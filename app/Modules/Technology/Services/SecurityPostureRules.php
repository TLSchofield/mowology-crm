<?php
/**
 * SecurityPostureRules — the pure decisions behind Ada's nightly security-posture checks
 * (cron security_posture, 2026-10-13; follow-up to the Fable security audit of 2026-10-12).
 * No database, no network: SecurityPostureService gathers the facts and stores the verdicts.
 *
 * Ada CHECKS and reports. Nothing here changes a setting, a credential, a cron or Stripe.
 * Every check is in the desk's "Security" group and has the AdaRules shape:
 *   ['system', 'group', 'name', 'last_worked', 'state', 'problem', 'evidence', 'handoff', 'handoff_to'].
 * A NEW red goes to Tim once through Charlie's urgent email (urgentAlerts(), keyed by the time the
 * check turned red — a red that stays red is not repeated; one that clears and comes back is new).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/AdaRules.php';

class SecurityPostureRules
{
    public const GROUP = 'Security';

    /** (1) Public URLs that must answer 403/404 (fetched over HTTPS from the server itself). */
    public const BLOCKED_PATHS = [
        '/crm/tmp/', '/crm/tmp/jobber1.csv', '/crm/backup/', '/app/', '/app/Storage/', '/storage/',
        '/vendor/', '/sessions/', '/app_config/secrets.php', '/composer.lock', '/composer.json',
        '/php.ini', '/.user.ini', '/database/migrations/', '/crm/email-logs/',
        '/LANDING_PAGE_MARKETING_INTEGRATION.md',                          // an *.md sample that exists
        '/database/migrations/1340_payment_mail_sender_verified.sql',      // an *.sql sample that exists
    ];

    /** (2) Data-file extensions that should never sit in the web root outside uploads/ and _media/. */
    public const STRAY_EXTENSIONS = ['csv', 'zip', 'gz', 'tgz', 'tar', 'xlsx', 'sql', 'bak', 'dump'];
    public const STRAY_SKIP_DIRS = ['uploads', '_media', 'vendor', 'node_modules', '.git'];
    public const STRAY_LIST_MAX = 20;

    /** (3) Privileges that let the app login change tables. */
    public const DDL_PRIVILEGES = ['ALL', 'ALL PRIVILEGES', 'CREATE', 'DROP', 'ALTER'];

    /** (4) Stripe events the webhook must receive. */
    public const STRIPE_WEBHOOK_PATH = '/api/stripe/webhook';
    public const STRIPE_EVENTS = ['charge.dispute.created', 'charge.dispute.closed', 'charge.refunded',
                                  'payment_intent.succeeded', 'payment_intent.payment_failed'];

    /** (6) / (9) heartbeat ages. */
    public const BACKUP_MAX_HOURS = 36;
    public const COMPOSER_MAX_DAYS = 14;
    public const HEARTBEAT_KINDS = ['db', 'files', 'composer'];

    /** (7) audit copy must grow at least this often. */
    public const AUDIT_MAX_HOURS = 36;

    /** (8) certificate days left. */
    public const CERT_AMBER_DAYS = 21;
    public const CERT_RED_DAYS = 7;

    /** (11) drift on these paths is red (auth and money). */
    public const DRIFT_RED_PATTERN = '#^(app/Core/|app/Modules/Accounting/|public/api/)#';
    public const LIST_MAX = 8;

    /** (12) logged-out pages that must render to </html>; 'any' = a friendly error page with any non-5xx status. */
    public const RENDER_PAGES = [
        '/'                          => 200,
        '/services'                  => 200,
        '/about'                     => 200,
        '/contact'                   => 200,
        '/privacy-request'           => 200,
        '/loginAuth/login.php'       => 200,
        '/forgot-password.php'       => 200,
        '/customer/invoice.php?token=ada-posture-check-not-a-token' => 'any',
    ];

    /** (14) PHP error lines in 24 h. */
    public const ERRORS_AMBER = 50;
    public const ERRORS_RED = 500;
    public const ERRORS_TOP = 5;

    /** The posture run itself: older than this and the desk says so. */
    public const STALE_HOURS = 36;

    // ─────────────────────────────────────────────────────────────────────────

    private static function base(string $key, string $name, ?string $lastWorked = null): array
    {
        return ['system' => 'posture:' . $key, 'group' => self::GROUP, 'name' => $name, 'last_worked' => $lastWorked];
    }

    private static function v(string $state, string $problem, string $evidence, ?string $handoff = null, ?string $to = null): array
    {
        return ['state' => $state, 'problem' => $problem, 'evidence' => $evidence, 'handoff' => $handoff, 'handoff_to' => $to];
    }

    /** "a, b, c and 4 more" */
    public static function listed(array $items, int $max = self::LIST_MAX): string
    {
        $items = array_values($items);
        $more = count($items) - $max;
        $s = implode(', ', array_slice($items, 0, $max));
        return $more > 0 ? $s . " and {$more} more" : $s;
    }

    private static function hoursBetween(string $from, string $to): float
    {
        return (strtotime($to) - strtotime($from)) / 3600;
    }

    // (1) Blocked paths ──────────────────────────────────────────────────────

    /** @param array<string, int> $codes path => HTTP status (0 = the fetch failed) */
    public static function blockedPathsCheck(array $codes): array
    {
        $b = self::base('blocked-paths', 'Private files blocked from the web');
        $open = $odd = $failed = [];
        foreach ($codes as $path => $code) {
            $code = (int)$code;
            if ($code >= 200 && $code < 300) $open[] = $path;
            elseif ($code === 0) $failed[] = $path;
            elseif (!in_array($code, [401, 403, 404, 410], true)) $odd[] = "{$path} ({$code})";
        }
        if ($open) {
            return $b + self::v('red', 'exposed',
                'Downloadable with no login (HTTP 200): ' . self::listed($open) . '.',
                'Block these in .htaccess (deny the folder or the extension) and delete any file that should not be on the server; then check cPanel → Raw Access for downloads.',
                'developer');
        }
        if ($failed && count($failed) === count($codes)) {
            return $b + self::v('unknown', 'check-failed', 'The server could not fetch its own pages over HTTPS, so Ada cannot tell.');
        }
        if ($odd) {
            return $b + self::v('amber', 'unexpected', 'Expected 403/404 but got: ' . self::listed($odd) . '.',
                'Open each URL logged out and confirm it does not serve the file.', 'developer');
        }
        return $b + self::v('green', 'ok', count($codes) - count($failed) . ' private paths answer 403/404'
            . ($failed ? ' (' . count($failed) . ' could not be fetched)' : '') . '.');
    }

    // (2) Stray data files ───────────────────────────────────────────────────

    /** Is this web-root-relative path a stray data file? (skips uploads/, _media/, vendor/ anywhere in the path) */
    public static function isStray(string $relPath): bool
    {
        $relPath = trim($relPath, '/');
        if (strpos($relPath, 'database/migrations/') === 0) return false;   // expected; denied by .htaccess (check 1)
        $parts = explode('/', $relPath);
        foreach (array_slice($parts, 0, -1) as $dir) {
            if (in_array($dir, self::STRAY_SKIP_DIRS, true)) return false;
        }
        $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
        return in_array($ext, self::STRAY_EXTENSIONS, true);
    }

    public static function strayFilesCheck(array $paths): array
    {
        $b = self::base('stray-files', 'Data files in the web root');
        if (!$paths) return $b + self::v('green', 'ok', 'No ' . implode('/', self::STRAY_EXTENSIONS) . ' files in the web root outside uploads/ and _media/.');
        sort($paths);
        return $b + self::v('amber', 'stray', count($paths) . ' data file(s) in the web root: '
            . self::listed($paths, self::STRAY_LIST_MAX) . '.',
            'Move anything still needed out of public_html (or into app/Storage, which is denied) and delete the rest. Database migrations under database/migrations are expected if that folder is denied (see the blocked-paths check).',
            'tim');
    }

    // (3) Database privileges ────────────────────────────────────────────────

    /** The privileges the grant lines give on $dbName (or on every database). */
    public static function grantedPrivileges(array $grants, string $dbName): array
    {
        $out = [];
        foreach ($grants as $g) {
            if (!preg_match('/^GRANT\s+(.+?)\s+ON\s+(\S+)\s+TO\s/i', trim((string)$g), $m)) continue;
            $on = str_replace(['`', '\\'], '', $m[2]);
            if ($on !== '*.*' && $on !== $dbName . '.*') continue;
            foreach (explode(',', $m[1]) as $p) {
                $p = strtoupper(trim(preg_replace('/\(.*\)/', '', $p)));
                if ($p !== '' && $p !== 'USAGE') $out[$p] = true;
            }
        }
        return array_keys($out);
    }

    public static function dbPrivilegesCheck(array $grants, string $dbName): array
    {
        $b = self::base('db-privileges', 'Database login rights');
        $privs = self::grantedPrivileges($grants, $dbName);
        if (!$privs) return $b + self::v('unknown', 'no-grants', 'SHOW GRANTS returned nothing Ada could read for this database.');
        $ddl = array_values(array_intersect($privs, self::DDL_PRIVILEGES));
        if ($ddl) {
            return $b + self::v('red', 'ddl', 'app login can change tables — finish step 3 (data-only user). It holds: ' . implode(', ', $ddl) . '.',
                'In cPanel → MySQL Databases, create a data-only user (SELECT, INSERT, UPDATE, DELETE, LOCK TABLES, EXECUTE, CREATE TEMPORARY TABLES), switch DB_USER/DB_PASS in secrets.php to it and keep the current login as DB_DDL_* for migrations.',
                'tim');
        }
        return $b + self::v('green', 'ok', 'The app login holds data privileges only (' . implode(', ', $privs) . ').');
    }

    // (4) Stripe webhook ─────────────────────────────────────────────────────

    /**
     * @param ?array $endpoints Stripe's webhook_endpoints "data" list, or null when not fetched
     * @param bool $hasKey STRIPE_SECRET_KEY is set
     */
    public static function stripeWebhookCheck(?array $endpoints, bool $hasKey, ?string $error = null): array
    {
        $b = self::base('stripe-webhook', 'Stripe webhook events');
        if (!$hasKey) return $b + self::v('unknown', 'no-key', 'No Stripe secret key on this server — skipped.');
        if ($endpoints === null) return $b + self::v('unknown', 'check-failed', 'Could not list Stripe webhooks: ' . AdaRules::shortError((string)$error) . '.');
        $mine = array_values(array_filter($endpoints, fn($e) => strpos((string)($e['url'] ?? ''), self::STRIPE_WEBHOOK_PATH) !== false));
        if (!$mine) {
            return $b + self::v('amber', 'no-endpoint', 'No Stripe webhook points at ' . self::STRIPE_WEBHOOK_PATH . ' (' . count($endpoints) . ' endpoint(s) in all).',
                'In Stripe → Developers → Webhooks, add https://mowology.ca' . self::STRIPE_WEBHOOK_PATH . ' with the events ' . implode(', ', self::STRIPE_EVENTS) . '.', 'tim');
        }
        $best = null;
        foreach ($mine as $e) {
            $events = (array)($e['enabled_events'] ?? []);
            $missing = in_array('*', $events, true) ? [] : array_values(array_diff(self::STRIPE_EVENTS, $events));
            $enabled = ($e['status'] ?? 'enabled') === 'enabled';
            $score = ($enabled ? 0 : 100) + count($missing);
            if ($best === null || $score < $best['score']) $best = ['score' => $score, 'missing' => $missing, 'enabled' => $enabled];
        }
        if (!$best['enabled']) {
            return $b + self::v('red', 'disabled', 'The Stripe webhook for ' . self::STRIPE_WEBHOOK_PATH . ' is disabled — payments, refunds and disputes are not reaching the books.',
                'Re-enable it in Stripe → Developers → Webhooks.', 'tim');
        }
        if ($best['missing']) {
            return $b + self::v('amber', 'missing-events', 'The Stripe webhook does not send: ' . implode(', ', $best['missing']) . '.',
                'In Stripe → Developers → Webhooks → the ' . self::STRIPE_WEBHOOK_PATH . ' endpoint → Add events: ' . implode(', ', $best['missing']) . '.', 'tim');
        }
        return $b + self::v('green', 'ok', 'The Stripe webhook is enabled and sends all ' . count(self::STRIPE_EVENTS) . ' payment, refund and dispute events.');
    }

    // (5) Payment-email signatures ───────────────────────────────────────────

    /** @param array<string, array{held:int, verified:int}> $counts keyed 'Yardi' / 'Interac', last 7 days */
    public static function paymentMailCheck(array $counts): array
    {
        $b = self::base('payment-mail', 'Payment email signatures');
        $parts = $bad = [];
        foreach ($counts as $src => $c) {
            $held = (int)$c['held'];
            $ok = (int)$c['verified'];
            $parts[] = "{$src}: {$ok} verified, {$held} held";
            if ($held > 0 && $ok === 0) $bad[] = $src;
        }
        $line = implode('; ', $parts) . ' (last 7 days).';
        if ($bad) {
            return $b + self::v('red', 'all-held', 'real Yardi/Interac mail may be failing the signature check — ' . $line,
                'Open one held ' . implode('/', $bad) . ' email on the Pending e-Transfers panel: if it is genuine, its DKIM check (MailAuth) is rejecting real mail — a developer must look at the raw headers.',
                'developer');
        }
        return $b + self::v('green', 'ok', $line);
    }

    // (6) / (9) heartbeats ───────────────────────────────────────────────────

    /** @param ?array $lastOk newest ok heartbeat of the kind {finished_at, bytes}; $lastAny newest of any result */
    public static function backupCheck(string $kind, ?array $lastOk, ?array $lastAny, string $now): array
    {
        $isDb = $kind === 'db';
        $b = self::base('backup-' . $kind, $isDb ? 'Database backup (Mac)' : 'File backup (Mac)', $lastOk['finished_at'] ?? null);
        $fresh = $lastOk && self::hoursBetween((string)$lastOk['finished_at'], $now) <= self::BACKUP_MAX_HOURS;
        if ($fresh) {
            return $b + self::v('green', 'ok', ($isDb ? 'Database' : 'File') . ' backup finished ' . AdaRules::ago((string)$lastOk['finished_at'], $now)
                . (isset($lastOk['bytes']) && $lastOk['bytes'] !== null ? ' (' . self::bytes((int)$lastOk['bytes']) . ')' : '') . '.');
        }
        $why = $lastOk ? 'last good one ' . AdaRules::ago((string)$lastOk['finished_at'], $now) : 'none ever reported';
        if ($lastAny && !(int)$lastAny['ok']) $why .= '; the latest run (' . AdaRules::ago((string)$lastAny['finished_at'], $now) . ') FAILED';
        return $b + self::v($isDb ? 'red' : 'amber', $lastOk ? 'stale' : 'never',
            'No successful ' . ($isDb ? 'database' : 'file') . ' backup in ' . self::BACKUP_MAX_HOURS . ' h — ' . $why . '.',
            'On the iMac: is it awake at 02:30? Check ~/Backups/mowology/backup.log and `launchctl print gui/$(id -u)/ca.mowology.backup`; run scripts/backup-nightly.sh by hand.',
            'tim');
    }

    public static function composerCheck(?array $last, string $now): array
    {
        $b = self::base('dependencies', 'PHP library advisories', $last['finished_at'] ?? null);
        if (!$last) {
            return $b + self::v('amber', 'never', 'No composer audit report has reached the server yet.',
                'Run scripts/composer-audit-report.sh on the iMac (the nightly backup job runs it too).', 'tim');
        }
        $days = self::hoursBetween((string)$last['finished_at'], $now) / 24;
        $n = (int)($last['advisories'] ?? 0);
        $detail = trim((string)($last['detail'] ?? ''));
        if ($n > 0) {
            return $b + self::v('amber', 'advisories', "composer audit: {$n} advisory(ies)" . ($detail !== '' ? " — {$detail}" : '') . ' (' . AdaRules::ago((string)$last['finished_at'], $now) . ').',
                'Upgrade the named packages (composer update <package>), run phpunit, deploy vendor/.', 'developer');
        }
        if ($days > self::COMPOSER_MAX_DAYS) {
            return $b + self::v('amber', 'stale', 'The last composer audit report is ' . AdaRules::ago((string)$last['finished_at'], $now) . ' — older than ' . self::COMPOSER_MAX_DAYS . ' days.',
                'Run scripts/composer-audit-report.sh on the iMac.', 'tim');
        }
        return $b + self::v('green', 'ok', 'composer audit: no advisories (' . AdaRules::ago((string)$last['finished_at'], $now) . ').');
    }

    // (7) Audit copy ─────────────────────────────────────────────────────────

    /**
     * @param ?string $path the copy found (null = none)
     * @param ?int $mtime its modification time; $chainBreak = 0 intact, N = first broken line of the tail, -1 unreadable
     */
    public static function auditCopyCheck(?string $path, ?int $mtime, ?int $size, int $chainBreak, string $now): array
    {
        $b = self::base('audit-copy', 'Audit log copy', $mtime ? date('Y-m-d H:i:s', $mtime) : null);
        $hand = 'Check the audit_export job on the Database Manager (Run it) and its cron_runs; the copy lives at /home/mowology/audit/activity_log.jsonl.';
        if ($path === null) return $b + self::v('red', 'missing', 'No audit copy found (activity_log.jsonl) — the append-only audit trail is not being written.', $hand, 'developer');
        if ($chainBreak !== 0) {
            return $b + self::v('red', 'chain-broken', $chainBreak < 0 ? 'The audit copy could not be read back to verify.'
                : "The audit copy's hash chain breaks at line {$chainBreak} of its tail — a line was edited or removed.",
                'Treat as tampering until proven otherwise: keep a copy of the file, compare it with activity_log, tell Tim.', 'tim');
        }
        $hours = self::hoursBetween(date('Y-m-d H:i:s', (int)$mtime), $now);
        if ($hours > self::AUDIT_MAX_HOURS) {
            return $b + self::v('red', 'stale', 'The audit copy has not grown for ' . (int)round($hours) . ' h (last write ' . AdaRules::ago(date('Y-m-d H:i:s', (int)$mtime), $now) . ').', $hand, 'developer');
        }
        return $b + self::v('green', 'ok', 'Audit copy growing (' . self::bytes((int)$size) . ', last write ' . AdaRules::ago(date('Y-m-d H:i:s', (int)$mtime), $now) . '); its last lines verify.');
    }

    /**
     * Verify the last lines of the hash-chained copy (AuditExportService::line format): every line's
     * hash recomputes and points at the line before it. Returns 0 when intact, else the 1-based
     * index (within $lines) of the first bad line. The first line's own "prev" is trusted.
     */
    public static function verifyAuditTail(array $lines): int
    {
        $prev = null;
        $n = 0;
        foreach ($lines as $line) {
            $line = rtrim((string)$line, "\r\n");
            if ($line === '') continue;
            $n++;
            $j = json_decode($line, true);
            if (!is_array($j) || !isset($j['prev'], $j['h'], $j['row'])) return $n;
            if ($prev !== null && $j['prev'] !== $prev) return $n;
            $body = json_encode($j['row'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (hash('sha256', $j['prev'] . $body) !== $j['h']) return $n;
            $prev = (string)$j['h'];
        }
        return $n === 0 ? -1 : 0;
    }

    // (8) Certificate ────────────────────────────────────────────────────────

    public static function certCheck(?int $validTo, ?string $error, string $now, string $host = 'mowology.ca'): array
    {
        $b = self::base('certificate', 'Website certificate');
        if ($validTo === null) return $b + self::v('unknown', 'check-failed', "Could not read {$host}'s certificate: " . AdaRules::shortError((string)$error) . '.');
        $days = (int)floor(($validTo - strtotime($now)) / 86400);
        $ev = "{$host} certificate expires " . date('M j, Y', $validTo) . " ({$days} days).";
        $hand = 'cPanel → SSL/TLS Status → Run AutoSSL; if it fails, ask Canadian Web Hosting support.';
        if ($days < self::CERT_RED_DAYS) return $b + self::v('red', 'expiring', $ev, $hand, 'tim');
        if ($days < self::CERT_AMBER_DAYS) return $b + self::v('amber', 'expiring', $ev, $hand, 'tim');
        return $b + self::v('green', 'ok', $ev);
    }

    // (11) Drift ─────────────────────────────────────────────────────────────

    /**
     * @param ?array $manifest deploy-manifest.json decoded (null = none yet)
     * @param array<string, string> $changed repo path => 'changed'|'missing'
     */
    public static function driftCheck(?array $manifest, array $changed): array
    {
        $b = self::base('drift', 'Live files vs last deploy', $manifest['updated_at'] ?? null);
        if (!$manifest || empty($manifest['files'])) {
            return $b + self::v('unknown', 'no-manifest', 'No deploy manifest on the server yet — the next scripts/deploy-merged.py (or deploy-checked.sh) writes one.');
        }
        $n = count($manifest['files']);
        if (!$changed) return $b + self::v('green', 'ok', "{$n} deployed files match what was deployed (manifest " . substr((string)($manifest['updated_at'] ?? ''), 0, 16) . ').');
        $red = array_values(array_filter(array_keys($changed), fn($p) => preg_match(self::DRIFT_RED_PATTERN, $p)));
        $items = [];
        foreach ($changed as $p => $how) $items[] = $p . ($how === 'missing' ? ' (deleted)' : '');
        return $b + self::v($red ? 'red' : 'amber', $red ? 'drift-sensitive' : 'drift',
            count($changed) . ' file(s) changed on the server since they were deployed: ' . self::listed($items) . '.'
                . ($red ? ' Auth/money code among them.' : ''),
            'Someone edited live (or deployed without the manifest). Download each with scripts/prod-file.sh, diff against the repo, commit what is wanted, redeploy; if nobody did it, treat as an intrusion.',
            $red ? 'tim' : 'developer');
    }

    // (12) Page render ───────────────────────────────────────────────────────

    /** @param array<string, array{code:int, html_end:bool, bytes:int, error:?string}> $results */
    public static function pageRenderCheck(array $results): array
    {
        $b = self::base('page-render', 'Public pages render fully');
        $bad = $failed = [];
        foreach ($results as $path => $r) {
            $want = self::RENDER_PAGES[$path] ?? 200;
            $code = (int)$r['code'];
            if ($code === 0) { $failed[] = $path; continue; }
            $statusOk = $want === 'any' ? $code < 500 : $code === $want;
            if (!$statusOk) $bad[] = "{$path} (HTTP {$code})";
            elseif (!$r['html_end']) $bad[] = "{$path} (cut off after " . self::bytes((int)$r['bytes']) . ')';
        }
        if ($bad) {
            return $b + self::v('red', 'broken', 'Not rendering to </html>: ' . self::listed($bad) . ' — a PHP fatal shows as a cut-off page with HTTP 200.',
                'Open the page logged out; read the PHP error log for that URL; fix and redeploy.', 'developer');
        }
        if ($failed && count($failed) === count($results)) return $b + self::v('unknown', 'check-failed', 'The server could not fetch its own pages.');
        return $b + self::v('green', 'ok', (count($results) - count($failed)) . ' logged-out pages render to </html>' . ($failed ? ' (' . count($failed) . ' not fetched)' : '') . '.');
    }

    // (13) Pending migrations ────────────────────────────────────────────────

    /** Numbered migrations (NNN_*.sql) not run successfully. done-/problem-/todo- files are ignored, as in the migrations manager. */
    public static function pendingMigrations(array $files, array $applied): array
    {
        $applied = array_flip($applied);
        $out = [];
        foreach (array_unique($files) as $f) {
            $f = basename((string)$f);
            if (!preg_match('/^\d+[a-z]?_[\w-]+\.sql$/i', $f)) continue;
            if (!isset($applied[$f])) $out[] = $f;
        }
        sort($out, SORT_NATURAL);
        return $out;
    }

    public static function migrationsCheck(array $pending, int $known): array
    {
        $b = self::base('migrations', 'Migrations not yet run');
        if (!$pending) return $b + self::v('green', 'ok', "Every numbered migration ({$known} known) is in migrations_log.");
        $nums = array_map(fn($f) => preg_replace('/_.*$/', '', $f), $pending);
        return $b + self::v('amber', 'pending', count($pending) . ' migration(s) never run: ' . self::listed($nums, 15) . '.',
            'Open Database Manager → Migrations and run them in order (or confirm each is obsolete and rename it done-…/todo-…).', 'tim');
    }

    // (14) PHP errors ────────────────────────────────────────────────────────

    /** "[13-Oct-2026 01:02:03 America/Vancouver] PHP Warning: …" → [unix time, message] or null. */
    public static function parseErrorLine(string $line): ?array
    {
        if (!preg_match('/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2})(?: ([A-Za-z_\/+\-0-9]+))?\]\s*(.*)$/', $line, $m)) return null;
        try {
            $tz = isset($m[2]) && $m[2] !== '' ? new DateTimeZone($m[2]) : null;
        } catch (Throwable $e) {
            $tz = null;
        }
        $d = DateTime::createFromFormat('d-M-Y H:i:s', $m[1], $tz ?: new DateTimeZone(date_default_timezone_get()));
        return $d ? [$d->getTimestamp(), $m[3]] : null;
    }

    /** One error message, made safe to show: secrets and long tokens removed, numbers kept short. */
    public static function redact(string $msg): string
    {
        $msg = preg_replace('/\b(sk|pk|rk|whsec)_(live|test)?_?[A-Za-z0-9]{6,}/', '$1_…', $msg);
        $msg = preg_replace('/(pass(word)?|pwd|secret|token|key|auth(orization)?|bearer)(\s*[=:]\s*|\s+)("[^"]*"|\'[^\']*\'|\S+)/i', '$1$3…', $msg);
        $msg = preg_replace('#mysql:[^\s]+#', 'mysql:…', $msg);
        $msg = preg_replace('/[A-Za-z0-9+\/_\-]{32,}={0,2}/', '…', $msg);
        $msg = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/', '<email>', $msg);
        $msg = trim(preg_replace('/\s+/', ' ', $msg));
        return mb_strlen($msg) > 160 ? mb_substr($msg, 0, 157) . '…' : $msg;
    }

    /** Dedup key for a message: ids and quoted values collapsed so one bug counts once. */
    public static function errorKey(string $msg): string
    {
        $k = preg_replace("/'[^']*'|\"[^\"]*\"/", "'…'", $msg);
        $k = preg_replace('/#\d+|\b\d{3,}\b/', 'N', $k);
        return self::redact($k);
    }

    /** @param array<string, int> $top message => count (already sorted, top N) */
    public static function phpErrorsCheck(int $count, bool $floor, array $top, array $files): array
    {
        $b = self::base('php-errors', 'PHP error log (24 h)');
        if (!$files) return $b + self::v('unknown', 'no-log', 'No PHP error log found to read.');
        $n = ($floor ? 'at least ' : '') . $count;
        $lines = [];
        foreach ($top as $m => $c) $lines[] = "{$c}× {$m}";
        $ev = "{$n} PHP error-log lines in 24 h" . ($lines ? '. Top: ' . implode(' | ', $lines) : '') . '.';
        $hand = 'Fix the top messages first (each is one bug repeating). Log: ' . implode(', ', $files) . '.';
        if ($count > self::ERRORS_RED) return $b + self::v('red', 'spike', $ev, $hand, 'developer');
        if ($count > self::ERRORS_AMBER) return $b + self::v('amber', 'many', $ev, $hand, 'developer');
        return $b + self::v('green', 'ok', $ev);
    }

    // (15) Outbound mail DNS ─────────────────────────────────────────────────

    /** IPv4 inside a CIDR ("1.2.3.0/24" or a bare address). */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $ipL = ip2long($ip);
        $netL = ip2long($net);
        if ($ipL === false || $netL === false) return false;
        $bits = max(0, min(32, (int)$bits));
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
        return ($ipL & $mask) === ($netL & $mask);
    }

    /**
     * Does an SPF record allow $ip? Handles ip4, a, mx and include (to depth 4); qualifier "-"/"~"
     * mechanisms never count as allowing. $resolve(type, name) returns TXT strings (type 'TXT'),
     * IPv4 addresses ('A') or MX hosts ('MX').
     */
    public static function spfAllows(string $spf, string $ip, string $domain, callable $resolve, int $depth = 0): bool
    {
        if ($depth > 4) return false;
        foreach (preg_split('/\s+/', trim($spf)) as $term) {
            if ($term === '' || stripos($term, 'v=spf1') === 0) continue;
            $q = in_array($term[0], ['+', '-', '~', '?'], true) ? $term[0] : '+';
            $mech = $q === '+' && $term[0] !== '+' ? $term : substr($term, 1);
            if ($q !== '+') continue;
            $lower = strtolower($mech);
            if (strpos($lower, 'ip4:') === 0) {
                if (self::ipInCidr($ip, substr($mech, 4))) return true;
            } elseif ($lower === 'a' || strpos($lower, 'a:') === 0 || strpos($lower, 'a/') === 0) {
                $host = strpos($lower, 'a:') === 0 ? explode('/', substr($mech, 2))[0] : $domain;
                if (in_array($ip, (array)$resolve('A', $host), true)) return true;
            } elseif ($lower === 'mx' || strpos($lower, 'mx:') === 0) {
                $host = strpos($lower, 'mx:') === 0 ? substr($mech, 3) : $domain;
                foreach ((array)$resolve('MX', $host) as $mx) {
                    if (in_array($ip, (array)$resolve('A', $mx), true)) return true;
                }
            } elseif (strpos($lower, 'include:') === 0) {
                $inc = substr($mech, 8);
                foreach ((array)$resolve('TXT', $inc) as $t) {
                    if (stripos($t, 'v=spf1') === 0 && self::spfAllows($t, $ip, $inc, $resolve, $depth + 1)) return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array $txt TXT records of the domain; $dmarc TXT of _dmarc.; $dkim TXT of <selector>._domainkey.
     * @param ?string $sendIp the SMTP host's address (null = unknown → SPF only checked for presence)
     */
    public static function mailDnsCheck(string $domain, array $txt, array $dmarc, ?string $selector, array $dkim, ?string $sendHost, ?string $sendIp, callable $resolve): array
    {
        $b = self::base('mail-dns', 'Email sender records (SPF / DMARC / DKIM)');
        $spf = null;
        foreach ($txt as $t) if (stripos((string)$t, 'v=spf1') === 0) { $spf = (string)$t; break; }
        $dm = null;
        foreach ($dmarc as $t) if (stripos((string)$t, 'v=DMARC1') === 0) { $dm = (string)$t; break; }
        $dk = null;
        foreach ($dkim as $t) if (stripos((string)$t, 'v=DKIM1') !== false || stripos((string)$t, 'p=') !== false) { $dk = (string)$t; break; }

        $problems = $good = [];
        if ($spf === null) $problems[] = 'no SPF record';
        elseif ($sendIp && !self::spfAllows($spf, $sendIp, $domain, $resolve)) $problems[] = "SPF does not allow the sending host {$sendHost} ({$sendIp})";
        else $good[] = 'SPF allows ' . ($sendHost ?: 'the sender');
        if ($dm === null) $problems[] = 'no DMARC record';
        else $good[] = 'DMARC ' . (preg_match('/\bp=(\w+)/i', $dm, $x) ? 'p=' . strtolower($x[1]) : 'present');
        if ($selector !== null) {
            if ($dk === null) $problems[] = "no DKIM key at {$selector}._domainkey";
            else $good[] = "DKIM key at {$selector}._domainkey";
        }
        if ($problems) {
            return $b + self::v('amber', 'missing', ucfirst(implode('; ', $problems)) . ($good ? ' (' . implode(', ', $good) . ')' : '') . ' — invoices may land in spam.',
                'cPanel → Email Deliverability → Repair for mowology.ca (it writes SPF and DKIM); add a DMARC TXT at _dmarc.mowology.ca.', 'tim');
        }
        return $b + self::v('green', 'ok', implode('; ', $good) . '.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Stored results, staleness, urgent alerts
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When each red check turned red: kept from the previous run while it stays red, set to $now
     * when it newly turns red, cleared otherwise.
     * @param array<string, ?string> $prevRedSince system => red_since of the previous run (null when it wasn't red)
     */
    public static function redSince(array $checks, array $prevRedSince, string $now): array
    {
        foreach ($checks as &$c) {
            $c["red_since"] = $c["state"] === "red" ? (($prevRedSince[$c["system"]] ?? null) ?: $now) : null;
        }
        return $checks;
    }

    /** The desk row when the nightly run has not happened (or is old). Null when fresh. */
    public static function staleCheck(?string $lastRun, string $now): ?array
    {
        $b = self::base('run', 'Nightly security checks', $lastRun);
        if ($lastRun === null) {
            return $b + self::v('amber', 'never', 'The nightly security checks (security_posture) have not run yet.',
                'Add the security_posture line from Database Manager → Cron Jobs to the cPanel crontab, or press Run there.', 'tim');
        }
        if (self::hoursBetween($lastRun, $now) > self::STALE_HOURS) {
            return $b + self::v('amber', 'stale', 'The nightly security checks last ran ' . AdaRules::ago($lastRun, $now) . ' — the results below may be out of date.',
                'Check the security_posture job on Database Manager → Cron Jobs.', 'tim');
        }
        return null;
    }

    /**
     * Charlie's urgent alerts: one per red, keyed by when it turned red — sent once, not daily.
     * @param array $rows stored checks with red_since
     */
    public static function urgentAlerts(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (($r['state'] ?? '') !== 'red' || empty($r['red_since'])) continue;
            $out[] = ['key' => 'urgent:posture:' . substr((string)$r['system'], 8) . ':' . date('YmdHi', strtotime((string)$r['red_since'])),
                      'kind' => 'security',
                      'text' => 'Ada (security): ' . $r['name'] . ' — ' . mb_substr((string)$r['evidence'], 0, 300),
                      'url' => '/crm/ada_appstack.php#' . rawurlencode((string)$r['system'])];
        }
        return $out;
    }

    public static function bytes(int $n): string
    {
        if ($n >= 1073741824) return round($n / 1073741824, 1) . ' GB';
        if ($n >= 1048576) return round($n / 1048576, 1) . ' MB';
        if ($n >= 1024) return round($n / 1024) . ' KB';
        return $n . ' B';
    }

    // Heartbeat payloads (the backup-heartbeat endpoint) ───────────────────────

    /** Validate a heartbeat body; returns the row to store or an error string. */
    public static function heartbeatRow(array $in, string $now)
    {
        $kind = (string)($in['kind'] ?? '');
        if (!in_array($kind, self::HEARTBEAT_KINDS, true)) return 'kind must be db, files or composer';
        $fin = (string)($in['finished_at'] ?? '');
        $t = $fin === '' ? strtotime($now) : strtotime($fin);
        if ($t === false) return 'finished_at is not a date';
        if ($t > strtotime($now) + 3600) return 'finished_at is in the future';
        $bytes = isset($in['bytes']) && is_numeric($in['bytes']) ? max(0, (int)$in['bytes']) : null;
        $adv = isset($in['advisories']) && is_numeric($in['advisories']) ? max(0, (int)$in['advisories']) : null;
        $detail = mb_substr(preg_replace('/[^\P{C}]/u', ' ', (string)($in['detail'] ?? '')), 0, 500);
        return ['kind' => $kind, 'ok' => !empty($in['ok']) ? 1 : 0, 'bytes' => $bytes, 'advisories' => $adv,
                'detail' => $detail === '' ? null : $detail, 'finished_at' => date('Y-m-d H:i:s', $t)];
    }
}
