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
 *   (3) security — active logins with no login and no phone seen for 90 days, the JWT key, failed
 *       logins and sensitive changes (AdaSecurityWatch), and the nightly security-posture rows
 *       (SecurityPostureService::deskChecks(), cron security_posture) — all in the "Security" group;
 *   (4) server crashes — PHP fatal / parse errors in the server error log's last day, by file:line
 *       (AdaServerErrors, 2026-10-16: the Stripe webhook crashed on 189 payments and nothing said so).
 * Each check fails on its own (logged, then shown as "can't tell") — one bad query never
 * blanks the page or Charlie's brief.
 *
 *   (5) the register (2026-11-03, operations/finance plugins): every problem carries a cost-of-waiting
 *       rank and a 3×3 level; Tim may accept a non-Critical one for 90 days (AdaRiskRegister /
 *       AdaAcceptedRisks); who holds admin/manager is reviewed every 90 days; a nightly count snapshot
 *       gives the headline its trend; findings without a date are named.
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

    private ?AdaFixedMarks $marks = null;
    private ?AdaAcceptedRisks $risks = null;
    private ?array $accepted = null;
    private ?array $yesterday = null;

    public const TREND_KEY = 'ada_daily_counts';
    public const ACCESS_REVIEW_KEY = 'ada_access_review';

    public function risks(): AdaAcceptedRisks
    {
        if ($this->risks === null) {
            require_once __DIR__ . '/AdaAcceptedRisks.php';
            $this->risks = new AdaAcceptedRisks($this->db);
        }
        return $this->risks;
    }

    /** Accepted problems within their review date (shown apart on the page). checks() must run first. */
    public function accepted(): array
    {
        $this->checks();
        return $this->accepted ?? [];
    }

    private function setting(string $key): ?string
    {
        try {
            $st = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function putSetting(string $key, string $value, string $description): void
    {
        $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $value, $description]);
    }

    /** Yesterday's counts (status-report: what changed since last time), from the nightly snapshot. */
    public function yesterday(): ?array
    {
        if ($this->yesterday !== null) return $this->yesterday ?: null;
        $hist = json_decode((string)$this->setting(self::TREND_KEY), true);
        $this->yesterday = is_array($hist) ? (AdaRules::trendYesterday($hist, substr($this->now(), 0, 10)) ?? []) : [];
        return $this->yesterday ?: null;
    }

    /** Nightly (security_posture cron): remember today's counts and reds for the trend. */
    public function recordDailyCounts(): array
    {
        $checks = $this->checks();
        $counts = AdaRules::counts($checks);
        $reds = [];
        foreach ($checks as $c) if ($c['state'] === 'red') $reds[] = (string)$c['system'];
        $hist = json_decode((string)$this->setting(self::TREND_KEY), true);
        $hist = AdaRules::trendAppend(is_array($hist) ? $hist : [], substr($this->now(), 0, 10), $counts, $reds);
        $this->putSetting(self::TREND_KEY, (string)json_encode($hist), 'Ada: nightly red/amber/unknown/green counts, last ' . AdaRules::TREND_DAYS . ' days (trend in her headline)');
        return $counts;
    }

    /** Charlie's and the page's headline, with the trend against yesterday. */
    public function headline(): string
    {
        return AdaRules::headline(AdaRules::counts($this->checks()), $this->yesterday());
    }

    /** Tim pressed Reviewed on the admin/manager list: remember when, who and the roster fingerprint. */
    public function markAccessReviewed(?int $userId, string $userName): void
    {
        $hash = AdaRules::rosterHash($this->privilegedUsers(true));
        $this->putSetting(self::ACCESS_REVIEW_KEY, (string)json_encode(['at' => $this->now(), 'by' => mb_substr($userName, 0, 80), 'by_id' => $userId, 'hash' => $hash]),
            'Ada: last review of who can sign in as admin or manager (asked again every ' . AdaRules::ACCESS_REVIEW_DAYS . ' days or when the list changes)');
    }

    /** @return array<array{id:int,name:string,role:string,is_active:int,last_login:?string}> */
    public function privilegedUsers(bool $activeOnly = false): array
    {
        $rows = $this->db->query(
            "SELECT u.id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.full_name, u.username) AS name,
                    u.role, u.is_active, u.last_login, COALESCE(u.is_vehicle_tablet, 0) AS is_vehicle_tablet
               FROM users u WHERE u.role IN ('admin', 'manager')" . ($activeOnly ? ' AND u.is_active = 1' : '') . ' ORDER BY u.role, u.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter($rows, static fn($u) => (int)($u['is_active'] ?? 1) === 1 || !$activeOnly));
    }

    public function marks(): AdaFixedMarks
    {
        if ($this->marks === null) {
            require_once __DIR__ . '/AdaFixedMarks.php';
            $this->marks = new AdaFixedMarks($this->db);
        }
        return $this->marks;
    }

    /** Ada needs no table of her own (the "Mark fixed" history, migration 1420, is optional). */
    public function ready(): bool { return true; }

    /** Charlie's contract. */
    public function brief(string $ownerFirstName = ''): array
    {
        $checks = $this->checks();
        $counts = AdaRules::counts($checks);
        $items = AdaRules::briefItems($checks, substr($this->now(), 0, 10));
        return [
            'head'     => AdaRules::HEAD,
            'headline' => AdaRules::headline($counts, $this->yesterday()),
            'count'    => $counts['red'] + $counts['amber'],
            'items'    => $items,
        ];
    }

    /** Every check, worst first. */
    public function checks(): array
    {
        if ($this->checks !== null) return $this->checks;
        $all = [];
        foreach (['pulseChecks', 'cronChecks', 'connectionChecks', 'securityChecks', 'serverErrorChecks'] as $part) {
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
        $all = AdaRules::applyProxies($all);
        if ($row = AdaRules::evidenceCheck($all)) $all[] = $row;
        // The register: cost rank + level on every row; accepted risks set apart until their review date.
        require_once __DIR__ . '/AdaRiskRegister.php';
        $acceptances = [];
        try { $acceptances = $this->risks()->open(); } catch (Throwable $e) { error_log('Ada: accepted risks unreadable: ' . $e->getMessage()); }
        $reg = AdaRiskRegister::apply($all, $acceptances, substr($this->now(), 0, 10));
        foreach ($reg['released'] as $sys) {
            try { $this->risks()->release($sys, 'cleared', $this->now()); } catch (Throwable $e) { error_log('Ada: could not release ' . $sys . ': ' . $e->getMessage()); }
        }
        $this->accepted = AdaRules::sortChecks($reg['accepted']);
        return $this->checks = AdaRules::sortChecks($reg['active']);
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
        $st->execute([date('Y-m-d H:i:s', strtotime($now) - AdaRules::SLOW_DAYS * 86400)]);
        foreach ($st as $r) $maxMs[(string)$r['cron_key']] = (int)$r['m'];

        $recentSt = $this->db->prepare("SELECT status, ran_at, error_message, summary FROM cron_runs WHERE cron_key = ? ORDER BY ran_at DESC LIMIT 12");
        $okSt = $this->db->prepare("SELECT MAX(ran_at) FROM cron_runs WHERE cron_key = ? AND status <> 'error'");
        // Problems Tim marked fixed: those jobs are judged on runs after the mark only.
        $marks = $this->marks();
        $open = $marks->open();
        $sinceSt = $this->db->prepare("SELECT status, ran_at, error_message, summary, duration_ms FROM cron_runs WHERE cron_key = ? AND ran_at > ? ORDER BY ran_at DESC LIMIT 12");

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
            $fix = null;
            $mark = $open['cron:' . $key] ?? null;
            if ($mark !== null) {
                $sinceSt->execute([$key, $mark['fixed_at']]);
                $after = $sinceSt->fetchAll(PDO::FETCH_ASSOC);
                $fix = ['at' => $mark['fixed_at'], 'runs' => count($after)];
                if ($after && $stats !== null) {
                    $stats['recent'] = array_map(static fn($r) => [(string)$r['status'], (string)$r['ran_at'], (string)($r['error_message'] ?: $r['summary'])], $after);
                    $stats['last_status'] = (string)$after[0]['status'];
                    $stats['last_error'] = (string)($after[0]['error_message'] ?: $after[0]['summary']);
                    $stats['max_ms'] = (int)max(array_map(static fn($r) => (int)$r['duration_ms'], $after));
                    if ($stats['last_status'] !== 'error') $stats['last_ok_at'] = (string)$after[0]['ran_at'];
                }
            }
            if ($stats !== null) {
                $stats['runs'] = count($stats['recent']);
                $stats['since'] = $stats['recent'] ? (string)end($stats['recent'])[1] : null;
            }
            $check = AdaRules::cronCheck($row, $stats, $records, $exist, $now, $base, $fix);
            $check['basis'] = AdaRules::cronBasis($stats, $fix, $now);
            $check['fixable'] = in_array($check['state'], ['red', 'amber'], true);
            if ($mark !== null && $fix !== null && $fix['runs'] > 0) {
                // A run has landed since the mark: write down how it turned out.
                try {
                    if ($check['state'] === 'green') $marks->close($mark['id'], 'cleared', $check['evidence'], $now);
                    elseif ($check['state'] !== 'info') $marks->close($mark['id'], 'came_back', $check['evidence'], $now);
                } catch (Throwable $e) { error_log('Ada: could not record outcome: ' . $e->getMessage()); }
            }
            $out[] = $check;
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

    /** Pulse's live checks (is the app working right now?): one row each, from the 5-minute check. */
    private function pulseChecks(): array
    {
        require_once __DIR__ . '/PulseService.php';
        return (new PulseService($this->db))->adaChecks();
    }

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
        $out = [AdaRules::loginsCheck($rows, $this->now()),
                AdaRules::jwtSecretCheck(defined('BLUEMOON_JWT_SECRET') ? strlen((string)BLUEMOON_JWT_SECRET) : null)];
        // Who can sign in as admin or manager, reviewed every 90 days (audit-support: access recertification).
        try {
            $last = json_decode((string)$this->setting(self::ACCESS_REVIEW_KEY), true);
            $out[] = AdaRules::accessReviewCheck($this->privilegedUsers(false), is_array($last) ? $last : null, $this->now());
        } catch (Throwable $e) {
            error_log('Ada: access review check failed: ' . $e->getMessage());
        }
        // Failed-login spikes and role / password / migration / log-clear changes (Fable audit #25d).
        require_once __DIR__ . '/AdaSecurityWatch.php';
        foreach ((new AdaSecurityWatch($this->db, $this->now))->checks() as $c) $out[] = $c;
        // The nightly security-posture checks (cron security_posture): stored rows, read back cheaply.
        require_once __DIR__ . '/SecurityPostureService.php';
        foreach ((new SecurityPostureService($this->db, $this->now))->deskChecks() as $c) $out[] = $c;
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (4) Server crashes
    // ─────────────────────────────────────────────────────────────────────────

    private function serverErrorChecks(): array
    {
        require_once __DIR__ . '/AdaServerErrors.php';
        return (new AdaServerErrors(null, $this->now()))->checks();
    }
}
