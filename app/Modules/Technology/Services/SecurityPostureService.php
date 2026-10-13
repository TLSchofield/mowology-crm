<?php
/**
 * SecurityPostureService — gathers the facts for Ada's nightly security-posture checks, stores the
 * verdicts, and hands them to her desk and to Charlie's urgent email. The decisions are in
 * SecurityPostureRules (unit tested).
 *
 *   run()        every check, each on its own (one failure → that row says "can't tell")
 *   store()      replaces the rows in security_posture_checks, keeping red_since for reds
 *   deskChecks() the stored rows (+ a row when the nightly run is missing or stale) — cheap,
 *                read by AdaDeskService every 15 minutes inside Charlie's brief
 *   urgent()     one alert per NEW red (CharlieUrgentService::find)
 *
 * Read-only towards everything it checks: it never changes a setting, credential, cron or Stripe
 * configuration. Its own writes are security_posture_checks (and the heartbeat endpoint's
 * backup_heartbeats). Cron: security_posture (nightly). Migration 1347.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/AdaRules.php';
require_once __DIR__ . '/SecurityPostureRules.php';

class SecurityPostureService
{
    public const TABLE = 'security_posture_checks';
    public const MANIFEST = 'deploy-manifest.json';
    public const DEFAULT_BASE_URL = 'https://mowology.ca';
    public const HTTP_TIMEOUT = 15;
    public const ERROR_TAIL_BYTES = 16 * 1048576;

    private PDO $db;
    private ?string $now;
    private string $baseUrl;
    private string $publicRoot;
    private string $appRoot;
    /** error_log files found by the web-root scan (check 2 feeds check 14). */
    private array $errorLogs = [];
    private ?array $manifest = null;
    private bool $manifestRead = false;

    public function __construct(PDO $db, ?string $now = null, array $opts = [])
    {
        $this->db = $db;
        $this->now = $now;
        $this->baseUrl = rtrim((string)($opts['base_url'] ?? (getenv('MW_POSTURE_BASE_URL') ?: self::DEFAULT_BASE_URL)), '/');
        $this->publicRoot = rtrim((string)($opts['public_root'] ?? (defined('PUBLIC_ROOT') ? PUBLIC_ROOT : '')), '/');
        $this->appRoot = rtrim((string)($opts['app_root'] ?? (defined('APP_ROOT') ? APP_ROOT : $this->publicRoot . '/app')), '/');
    }

    private function now(): string { return $this->now ?? date('Y-m-d H:i:s'); }

    public static function ready(PDO $db): bool
    {
        try {
            return $db->query("SHOW TABLES LIKE '" . self::TABLE . "'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The token the Mac's heartbeat must send: BACKUP_HEARTBEAT_TOKEN, else derived from the JWT secret. */
    public static function heartbeatToken(): ?string
    {
        if (defined('BACKUP_HEARTBEAT_TOKEN') && strlen((string)BACKUP_HEARTBEAT_TOKEN) >= 24) return (string)BACKUP_HEARTBEAT_TOKEN;
        if (defined('BLUEMOON_JWT_SECRET') && strlen((string)BLUEMOON_JWT_SECRET) >= 32) {
            return hash_hmac('sha256', 'backup-heartbeat', (string)BLUEMOON_JWT_SECRET);
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Run + store
    // ─────────────────────────────────────────────────────────────────────────

    public function run(): array
    {
        $checks = [];
        $steps = [
            'blocked-paths'  => 'Private files blocked from the web',
            'stray-files'    => 'Data files in the web root',
            'db-privileges'  => 'Database login rights',
            'stripe-webhook' => 'Stripe webhook events',
            'payment-mail'   => 'Payment email signatures',
            'backups'        => 'Backups',
            'audit-copy'     => 'Audit log copy',
            'certificate'    => 'Website certificate',
            'dependencies'   => 'PHP library advisories',
            'drift'          => 'Live files vs last deploy',
            'page-render'    => 'Public pages render fully',
            'migrations'     => 'Migrations not yet run',
            'php-errors'     => 'PHP error log (24 h)',
            'mail-dns'       => 'Email sender records (SPF / DMARC / DKIM)',
        ];
        foreach ($steps as $key => $name) {
            $method = 'check' . str_replace(' ', '', ucwords(str_replace('-', ' ', $key)));
            try {
                foreach ($this->$method() as $c) $checks[] = $c;
            } catch (Throwable $e) {
                error_log("Ada posture: {$key} failed: " . $e->getMessage());
                $checks[] = ['system' => 'posture:' . $key, 'group' => SecurityPostureRules::GROUP, 'name' => $name, 'last_worked' => null,
                             'state' => 'unknown', 'problem' => 'check-failed',
                             'evidence' => "{$name}: Ada couldn't run this check (" . AdaRules::shortError($e->getMessage()) . ').',
                             'handoff' => "Ada: SecurityPostureService::{$method}() threw \"" . AdaRules::shortError($e->getMessage()) . '". Fix the check.',
                             'handoff_to' => 'developer'];
            }
        }
        return $checks;
    }

    /** Replace the stored rows; reds keep the time they first turned red. Returns the rows stored. */
    public function store(array $checks): array
    {
        $prev = [];
        foreach ($this->db->query("SELECT check_key, red_since FROM " . self::TABLE) as $r) $prev[$r['check_key']] = $r['red_since'];
        $now = $this->now();
        $checks = SecurityPostureRules::redSince($checks, $prev, $now);
        $this->db->beginTransaction();
        try {
            $this->db->exec("DELETE FROM " . self::TABLE);
            $ins = $this->db->prepare("INSERT INTO " . self::TABLE . "
                (check_key, name, state, problem, evidence, handoff, handoff_to, last_worked, red_since, checked_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($checks as $c) {
                $ins->execute([mb_substr($c['system'], 0, 60), mb_substr($c['name'], 0, 120), $c['state'], mb_substr((string)$c['problem'], 0, 40),
                               mb_substr((string)$c['evidence'], 0, 4000), $c['handoff'] !== null ? mb_substr((string)$c['handoff'], 0, 2000) : null,
                               $c['handoff_to'], $c['last_worked'], $c['red_since'], $now]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $checks;
    }

    /** Stored rows in Ada's check shape, plus a row when the nightly run is missing or stale. */
    public function deskChecks(): array
    {
        if (!self::ready($this->db)) return [];
        $rows = $this->db->query("SELECT * FROM " . self::TABLE . " ORDER BY check_key")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        $last = null;
        foreach ($rows as $r) {
            $last = max($last ?? '', (string)$r['checked_at']);
            $out[] = ['system' => $r['check_key'], 'group' => SecurityPostureRules::GROUP, 'name' => $r['name'],
                      'last_worked' => $r['last_worked'], 'state' => $r['state'], 'problem' => $r['problem'],
                      'evidence' => $r['evidence'], 'handoff' => $r['handoff'], 'handoff_to' => $r['handoff_to'],
                      'red_since' => $r['red_since']];
        }
        $stale = SecurityPostureRules::staleCheck($last, $this->now());
        if ($stale) $out[] = $stale;
        return $out;
    }

    /** Charlie: one alert per red, keyed by when it turned red (only from a fresh run). */
    public function urgent(): array
    {
        $rows = $this->deskChecks();
        foreach ($rows as $r) if ($r['system'] === 'posture:run') return [];   // stale: don't re-alert old reds
        return SecurityPostureRules::urgentAlerts($rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HTTP helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{code:int, body:string, error:?string} body capped at $maxBytes */
    private function fetch(string $url, int $maxBytes, array $opts = []): array
    {
        $ch = curl_init($url);
        $body = '';
        curl_setopt_array($ch, $opts + [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'Mowology-Ada-posture/1.0',
            CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache'],
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, $maxBytes) {
                $body .= $chunk;
                return strlen($body) > $maxBytes ? 0 : strlen($chunk);   // 0 aborts once we have enough
            },
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch) && curl_errno($ch) !== 23 ? curl_error($ch) : null;   // 23 = we stopped reading
        curl_close($ch);
        return ['code' => $code, 'body' => $body, 'error' => $err];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The checks (each returns a list of Ada checks)
    // ─────────────────────────────────────────────────────────────────────────

    private function checkBlockedPaths(): array
    {
        $codes = [];
        foreach (SecurityPostureRules::BLOCKED_PATHS as $p) {
            $codes[$p] = $this->fetch($this->baseUrl . $p, 2048)['code'];
        }
        return [SecurityPostureRules::blockedPathsCheck($codes)];
    }

    private function checkStrayFiles(): array
    {
        if ($this->publicRoot === '' || !is_dir($this->publicRoot)) throw new RuntimeException('web root not found');
        $skip = SecurityPostureRules::STRAY_SKIP_DIRS;
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($this->publicRoot, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $f) use ($skip) {
                    if (!$f->isDir()) return true;
                    return !in_array($f->getFilename(), $skip, true) && !$f->isLink() && $f->isReadable();
                }),
            RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
        $stray = [];
        $len = strlen($this->publicRoot) + 1;
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), $len);
            if ($f->getFilename() === 'error_log') $this->errorLogs[] = $f->getPathname();
            if (SecurityPostureRules::isStray($rel)) $stray[] = $rel;
        }
        return [SecurityPostureRules::strayFilesCheck($stray)];
    }

    private function checkDbPrivileges(): array
    {
        $grants = $this->db->query("SHOW GRANTS FOR CURRENT_USER()")->fetchAll(PDO::FETCH_COLUMN);
        $dbName = (string)$this->db->query("SELECT DATABASE()")->fetchColumn();
        return [SecurityPostureRules::dbPrivilegesCheck($grants, $dbName)];
    }

    private function checkStripeWebhook(): array
    {
        $key = defined('STRIPE_SECRET_KEY') ? (string)STRIPE_SECRET_KEY : '';
        if ($key === '') return [SecurityPostureRules::stripeWebhookCheck(null, false)];
        $r = $this->fetch('https://api.stripe.com/v1/webhook_endpoints?limit=100', 1048576, [
            CURLOPT_USERPWD => $key . ':',
        ]);
        $j = json_decode($r['body'], true);
        if ($r['code'] !== 200 || !is_array($j) || !isset($j['data'])) {
            $msg = is_array($j) ? (string)($j['error']['message'] ?? '') : '';
            return [SecurityPostureRules::stripeWebhookCheck(null, true, 'HTTP ' . $r['code'] . ($msg !== '' ? ' ' . SecurityPostureRules::redact($msg) : '') . ($r['error'] ? ' ' . $r['error'] : ''))];
        }
        return [SecurityPostureRules::stripeWebhookCheck($j['data'], true)];
    }

    private function checkPaymentMail(): array
    {
        $since = date('Y-m-d H:i:s', strtotime($this->now()) - 7 * 86400);
        try {
            $this->db->query("SELECT sender_verified FROM etransfer_notifications LIMIT 0");
        } catch (Throwable $e) {
            return [['system' => 'posture:payment-mail', 'group' => SecurityPostureRules::GROUP, 'name' => 'Payment email signatures', 'last_worked' => null,
                     'state' => 'unknown', 'problem' => 'no-column', 'evidence' => 'etransfer_notifications.sender_verified does not exist yet (migration 1340 not run).',
                     'handoff' => 'Run migration 1340 in Database Manager → Migrations.', 'handoff_to' => 'tim']];
        }
        // Yardi: auto-recorded lines are stored without sender_verified (only proven mail auto-records).
        // Interac: forwarded notices (Fwd:) are unverified by design and are not counted as held.
        $st = $this->db->prepare("
            SELECT CASE WHEN source = 'yardi_eft' THEN 'Yardi' ELSE 'Interac' END AS src,
                   SUM(CASE WHEN sender_verified = 1 OR (source = 'yardi_eft' AND sender_verified IS NULL AND status = 'recorded') THEN 1 ELSE 0 END) AS verified,
                   SUM(CASE WHEN sender_verified = 0 AND (source = 'yardi_eft'
                            OR COALESCE(email_subject, '') NOT REGEXP '^[[:space:]]*(fwd?|fw)[[:space:]]*:') THEN 1 ELSE 0 END) AS held
            FROM etransfer_notifications
            WHERE created_at >= ?
            GROUP BY src
        ");
        $st->execute([$since]);
        $counts = ['Yardi' => ['held' => 0, 'verified' => 0], 'Interac' => ['held' => 0, 'verified' => 0]];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[$r['src']] = ['held' => (int)$r['held'], 'verified' => (int)$r['verified']];
        return [SecurityPostureRules::paymentMailCheck($counts)];
    }

    private function heartbeat(string $kind, bool $okOnly): ?array
    {
        $st = $this->db->prepare("SELECT kind, ok, bytes, advisories, detail, finished_at FROM backup_heartbeats
                                  WHERE kind = ?" . ($okOnly ? " AND ok = 1" : "") . " ORDER BY finished_at DESC, id DESC LIMIT 1");
        $st->execute([$kind]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function checkBackups(): array
    {
        $now = $this->now();
        return [
            SecurityPostureRules::backupCheck('db', $this->heartbeat('db', true), $this->heartbeat('db', false), $now),
            SecurityPostureRules::backupCheck('files', $this->heartbeat('files', true), $this->heartbeat('files', false), $now),
        ];
    }

    private function checkDependencies(): array
    {
        return [SecurityPostureRules::composerCheck($this->heartbeat('composer', false), $this->now())];
    }

    private function checkAuditCopy(): array
    {
        $dirs = [];
        if (defined('AUDIT_EXPORT_DIR') && (string)AUDIT_EXPORT_DIR !== '') $dirs[] = rtrim((string)AUDIT_EXPORT_DIR, '/');
        $dirs[] = dirname($this->publicRoot) . '/audit';
        $dirs[] = (defined('STORAGE_ROOT') ? rtrim((string)STORAGE_ROOT, '/') : $this->appRoot . '/Storage') . '/audit';
        $best = null;
        foreach ($dirs as $d) {
            $p = $d . '/activity_log.jsonl';
            if (is_file($p) && ($best === null || filemtime($p) > filemtime($best))) $best = $p;
        }
        if ($best === null) return [SecurityPostureRules::auditCopyCheck(null, null, null, 0, $this->now())];
        clearstatcache(true, $best);
        $size = (int)filesize($best);
        $h = @fopen($best, 'rb');
        $break = -1;
        if ($h) {
            fseek($h, max(0, $size - 262144));
            $tail = (string)stream_get_contents($h);
            fclose($h);
            $lines = explode("\n", $tail);
            if ($size > 262144) array_shift($lines);   // first line is partial
            $break = SecurityPostureRules::verifyAuditTail(array_slice($lines, -500));
        }
        return [SecurityPostureRules::auditCopyCheck($best, (int)filemtime($best), $size, $break, $this->now())];
    }

    private function checkCertificate(): array
    {
        $host = (string)parse_url(self::DEFAULT_BASE_URL, PHP_URL_HOST);
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $s = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) return [SecurityPostureRules::certCheck(null, "{$errno} {$errstr}", $this->now(), $host)];
        $params = stream_context_get_params($s);
        fclose($s);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $info = $cert ? openssl_x509_parse($cert) : null;
        return [SecurityPostureRules::certCheck(isset($info['validTo_time_t']) ? (int)$info['validTo_time_t'] : null, 'no certificate returned', $this->now(), $host)];
    }

    private function manifest(): ?array
    {
        if ($this->manifestRead) return $this->manifest;
        $this->manifestRead = true;
        $p = (defined('STORAGE_ROOT') ? rtrim((string)STORAGE_ROOT, '/') : $this->appRoot . '/Storage') . '/' . self::MANIFEST;
        if (!is_file($p)) return null;
        $j = json_decode((string)file_get_contents($p), true);
        return $this->manifest = is_array($j) ? $j : null;
    }

    /** Repo path in the manifest → the file on this server. */
    private function serverPath(string $repoPath): ?string
    {
        if (strpos($repoPath, 'public/') === 0) return $this->publicRoot . '/' . substr($repoPath, 7);
        if (strpos($repoPath, 'app/') === 0) return $this->appRoot . '/' . substr($repoPath, 4);
        if (strpos($repoPath, 'database/migrations/') === 0) return $this->publicRoot . '/' . $repoPath;
        return null;
    }

    private function checkDrift(): array
    {
        $m = $this->manifest();
        $changed = [];
        foreach ((array)($m['files'] ?? []) as $repoPath => $sha) {
            $p = $this->serverPath((string)$repoPath);
            if ($p === null) continue;
            if (!is_file($p)) { $changed[$repoPath] = 'missing'; continue; }
            if (sha1_file($p) !== $sha) $changed[$repoPath] = 'changed';
        }
        return [SecurityPostureRules::driftCheck($m, $changed)];
    }

    private function checkPageRender(): array
    {
        $res = [];
        foreach (SecurityPostureRules::RENDER_PAGES as $path => $want) {
            $r = $this->fetch($this->baseUrl . $path, 4 * 1048576);
            $res[$path] = ['code' => $r['code'], 'bytes' => strlen($r['body']), 'error' => $r['error'],
                           'html_end' => (bool)preg_match('#</html>\s*$#i', $r['body'])];
        }
        return [SecurityPostureRules::pageRenderCheck($res)];
    }

    private function checkMigrations(): array
    {
        $files = array_map('basename', glob($this->publicRoot . '/database/migrations/*.sql') ?: []);
        foreach ((array)($this->manifest()['migrations'] ?? []) as $f) $files[] = basename((string)$f);
        $applied = $this->db->query("SELECT DISTINCT migration_filename FROM migrations_log WHERE status = 'success'")->fetchAll(PDO::FETCH_COLUMN);
        $pending = SecurityPostureRules::pendingMigrations($files, $applied);
        return [SecurityPostureRules::migrationsCheck($pending, count(array_unique($files)))];
    }

    private function checkPhpErrors(): array
    {
        $cands = [(string)ini_get('error_log'), dirname($this->publicRoot) . '/error_log', $this->publicRoot . '/error_log', $this->appRoot . '/error_log'];
        $files = [];
        foreach (array_merge($cands, $this->errorLogs) as $f) {
            if ($f !== '' && is_file($f) && is_readable($f)) $files[realpath($f) ?: $f] = true;
        }
        $files = array_keys($files);
        $since = strtotime($this->now()) - 86400;
        $count = 0;
        $all = 0;
        $floor = false;
        $byKey = [];
        foreach ($files as $f) {
            $size = (int)filesize($f);
            if ($size === 0 || filemtime($f) < $since) continue;
            $h = fopen($f, 'rb');
            if (!$h) continue;
            $start = max(0, $size - self::ERROR_TAIL_BYTES);
            fseek($h, $start);
            $first = true;
            $sawOld = false;
            while (($line = fgets($h)) !== false) {
                if ($first && $start > 0) { $first = false; continue; }   // partial line
                $first = false;
                $p = SecurityPostureRules::parseErrorLine(rtrim($line, "\r\n"));
                if ($p === null) continue;
                if ($p[0] < $since) { $sawOld = true; continue; }
                $all++;
                if (!SecurityPostureRules::isErrorMessage($p[1])) continue;
                $count++;
                $k = SecurityPostureRules::errorKey($p[1]);
                $byKey[$k] = ($byKey[$k] ?? 0) + 1;
            }
            fclose($h);
            if ($start > 0 && !$sawOld) $floor = true;
        }
        arsort($byKey);
        $short = array_map(fn($f) => str_replace(dirname($this->publicRoot) . '/', '~/', $f), $files);
        return [SecurityPostureRules::phpErrorsCheck($count, $floor, array_slice($byKey, 0, SecurityPostureRules::ERRORS_TOP, true), $short, $all)];
    }

    private function checkMailDns(): array
    {
        $domain = (string)parse_url(self::DEFAULT_BASE_URL, PHP_URL_HOST);
        $resolve = function (string $type, string $name): array {
            $map = ['TXT' => DNS_TXT, 'A' => DNS_A, 'MX' => DNS_MX];
            $recs = @dns_get_record($name, $map[$type]) ?: [];
            $out = [];
            foreach ($recs as $r) {
                if ($type === 'TXT') $out[] = isset($r['entries']) ? implode('', $r['entries']) : (string)($r['txt'] ?? '');
                elseif ($type === 'A') $out[] = (string)($r['ip'] ?? '');
                else $out[] = (string)($r['target'] ?? '');
            }
            return $out;
        };
        $sendHost = defined('SMTP_HOST') && (string)SMTP_HOST !== '' ? (string)SMTP_HOST : $domain;
        $sendIp = $resolve('A', $sendHost)[0] ?? null;
        $selector = defined('DKIM_SELECTOR') && (string)DKIM_SELECTOR !== '' ? (string)DKIM_SELECTOR : 'default';   // cPanel signs with "default"
        return [SecurityPostureRules::mailDnsCheck($domain, $resolve('TXT', $domain), $resolve('TXT', '_dmarc.' . $domain),
            $selector, $resolve('TXT', $selector . '._domainkey.' . $domain), $sendHost, $sendIp, $resolve)];
    }
}
