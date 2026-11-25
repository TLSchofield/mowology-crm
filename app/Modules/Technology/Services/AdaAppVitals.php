<?php
/**
 * AdaAppVitals — Ada reads what the phones report (AppVitalsService) and puts new or growing problems on her desk (2026-11-15).
 *
 * Why: the crew apps' crashes, freezes and slow launches only reached us when someone exported a phone's log by hand.
 * Now: a new issue, or one hitting several phones, is a check the same day — and so is silence. A healthy install
 * still sends one daily_metrics row per phone per day, so no rows for SILENT_DAYS means the pipe is broken, not the app.
 * Pure (checksFrom) is unit tested; checks() only reads. Global namespace (require_once + new).
 */
declare(strict_types=1);

require_once __DIR__ . '/AdaRules.php';

class AdaAppVitals
{
    public const WINDOW_HOURS = 24;
    public const SILENT_DAYS = 3;
    public const RED_EVENTS = 10;       // one issue this often in the window → urgent
    public const RED_DEVICES = 2;       // a crash-type issue on this many phones → urgent
    public const MAX_CHECKS = 8;
    private const SERIOUS = ['crash', 'watchdog', 'unclean_exit'];

    private PDO $db;
    private string $now;

    public function __construct(PDO $db, ?string $now = null)
    {
        $this->db = $db;
        $this->now = $now ?? date('Y-m-d H:i:s');
    }

    public function checks(): array
    {
        $since = gmdate('Y-m-d H:i:s', strtotime($this->now . ' UTC') - self::WINDOW_HOURS * 3600);
        $st = $this->db->prepare(
            "SELECT i.signature, i.kind, i.platform, i.title, i.first_seen, i.first_build, i.last_build,
                    COUNT(e.id) AS n, COUNT(DISTINCT e.install_id) AS devices
               FROM app_vitals_issues i JOIN app_vitals_events e ON e.signature = i.signature
              WHERE i.status = 'open' AND e.received_at >= ?
              GROUP BY i.signature, i.kind, i.platform, i.title, i.first_seen, i.first_build, i.last_build
              ORDER BY n DESC");
        $st->execute([$since]);
        $issues = $st->fetchAll(PDO::FETCH_ASSOC);
        $last = $this->db->query("SELECT MAX(received_at) FROM app_vitals_events")->fetchColumn();
        return self::checksFrom($issues, $last ? (string)$last : null, $this->now);
    }

    /** One check per active issue (worst first), a "no reports" check when the pipe is silent, or one green check. */
    public static function checksFrom(array $issues, ?string $lastReceived, string $now): array
    {
        $out = [];
        $silentFor = $lastReceived === null ? null : (strtotime($now . ' UTC') - strtotime($lastReceived . ' UTC')) / 86400;
        if ($lastReceived === null || $silentFor >= self::SILENT_DAYS) {
            $out[] = self::base('vitals:silent') + ['name' => 'Phone reports', 'last_worked' => $lastReceived, 'state' => 'amber', 'problem' => 'silent',
                'evidence' => $lastReceived === null
                    ? 'No phone has reported yet. Until one does, a crash or freeze would not reach Ada.'
                    : 'No phone has reported for ' . (int)floor($silentFor) . ' days (last ' . substr($lastReceived, 0, 16) . '). A working install sends one summary per phone per day.',
                'handoff' => 'Ada: the apps send nothing to /crm/api/app-vitals.php. Check the endpoint answers 401 without a token, a phone is on a build with the uploader, and AppVitalsService is not failing (PHP error log).',
                'handoff_to' => 'developer'];
        }
        foreach (array_slice($issues, 0, self::MAX_CHECKS) as $i) {
            $n = (int)$i['n'];
            $devices = (int)$i['devices'];
            $serious = in_array($i['kind'], self::SERIOUS, true);
            $isNew = (string)$i['first_seen'] >= gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - self::WINDOW_HOURS * 3600);
            $red = $n >= self::RED_EVENTS || ($serious && $devices >= self::RED_DEVICES);
            $label = strtoupper(str_replace('_', ' ', (string)$i['kind']));
            $out[] = self::base('vitals:' . $i['signature']) + [
                'name' => ($isNew ? 'New: ' : '') . $label . ' on ' . $i['platform'],
                'last_worked' => null,
                'state' => $red ? 'red' : 'amber',
                'problem' => 'reported',
                'evidence' => $n . ' report' . ($n === 1 ? '' : 's') . ' from ' . $devices . ' phone' . ($devices === 1 ? '' : 's') . ' in the last ' . self::WINDOW_HOURS . ' hours'
                              . ' (first seen build ' . ($i['first_build'] ?: '?') . ', latest ' . ($i['last_build'] ?: '?') . '): ' . $i['title'],
                'handoff' => "Ada: {$label} on {$i['platform']} — {$n}× on {$devices} phone(s), first seen {$i['first_seen']} on build {$i['first_build']}. \"{$i['title']}\". Find the cause (issue {$i['signature']} in app_vitals_issues, events in app_vitals_events with breadcrumbs) and fix it in the next build.",
                'handoff_to' => 'developer',
            ];
        }
        if (!$out) {
            $out[] = self::base('vitals:ok') + ['name' => 'Phone reports', 'last_worked' => $lastReceived, 'state' => 'green', 'problem' => 'ok',
                'evidence' => 'Phones are reporting and nothing new crashed, froze or launched slowly in the last ' . self::WINDOW_HOURS . ' hours.', 'handoff' => '', 'handoff_to' => ''];
        }
        return $out;
    }

    private static function base(string $system): array
    {
        return ['system' => $system, 'group' => 'App health'];
    }
}
