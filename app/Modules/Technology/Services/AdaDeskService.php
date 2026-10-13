<?php
/**
 * AdaDeskService — Ada, head of Technology: what she reads. Read-only, rules not AI.
 *
 * Checks (AdaRules has the decisions, unit tested):
 *   (1) scheduled jobs — every job in the Database Manager's registry ($crons in
 *       public/crm/database_appstack.php, read as text) against cron_runs: failing, missed,
 *       recovered, slow, never ran, doesn't record; plus keys that run but aren't listed;
 *   (2) connections — Facebook / Instagram / Google Business (SocialAccountHealth's stored
 *       result), Search Console (gsc_sync_history), Apple push (push_queue), Stripe
 *       (stripe_payments), truck GPS (vehicle_location_pings);
 *   (3) security — active logins with no login and no phone seen for 90 days.
 * Each check fails on its own (logged, then shown as "can't tell") — one bad query never
 * blanks the page or Charlie's brief.
 *
 * brief() is Charlie's contract (CharlieBriefService). It runs every 15 minutes inside
 * charlie_morning_brief, so everything here is a handful of indexed queries and small reads.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/AdaRules.php';

class AdaDeskService
{
    private PDO $db;
    private ?string $now;
    private ?string $publicRoot;
    private ?array $checks = null;

    /** @param ?string $publicRoot the web root (public_html); default dirname(APP_ROOT) */
    public function __construct(PDO $db, ?string $now = null, ?string $publicRoot = null)
    {
        $this->db = $db;
        $this->now = $now;
        $this->publicRoot = $publicRoot ?? (defined('APP_ROOT') ? dirname(APP_ROOT) : null);
    }

    private function now(): string { return $this->now ?? date('Y-m-d H:i:s'); }

    /** Ada needs no table of her own. */
    public function ready(): bool { return true; }

    /** Charlie's contract. */
    public function brief(string $ownerFirstName = ''): array
    {
        $checks = $this->checks();
        $counts = AdaRules::counts($checks);
        $items = AdaRules::briefItems($checks, substr($this->now(), 0, 10));
        return [
            'head'     => AdaRules::HEAD,
            'headline' => AdaRules::headline($counts),
            'count'    => $counts['red'] + $counts['amber'],
            'items'    => $items,
        ];
    }

    /** Every check, worst first. */
    public function checks(): array
    {
        if ($this->checks !== null) return $this->checks;
        $all = [];
        foreach (['cronChecks', 'connectionChecks', 'securityChecks'] as $part) {
            try {
                $all = array_merge($all, $this->$part());
            } catch (Throwable $e) {
                error_log("Ada: {$part} failed: " . $e->getMessage());
                $all[] = ['system' => 'ada:' . $part, 'group' => 'Ada', 'name' => $part, 'last_worked' => null,
                          'state' => 'unknown', 'problem' => 'check-failed',
                          'evidence' => "Ada couldn't run this group of checks: " . AdaRules::shortError($e->getMessage()),
                          'handoff' => "Ada: AdaDeskService::{$part}() threw \"" . AdaRules::shortError($e->getMessage()) . '". Fix the check.',
                          'handoff_to' => 'developer'];
            }
        }
        return $this->checks = AdaRules::sortChecks(AdaRules::applyProxies($all));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (1) Scheduled jobs
    // ─────────────────────────────────────────────────────────────────────────

    private ?string $registrySrc = null;

    private function registrySource(): string
    {
        if ($this->registrySrc !== null) return $this->registrySrc;
        $file = $this->publicRoot ? $this->publicRoot . '/crm/database_appstack.php' : null;
        if (!$file || !is_file($file)) throw new RuntimeException('cron registry page not found');
        return $this->registrySrc = (string)file_get_contents($file);
    }

    public function registry(): array
    {
        return AdaRules::parseRegistry($this->registrySource());
    }

    private function cronChecks(): array
    {
        $now = $this->now();
        $reg = $this->registry();
        $base = AdaRules::parseCronBase($this->registrySource());

        $lastByKey = [];
        foreach ($this->db->query("SELECT cron_key, MAX(ran_at) AS last_at FROM cron_runs GROUP BY cron_key") as $r) {
            $lastByKey[(string)$r['cron_key']] = (string)$r['last_at'];
        }
        $maxMs = [];
        $st = $this->db->prepare("SELECT cron_key, MAX(duration_ms) AS m FROM cron_runs WHERE ran_at > ? GROUP BY cron_key");
        $st->execute([date('Y-m-d H:i:s', strtotime($now) - 14 * 86400)]);
        foreach ($st as $r) $maxMs[(string)$r['cron_key']] = (int)$r['m'];

        $recentSt = $this->db->prepare("SELECT status, ran_at, error_message, summary FROM cron_runs WHERE cron_key = ? ORDER BY ran_at DESC LIMIT 12");
        $okSt = $this->db->prepare("SELECT MAX(ran_at) FROM cron_runs WHERE cron_key = ? AND status <> 'error'");

        $out = [];
        foreach ($reg as $key => $row) {
            $files = array_values(array_unique(array_filter([$row['app'], $row['run']])));
            $exist = true;
            $records = false;
            foreach ($files as $f) {
                $p = $this->publicRoot . $f;
                if (!is_file($p)) { $exist = false; continue; }
                if (AdaRules::scriptRecords((string)file_get_contents($p))) $records = true;
            }
            $stats = null;
            if (isset($lastByKey[$key])) {
                $recentSt->execute([$key]);
                $recent = [];
                $lastOk = null;
                $first = null;
                foreach ($recentSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $first = $first ?? $r;
                    $recent[] = [(string)$r['status'], (string)$r['ran_at'], (string)($r['error_message'] ?: $r['summary'])];
                    if ($lastOk === null && $r['status'] !== 'error') $lastOk = (string)$r['ran_at'];
                }
                if ($lastOk === null) {
                    $okSt->execute([$key]);
                    $lastOk = $okSt->fetchColumn() ?: null;
                }
                $stats = [
                    'last_at'     => $lastByKey[$key],
                    'last_ok_at'  => $lastOk,
                    'last_status' => $first['status'] ?? null,
                    'last_error'  => $first ? (string)($first['error_message'] ?: $first['summary']) : null,
                    'max_ms'      => $maxMs[$key] ?? 0,
                    'recent'      => $recent,
                ];
            }
            $out[] = AdaRules::cronCheck($row, $stats, $records, $exist, $now, $base);
        }
        foreach (array_diff_key($lastByKey, $reg) as $key => $lastAt) {
            $out[] = AdaRules::unlistedCron((string)$key, $lastAt, $now);
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (2) Connections — each one on its own
    // ─────────────────────────────────────────────────────────────────────────

    private function connectionChecks(): array
    {
        $now = $this->now();
        $out = [];
        $one = function (string $system, string $name, callable $fn) use (&$out) {
            try {
                foreach ((array)$fn() as $c) $out[] = $c;
            } catch (Throwable $e) {
                error_log("Ada: {$system} check failed: " . $e->getMessage());
                $out[] = ['system' => $system, 'group' => 'Connections', 'name' => $name, 'last_worked' => null,
                          'state' => 'unknown', 'problem' => 'check-failed',
                          'evidence' => "{$name}: Ada couldn't read it (" . AdaRules::shortError($e->getMessage()) . ').',
                          'handoff' => null, 'handoff_to' => null];
            }
        };

        $one('social', 'Social accounts', function () use ($now) {
            $rows = $this->db->query("SELECT platform, meta_json FROM social_accounts WHERE is_active = 1 ORDER BY platform, id")->fetchAll(PDO::FETCH_ASSOC);
            $seen = [];
            $res = [];
            foreach ($rows as $r) {
                $p = (string)$r['platform'];
                if (isset($seen[$p])) continue;   // one per platform: the newest active connection
                $seen[$p] = true;
                $meta = json_decode((string)($r['meta_json'] ?: '{}'), true) ?: [];
                $res[] = AdaRules::socialCheck($p, is_array($meta['health'] ?? null) ? $meta['health'] : null, $now);
            }
            return $res;
        });

        $one('google:search-console', 'Google Search Console', function () use ($now) {
            $r = $this->db->query("SELECT * FROM gsc_sync_history ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            return [AdaRules::gscCheck($r ?: null, $now)];
        });

        $one('apple:push', 'Apple push notifications', function () use ($now) {
            $since = date('Y-m-d H:i:s', strtotime($now) - 48 * 3600);
            $st = $this->db->prepare("SELECT SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed FROM push_queue WHERE created_at > ?");
            $st->execute([$since]);
            $n = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $lastSent = $this->db->query("SELECT MAX(sent_at) FROM push_queue WHERE status = 'sent'")->fetchColumn() ?: null;
            $st = $this->db->prepare("SELECT error_message FROM push_queue WHERE status = 'failed' AND created_at > ? ORDER BY id DESC LIMIT 1");
            $st->execute([$since]);
            $err = $st->fetchColumn() ?: null;
            // Pending rows that should already have gone out: the drain is dead (audit 2026-10-12 #23).
            $st = $this->db->prepare("SELECT COUNT(*) AS n, MIN(scheduled_at) AS oldest FROM push_queue WHERE status = 'pending' AND scheduled_at < ?");
            $st->execute([date('Y-m-d H:i:s', strtotime($now) - AdaRules::PUSH_STUCK_MINUTES * 60)]);
            $stuck = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            return [AdaRules::pushCheck((int)($n['sent'] ?? 0), (int)($n['failed'] ?? 0), $lastSent, $err, $now,
                (int)($stuck['n'] ?? 0), $stuck['oldest'] ?? null)];
        });

        $one('stripe', 'Stripe payments', function () use ($now) {
            $st = $this->db->prepare("SELECT MAX(created_at) AS last_at, SUM(created_at > ?) AS n FROM stripe_payments");
            $st->execute([date('Y-m-d H:i:s', strtotime($now) - 30 * 86400)]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            return [AdaRules::stripeCheck($r['last_at'] ?? null, (int)($r['n'] ?? 0), $now)];
        });

        $one('trackimo', 'Truck GPS (Trackimo)', function () use ($now) {
            $last = $this->db->query("SELECT MAX(fetched_at) FROM vehicle_location_pings")->fetchColumn() ?: null;
            return [AdaRules::trackimoCheck($last, $now)];
        });

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (3) Security
    // ─────────────────────────────────────────────────────────────────────────

    private function securityChecks(): array
    {
        $rows = $this->db->query(
            "SELECT u.id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.full_name, u.username) AS name,
                    u.role, u.last_login, MAX(d.last_seen_at) AS seen
               FROM users u
               LEFT JOIN device_tracking_health d ON d.user_id = u.id
              WHERE u.is_active = 1 AND COALESCE(u.is_vehicle_tablet, 0) = 0
              GROUP BY u.id, name, u.role, u.last_login"
        )->fetchAll(PDO::FETCH_ASSOC);
        return [AdaRules::loginsCheck($rows, $this->now()),
                AdaRules::jwtSecretCheck(defined('BLUEMOON_JWT_SECRET') ? strlen((string)BLUEMOON_JWT_SECRET) : null)];
    }
}
