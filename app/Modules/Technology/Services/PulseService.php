<?php
declare(strict_types=1);

require_once __DIR__ . '/PulseRules.php';
require_once __DIR__ . '/SecurityPostureRules.php';

/**
 * PulseService — is the app working right now? (Ittai, 2026-10-28)
 *
 * Every five minutes (cron pulse_check) it checks the things people depend on and records one pulse_checks row per
 * check. Nothing here changes a client record, sends anything, or writes outside pulse_checks; every probe is
 * time-boxed and fails soft to 'unknown' (a probe that cannot run never raises an alarm, and never breaks the cron).
 *
 *   head_cards  each head's brief builds (the data behind the AI Office cards)           CharlieBriefService::collect
 *   pages       sign-in and public pages return 200 and end in </html>                    HTTP to ourselves
 *   database    answers a query quickly
 *   ai          AI calls are succeeding (llm_calls, last hour)
 *   queues      nothing stuck: push_queue, receipt OCR jobs, failed agent tasks
 *   requests    the web server is not returning errors (last 15 min of the SSL access log)
 *   php_fatals  the code is not crashing (new PHP fatals in the error log, last 15 min)
 *
 * Two bad runs in a row => urgent() returns one alert (PulseRules::alert), plugged into Charlie's urgent email.
 * adaChecks() gives Ada's page a "Live checks" group. Judging lives in PulseRules (pure, tested).
 *
 * No namespace / no autoloader: require_once and `new PulseService($db)`.
 */
class PulseService
{
    public const HTTP_TIMEOUT = 8;
    public const LOG_TAIL_BYTES = 600000;
    public const SLOW_BRIEF_MS = 6000;
    public const FAIL_BRIEF_MS = 12000;
    public const STUCK_MINUTES = 30;
    /** A queue stuck this long is a failure, not a warning. */
    public const FAIL_STUCK_MINUTES = 120;

    private PDO $db;
    /** @var callable fn(): int  "now" as a unix time — injectable for tests */
    private $clock;
    /** @var array<string,callable> check_key => fn(): array{status,detail} — replaceable in tests */
    private array $probes;

