<?php
/**
 * AppVitalsService — takes the batches the iPhone and Android apps send and keeps them as events and issues (2026-11-15).
 *
 * Why: a phone's diagnostics log never left the phone, so a crash or a 12 s freeze was only known if someone exported
 * the log by hand. Both apps now post the same batch; the server groups events by signature (same cause, same top frame)
 * so 40 phones hitting one bug is one issue with a count, and Ada can compare builds.
 *
 * Wire format (both platforms; the server does not care which sent it):
 *   {device:{platform, model, os, app_version, build, install_id},
 *    events:[{id, at (ISO UTC), kind, severity?, message, signature_parts?:{cause, frame, binary_uuid?}, breadcrumbs?:[..]}]}
 * Never stored: request bodies, tokens, anything beyond MAX_* below. Rows over the limits are cut, not refused,
 * except a batch over MAX_EVENTS (only the first MAX_EVENTS count) and a device over DAILY_CAP (rest rejected).
 *
 * Pure parts (clean, signature) are unit tested; ingest/purge take a PDO. Global namespace (require_once + new).
 */
declare(strict_types=1);

class AppVitalsService
{
    public const KINDS = ['crash', 'cpu_exception', 'hang', 'slow_launch', 'watchdog', 'unclean_exit', 'exit_summary',
                          'daily_metrics', 'net_errors', 'user_report', 'disk_writes', 'stall_end'];
    /** Kinds that become issues; the rest are numbers to compare, not problems. */
    public const ISSUE_KINDS = ['crash', 'cpu_exception', 'hang', 'slow_launch', 'watchdog', 'unclean_exit', 'user_report', 'disk_writes'];
    public const MAX_EVENTS = 50;
    public const MAX_MESSAGE = 600;
    public const MAX_CRUMBS = 30;
    public const MAX_CRUMB = 160;
    public const DAILY_CAP = 200;       // per install per day, so a crash loop cannot flood the table
    public const KEEP_DAYS = 90;

    private PDO $db;

    public function __construct(PDO $db) { $this->db = $db; }

    /** Validated batch → ['device'=>[...], 'events'=>[...]] or null when there is nothing usable. */
    public static function clean(array $payload): ?array
    {
        $d = is_array($payload['device'] ?? null) ? $payload['device'] : [];
        $platform = strtolower((string)($d['platform'] ?? ''));
        $install = self::cut((string)($d['install_id'] ?? ''), 64);
        if (!in_array($platform, ['ios', 'android'], true) || $install === '') return null;
        $device = ['platform' => $platform, 'install_id' => $install,
                   'model' => self::cut((string)($d['model'] ?? ''), 64), 'os' => self::cut((string)($d['os'] ?? ''), 64),
                   'app_version' => self::cut((string)($d['app_version'] ?? ''), 24), 'build' => self::cut((string)($d['build'] ?? ''), 24)];
        $events = [];
        foreach (array_slice(is_array($payload['events'] ?? null) ? $payload['events'] : [], 0, self::MAX_EVENTS) as $e) {
            if (!is_array($e)) continue;
            $id = self::cut((string)($e['id'] ?? ''), 64);
            $kind = (string)($e['kind'] ?? '');
            $at = strtotime((string)($e['at'] ?? ''));
            if ($id === '' || !in_array($kind, self::KINDS, true) || $at === false) continue;
            $sev = in_array(($e['severity'] ?? ''), ['info', 'warn', 'error'], true) ? $e['severity'] : 'info';
            $crumbs = [];
            foreach (array_slice(is_array($e['breadcrumbs'] ?? null) ? $e['breadcrumbs'] : [], -self::MAX_CRUMBS) as $c) {
                if (is_string($c)) $crumbs[] = self::cut($c, self::MAX_CRUMB);
            }
            $parts = is_array($e['signature_parts'] ?? null) ? $e['signature_parts'] : [];
            $events[] = ['id' => $id, 'kind' => $kind, 'severity' => $sev, 'at' => gmdate('Y-m-d H:i:s', $at),
                         'message' => self::cut((string)($e['message'] ?? ''), self::MAX_MESSAGE),
                         'signature' => in_array($kind, self::ISSUE_KINDS, true) ? self::signature($kind, $parts, (string)($e['message'] ?? '')) : null,
                         'breadcrumbs' => $crumbs];
        }
        return $events ? ['device' => $device, 'events' => $events] : null;
    }