    public function __construct(PDO $db, ?callable $clock = null, ?array $probes = null)
    {
        $this->db = $db;
        $this->clock = $clock ?? static fn() => time();
        $this->probes = $probes ?? $this->defaultProbes();
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    // ── Run ──────────────────────────────────────────────────────────────────

    /**
     * Run every probe once and store the results. A probe that throws is recorded as 'unknown'.
     * @return array<string, array{status:string, detail:string, ms:int}>
     */
    public function run(): array
    {
        $out = [];
        $at = date('Y-m-d H:i:s', $this->now());
        foreach ($this->probes as $key => $fn) {
            $t = microtime(true);
            try {
                $r = $fn();
                $status = in_array($r['status'] ?? '', ['ok', 'warn', 'fail', 'unknown'], true) ? $r['status'] : 'unknown';
                $detail = (string)($r['detail'] ?? '');
            } catch (Throwable $e) {
                $status = 'unknown';
                $detail = 'The check itself could not run: ' . SecurityPostureRules::redact($e->getMessage());
                error_log("Pulse {$key}: " . $e->getMessage());
            }
            $ms = (int)round((microtime(true) - $t) * 1000);
            $out[$key] = ['status' => $status, 'detail' => mb_substr($detail, 0, 255), 'ms' => $ms];
            try {
                $this->db->prepare("INSERT INTO pulse_checks (check_key, status, detail, ms, run_at) VALUES (?, ?, ?, ?, ?)")
                         ->execute([$key, $status, $out[$key]['detail'], $ms, $at]);
            } catch (Throwable $e) {
                error_log('Pulse record: ' . $e->getMessage());
            }
        }
        $this->prune();
        return $out;
    }

    private function prune(): void
    {
        try {
            $this->db->prepare("DELETE FROM pulse_checks WHERE run_at < ?")
                     ->execute([date('Y-m-d H:i:s', $this->now() - PulseRules::KEEP_DAYS * 86400)]);
        } catch (Throwable $e) { /* housekeeping only */ }
    }

    // ── Reads: alerts and Ada ────────────────────────────────────────────────

    /** @return array<int,array{key:string,kind:string,text:string,url:string}> one alert per incident that has lasted long enough */
    public function urgent(): array
    {
        $alerts = [];
        foreach (array_keys(PulseRules::LABELS) as $key) {
            $inc = $this->incident($key);
            if ($inc === null || $inc['runs'] < PulseRules::BAD_RUNS_TO_ALERT) continue;
            $a = PulseRules::alert($key, $inc['detail'], $inc['since'], $inc['runs']);
            if ($a) $alerts[] = $a;
        }
        return $alerts;
    }

    /**
     * The current outage of one check, or null when its latest run is not a failure. `since` is the first failure after the
     * last non-failing run, found in SQL, so it stays the same however long the outage lasts (the alert key depends on it).
     * @return array{runs:int, since:string, detail:string}|null
     */
    private function incident(string $key): ?array
    {
        try {
            $last = $this->history($key, 1);
            if (!$last || $last[0]['status'] !== 'fail') return null;
            $g = $this->db->prepare("SELECT MAX(run_at) FROM pulse_checks WHERE check_key = ? AND status <> 'fail'");
            $g->execute([$key]);
            $lastGood = (string)($g->fetchColumn() ?: '0000-00-00 00:00:00');
            $f = $this->db->prepare("SELECT COUNT(*) c, MIN(run_at) s FROM pulse_checks WHERE check_key = ? AND status = 'fail' AND run_at > ?");
            $f->execute([$key, $lastGood]);
            $r = $f->fetch(PDO::FETCH_ASSOC) ?: [];
            if (empty($r['s'])) return null;
            return ['runs' => (int)$r['c'], 'since' => (string)$r['s'], 'detail' => (string)$last[0]['detail']];
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Ada's rows: one per check, newest result. @return array[] */
    public function adaChecks(): array
    {
        $rows = [];
        foreach (PulseRules::LABELS as $key => $label) {
            $hist = $this->history($key, 12);
            if (!$hist) {
                $rows[] = ['system' => 'pulse:' . $key, 'group' => 'Live checks', 'name' => $label, 'last_worked' => null, 'state' => 'unknown',
                           'problem' => 'not-run', 'evidence' => "Pulse hasn't checked this yet (its job runs every 5 minutes).", 'handoff' => null, 'handoff_to' => null];
                continue;
            }
            $now = $hist[0];
            $lastOk = null;
            foreach ($hist as $h) {
                if ($h['status'] === 'ok') { $lastOk = $h['run_at']; break; }
            }
            $state = PulseRules::adaState($now['status']);
            $bad = in_array($state, ['red', 'amber'], true);
            $rows[] = [
                'system' => 'pulse:' . $key, 'group' => 'Live checks', 'name' => $label, 'last_worked' => $lastOk, 'state' => $state,
                'problem' => $bad ? 'pulse-' . $now['status'] : null,
                'evidence' => (string)$now['detail'] . ' (checked ' . date('g:i a', strtotime($now['run_at'])) . ')',
                'handoff' => $bad ? "Pulse: \"{$label}\" is " . ($now['status'] === 'fail' ? 'failing' : 'doing badly') . ': ' . $now['detail']
                                    . ' Look at the server error log and the Crons tab; re-run the check from /crm/cron/pulse_check.php.' : null,
                'handoff_to' => $bad ? 'developer' : null,
            ];
        }
        return $rows;
    }

    /** @return array<int,array{status:string,detail:string,run_at:string}> newest first */
    private function history(string $key, int $n): array
    {
        try {
            $s = $this->db->prepare("SELECT status, detail, run_at FROM pulse_checks WHERE check_key = ? ORDER BY id DESC LIMIT " . max(1, $n));
            $s->execute([$key]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    // ── Probes ───────────────────────────────────────────────────────────────

    private function defaultProbes(): array
    {
        return [
            // Quick, local probes first; the two that can wait on the network or on every head last, so a hang in
            // them cannot stop the others being recorded (and a cron that never finishes shows up on Ada's cron checks).
            'database'   => fn() => $this->probeDatabase(),
            'ai'         => fn() => $this->probeAi(),
            'queues'     => fn() => $this->probeQueues(),
            'requests'   => fn() => $this->probeRequests(),
            'php_fatals' => fn() => $this->probeFatals(),
            'pages'      => fn() => $this->probePages(),
            'head_cards' => fn() => $this->probeHeadCards(),
        ];
    }

    private function probeHeadCards(): array
    {
        require_once dirname(__DIR__, 2) . '/ChiefOfStaff/Services/CharlieBriefService.php';
        $t = microtime(true);
        $c = (new CharlieBriefService($this->db))->collect('Pulse');
        $ms = (int)round((microtime(true) - $t) * 1000);
        if (!empty($c['failed'])) {
            return ['status' => 'fail', 'detail' => implode(', ', array_map('ucfirst', array_keys($c['failed']))) . ' ' . (count($c['failed']) === 1 ? "card isn't" : "cards aren't") . ' building: '
                . SecurityPostureRules::redact((string)array_values($c['failed'])[0])];
        }
        if (count((array)$c['ok']) < 3) return ['status' => 'fail', 'detail' => 'Fewer than three heads reported in (' . count((array)$c['ok']) . ').'];
        if ($ms >= self::FAIL_BRIEF_MS) return ['status' => 'fail', 'detail' => "The cards took {$ms} ms to build."];
        if ($ms >= self::SLOW_BRIEF_MS) return ['status' => 'warn', 'detail' => "The cards are slow ({$ms} ms)."];
        return ['status' => 'ok', 'detail' => count((array)$c['ok']) . ' heads built their cards in ' . $ms . ' ms.'];
    }

    private function probePages(): array
    {
        $base = rtrim(defined('MW_POSTURE_BASE_URL') ? (string)MW_POSTURE_BASE_URL : 'https://mowology.ca', '/');
        $bad = [];
        foreach (['/loginAuth/login.php', '/privacy', '/terms.php'] as $path) {
            $r = $this->get($base . $path, 400000);
            if ($r['error'] || $r['code'] !== 200 || stripos($r['body'], '</html>') === false) {
                $bad[] = $path . ' (' . ($r['error'] ? 'no answer' : ($r['code'] !== 200 ? 'HTTP ' . $r['code'] : 'page cut short')) . ')';
            }
        }
        return $bad ? ['status' => 'fail', 'detail' => 'Not loading properly: ' . implode(', ', $bad) . '.'] : ['status' => 'ok', 'detail' => 'Sign-in and public pages load.'];
    }

    private function probeDatabase(): array
    {
        $t = microtime(true);
        $this->db->query('SELECT 1')->fetchColumn();
        $ms = (int)round((microtime(true) - $t) * 1000);
        return $ms >= 1500 ? ['status' => 'warn', 'detail' => "The database answered slowly ({$ms} ms)."] : ['status' => 'ok', 'detail' => "Database answered in {$ms} ms."];
    }

    private function probeAi(): array
    {
        $since = date('Y-m-d H:i:s', $this->now() - 3600);
        try {
            $s = $this->db->prepare("SELECT COUNT(*) c, SUM(outcome NOT IN ('ok')) bad, SUM(outcome = 'killed') killed FROM llm_calls WHERE created_at >= ?");
            $s->execute([$since]);
            $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return ['status' => 'unknown', 'detail' => 'The AI call log is not set up yet.'];
        }
        return PulseRules::aiCheck((int)($r['c'] ?? 0), (int)($r['bad'] ?? 0), (int)($r['killed'] ?? 0));
    }

    private function probeQueues(): array
    {
        $now = $this->now();
        $cut = date('Y-m-d H:i:s', $now - self::STUCK_MINUTES * 60);
        $problems = [];
        $worst = 'ok';
        $checked = 0;
        foreach ([
            'notifications waiting to send' => ["SELECT COUNT(*) c, MIN(created_at) oldest FROM push_queue WHERE status = 'pending' AND created_at < ?", [$cut]],
            'receipts waiting to be read'   => ["SELECT COUNT(*) c, MIN(created_at) oldest FROM expense_ocr_jobs WHERE status IN ('pending','processing') AND created_at < ?", [$cut]],
        ] as $label => [$sql, $args]) {
            try {
                $s = $this->db->prepare($sql);
                $s->execute($args);
                $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
                $checked++;
                if ((int)($r['c'] ?? 0) > 0) {
                    $mins = (int)floor(($now - strtotime((string)$r['oldest'])) / 60);
                    $problems[] = $r['c'] . ' ' . $label . ' (oldest ' . $mins . ' min)';
                    // Stuck for a long time is no longer a blip: it has to reach the owner like any other failure.
                    $worst = $mins >= self::FAIL_STUCK_MINUTES ? 'fail' : ($worst === 'fail' ? 'fail' : 'warn');
                }
            } catch (Throwable $e) { /* that table is not here on this install */ }
        }
        try {
            $s = $this->db->prepare("SELECT COUNT(*) FROM agent_tasks WHERE state = 'failed' AND updated_at >= ?");
            $s->execute([date('Y-m-d H:i:s', $now - 86400)]);
            $checked++;
            if ((int)$s->fetchColumn() > 0) {
                $problems[] = $s->fetchColumn() === false ? '' : 'AI tasks that gave up';
                $worst = $worst === 'fail' ? 'fail' : 'warn';
            }
        } catch (Throwable $e) { /* not set up here */ }
        $problems = array_values(array_filter($problems));
        if ($checked === 0) return ['status' => 'unknown', 'detail' => 'No queues to check on this install.'];
        return $problems ? ['status' => $worst, 'detail' => 'Stuck: ' . implode(', ', $problems) . '.'] : ['status' => 'ok', 'detail' => 'Nothing is stuck in the queues.'];
    }

    private function probeRequests(): array
    {
        $path = $this->accessLogPath();
        if ($path === null) return ['status' => 'unknown', 'detail' => "The web server's access log was not found (set ops_settings pulse_access_log)."];
        $lines = $this->tail($path, self::LOG_TAIL_BYTES);
        if ($lines === null) return ['status' => 'unknown', 'detail' => 'The access log could not be read.'];
        $now = $this->now();
        $parsed = [];
        $seen = 0;
        foreach ($lines as $l) {
            $p = PulseRules::parseAccessLine($l);
            if ($p && $p['ts'] >= $now - PulseRules::WINDOW_SECONDS) {
                $seen++;
                $parsed[] = $p;
            }
        }
        return PulseRules::requestsCheck(PulseRules::requestFindings($parsed, $now), $seen);
    }

    private function probeFatals(): array
    {
        $path = $this->errorLogPath();
        if ($path === null) return ['status' => 'unknown', 'detail' => 'The PHP error log was not found.'];
        $lines = $this->tail($path, 400000);
        if ($lines === null) return ['status' => 'unknown', 'detail' => 'The PHP error log could not be read.'];
        return PulseRules::fatalsCheck(PulseRules::fatalFindings($lines, $this->now()));
    }

    // ── Files and HTTP ───────────────────────────────────────────────────────

    private function setting(string $key): ?string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return $v === false || $v === '' ? null : (string)$v;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function accessLogPath(): ?string
    {
        $cands = [$this->setting('pulse_access_log')];
        $host = parse_url(defined('MW_POSTURE_BASE_URL') ? (string)MW_POSTURE_BASE_URL : 'https://mowology.ca', PHP_URL_HOST) ?: 'mowology.ca';
        if (defined('PUBLIC_ROOT')) $cands[] = dirname((string)PUBLIC_ROOT) . '/access-logs/' . $host . '-ssl_log';
        foreach (array_filter($cands) as $p) {
            if (is_file($p) && is_readable($p)) return $p;
        }
        return null;
    }

    private function errorLogPath(): ?string
    {
        $cands = [$this->setting('pulse_error_log'), (string)ini_get('error_log')];
        if (defined('PUBLIC_ROOT')) $cands[] = dirname((string)PUBLIC_ROOT) . '/logs/mowology_ca.php.error.log';
        foreach (array_filter($cands) as $p) {
            if (is_file($p) && is_readable($p)) return $p;
        }
        return null;
    }

    /** The last $bytes of a file as whole lines (the cut-off first line is dropped). @return string[]|null */
    private function tail(string $path, int $bytes): ?array
    {
        $size = @filesize($path);
        $fh = @fopen($path, 'r');
        if (!$fh || $size === false) return null;
        $start = max(0, $size - $bytes);
        fseek($fh, $start);
        $txt = (string)stream_get_contents($fh);
        fclose($fh);
        $lines = explode("\n", $txt);
        if ($start > 0) array_shift($lines);
        return $lines;
    }

    /** @return array{code:int, body:string, error:?string} */
    private function get(string $url, int $maxBytes): array
    {
        $ch = curl_init($url);
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => self::HTTP_TIMEOUT, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mowology-Pulse/1.0', CURLOPT_HTTPHEADER => ['Cache-Control: no-cache'],
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $maxBytes) {
                $body .= $chunk;
                return strlen($body) > $maxBytes ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch) && curl_errno($ch) !== 23 ? curl_error($ch) : null;
        curl_close($ch);
        return ['code' => $code, 'body' => $body, 'error' => $err];
    }
}