    /**
     * Same cause + same top frame + same binary → same signature, across devices and builds of one binary.
     * Without signature parts the message stands in, with digits dropped so "took 2.1 s" and "took 3.4 s" group.
     */
    public static function signature(string $kind, array $parts, string $message = ''): string
    {
        $cause = trim((string)($parts['cause'] ?? ''));
        $frame = trim((string)($parts['frame'] ?? ''));
        if ($cause === '' && $frame === '') $cause = (string)preg_replace('/[0-9]+/', '#', mb_substr($message, 0, 120));
        return sha1(strtolower($kind . '|' . $cause . '|' . $frame . '|' . trim((string)($parts['binary_uuid'] ?? ''))));
    }

    /** @return array{accepted:int, duplicate:int, rejected:int} */
    public function ingest(string $business, int $userId, array $payload, string $now): array
    {
        $out = ['accepted' => 0, 'duplicate' => 0, 'rejected' => 0];
        $batch = self::clean($payload);
        if (!$batch) { $out['rejected'] = count(is_array($payload['events'] ?? null) ? $payload['events'] : []); return $out; }
        $dev = $batch['device'];
        $day = substr($now, 0, 10);
        $today = $this->db->prepare("SELECT COUNT(*) FROM app_vitals_events WHERE install_id = ? AND received_at >= ?");
        $today->execute([$dev['install_id'], $day . ' 00:00:00']);
        $room = max(0, self::DAILY_CAP - (int)$today->fetchColumn());
        $exists = $this->db->prepare("SELECT 1 FROM app_vitals_events WHERE event_id = ?");
        $ins = $this->db->prepare("INSERT INTO app_vitals_events (event_id, business, user_id, platform, install_id, app_version, build, device, os, kind, severity, signature, message, breadcrumbs, occurred_at, received_at)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($batch['events'] as $e) {
            $exists->execute([$e['id']]);
            if ($exists->fetchColumn()) { $out['duplicate']++; continue; }
            if ($room <= 0) { $out['rejected']++; continue; }
            $ins->execute([$e['id'], self::cut($business, 64), $userId, $dev['platform'], $dev['install_id'], $dev['app_version'], $dev['build'],
                           $dev['model'], $dev['os'], $e['kind'], $e['severity'], $e['signature'], $e['message'],
                           $e['breadcrumbs'] ? json_encode($e['breadcrumbs'], JSON_UNESCAPED_UNICODE) : null, $e['at'], $now]);
            $room--;
            $out['accepted']++;
            if ($e['signature'] !== null) $this->touchIssue($e, $dev, $now);
        }
        return $out;
    }

    private function touchIssue(array $e, array $dev, string $now): void
    {
        $sel = $this->db->prepare("SELECT 1 FROM app_vitals_issues WHERE signature = ?");
        $sel->execute([$e['signature']]);
        if ($sel->fetchColumn()) {
            $this->db->prepare("UPDATE app_vitals_issues SET event_count = event_count + 1, last_seen = ?, last_build = ? WHERE signature = ?")
                     ->execute([$now, $dev['build'], $e['signature']]);
            return;
        }
        $this->db->prepare("INSERT INTO app_vitals_issues (signature, kind, platform, title, first_seen, last_seen, first_build, last_build, event_count)
                            VALUES (?,?,?,?,?,?,?,?,1)")
                 ->execute([$e['signature'], $e['kind'], $dev['platform'], self::cut($e['message'], 255), $now, $now, $dev['build'], $dev['build']]);
    }

    /** Drops events older than KEEP_DAYS; issues stay (they are the history). Returns rows removed. */
    public function purge(string $now): int
    {
        $cut = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - self::KEEP_DAYS * 86400);
        $st = $this->db->prepare("DELETE FROM app_vitals_events WHERE received_at < ?");
        $st->execute([$cut]);
        return $st->rowCount();
    }

    private static function cut(string $s, int $max): string
    {
        $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '');
        return mb_substr($s, 0, $max);
    }
}
