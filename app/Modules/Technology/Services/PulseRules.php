<?php
declare(strict_types=1);

/**
 * PulseRules — the judging half of Pulse, as pure functions (Ittai, 2026-10-28).
 *
 * Pulse's probes (PulseService) gather facts; everything that decides "is this bad, and is it worth waking the owner"
 * lives here so it can be tested without a server: reading the web server's access log, grouping requests by screen,
 * counting server errors, spotting new PHP fatals, and turning a run history into "alert once per incident".
 *
 * Plain-English rule: an alert says what the PERSON would notice ("Charlie's card isn't loading"), not a status code.
 * No namespace / no autoloader: require_once and call statically.
 */
final class PulseRules
{
    public const WINDOW_SECONDS = 900;       // how far back the log watchers look
    public const BAD_RUNS_TO_ALERT = 2;      // consecutive failing runs (10 minutes) before the owner is told
    public const KEEP_DAYS = 14;

    // A screen is failing when it returns server errors for a real share of its requests.
    public const REQ_FAIL_MIN_ERRORS = 5;
    public const REQ_FAIL_SHARE = 0.10;
    public const REQ_ALLBAD_MIN = 3;         // every request failed, at least this many
    public const REQ_WARN_ERRORS = 2;

    /** check_key => the words a person would use. */
    public const LABELS = [
        'head_cards' => "The AI Office cards (Charlie and the heads)",
        'pages'      => 'The sign-in and public pages',
        'database'   => 'The database',
        'ai'         => 'AI answers and drafts',
        'queues'     => 'Background queues',
        'requests'   => 'The app and website (server errors)',
        'php_fatals' => 'Crashes in the code (PHP fatal errors)',
    ];

    // ── Access log ───────────────────────────────────────────────────────────

    /**
     * One combined-format line: IP - - [28/Oct/2026:09:16:07 -0400] "GET /path?x=1 HTTP/1.1" 200 15407 "ref" "ua"
     * @return array{ts:int, method:string, path:string, query:string, status:int, bytes:int, ua:string}|null
     */
    public static function parseAccessLine(string $line): ?array
    {
        if (!preg_match('/\[(\d{2}\/[A-Za-z]{3}\/\d{4}:\d{2}:\d{2}:\d{2} [+-]\d{4})\] "([A-Z]+) (\S+)[^"]*" (\d{3}) (\d+|-)(?: "[^"]*" "([^"]*)")?/', $line, $m)) return null;
        $d = DateTime::createFromFormat('d/M/Y:H:i:s O', $m[1]);
        if (!$d) return null;
        $uri = $m[3];
        $q = strpos($uri, '?');
        return [
            'ts' => $d->getTimestamp(), 'method' => $m[2],
            'path' => $q === false ? $uri : substr($uri, 0, $q), 'query' => $q === false ? '' : substr($uri, $q + 1),
            'status' => (int)$m[4], 'bytes' => $m[5] === '-' ? 0 : (int)$m[5], 'ua' => $m[6] ?? '',
        ];
    }

    public static function isAsset(string $path): bool
    {
        return (bool)preg_match('/\.(css|js|map|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot|mp4|pdf)$/i', $path);
    }

    public static function isBot(string $ua): bool
    {
        return (bool)preg_match('/bot|crawl|spider|scan|curl|python-requests|wget|monitor|uptime/i', $ua);
    }

    /** The screen a request belongs to: the path with ids collapsed, plus ?mode= (one endpoint serves many modes). */
    public static function group(string $path, string $query = ''): string
    {
        $g = strtolower(preg_replace('#/\d+(?=/|$)#', '/#', $path) ?? $path);
        if ($query !== '' && preg_match('/(?:^|&)mode=([a-z_\-]+)/i', $query, $m)) $g .= ' [' . strtolower($m[1]) . ']';
        return $g;
    }

    /**
     * @param array[] $parsed parseAccessLine() results
     * @return array<string, array{total:int, errors:int, status:string, example:int}> by screen, only screens with errors, worst first
     */
    public static function requestFindings(array $parsed, int $now, int $window = self::WINDOW_SECONDS): array
    {
        $by = [];
        foreach ($parsed as $r) {
            if (!$r || $r['ts'] < $now - $window || $r['ts'] > $now + 60) continue;
            if (self::isAsset($r['path']) || self::isBot($r['ua'])) continue;
            if (preg_match('#/once-[0-9a-f]+\.php#', $r['path'])) continue;   // a developer's one-off runner, not something a user hit
            $g = self::group($r['path'], $r['query']);
            $by[$g] ??= ['total' => 0, 'errors' => 0, 'status' => 'ok', 'example' => 0];
            $by[$g]['total']++;
            if ($r['status'] >= 500) {
                $by[$g]['errors']++;
                $by[$g]['example'] = $r['status'];
            }
        }
        foreach ($by as $g => &$v) {
            $e = $v['errors'];
            if (($e >= self::REQ_FAIL_MIN_ERRORS && $e / max(1, $v['total']) >= self::REQ_FAIL_SHARE) || ($e >= self::REQ_ALLBAD_MIN && $e === $v['total'])) $v['status'] = 'fail';
            elseif ($e >= self::REQ_WARN_ERRORS) $v['status'] = 'warn';
        }
        unset($v);
        $by = array_filter($by, fn($v) => $v['errors'] > 0);
        uasort($by, fn($a, $b) => [$b['status'] === 'fail', $b['errors']] <=> [$a['status'] === 'fail', $a['errors']]);
        return $by;
    }

    /** @return array{status:string, detail:string} */
    public static function requestsCheck(array $findings, int $totalSeen): array
    {
        if (!$findings) return ['status' => 'ok', 'detail' => $totalSeen > 0 ? "No server errors in the last 15 minutes ($totalSeen requests)." : 'No requests in the last 15 minutes.'];
        $worst = 'warn';
        $bits = [];
        foreach (array_slice($findings, 0, 3, true) as $g => $v) {
            if ($v['status'] === 'fail') $worst = 'fail';
            if ($v['status'] === 'ok') continue;
            $bits[] = $g . ': ' . $v['errors'] . ' of ' . $v['total'] . ' requests failed';
        }
        if (!$bits) return ['status' => 'ok', 'detail' => 'A stray server error or two, nothing repeating.'];
        return ['status' => $worst, 'detail' => implode('; ', $bits) . '.'];
    }

    // ── PHP error log ────────────────────────────────────────────────────────

    /**
     * New PHP fatals in the window. Our own one-off runner files (prod-run's once-*.php) are ignored: they are a
     * developer's rolled-back test, not something a user hit.
     * @param string[] $lines raw error-log lines
     * @return array<string, array{count:int, msg:string}> keyed by SecurityPostureRules::errorKey
     */
    public static function fatalFindings(array $lines, int $now, int $window = self::WINDOW_SECONDS): array
    {
        $out = [];
        foreach ($lines as $line) {
            $p = SecurityPostureRules::parseErrorLine($line);
            if (!$p || $p[0] < $now - $window || $p[0] > $now + 60) continue;
            $msg = $p[1];
            if (!preg_match('/PHP (Fatal|Parse) error|Uncaught (Error|Exception|TypeError)/i', $msg)) continue;
            if (preg_match('#/once-[0-9a-f]+\.php#', $msg)) continue;
            $k = SecurityPostureRules::errorKey($msg);
            $out[$k] ??= ['count' => 0, 'msg' => SecurityPostureRules::redact($msg)];
            $out[$k]['count']++;
        }
        uasort($out, fn($a, $b) => $b['count'] <=> $a['count']);
        return $out;
    }

    /** @return array{status:string, detail:string} */
    public static function fatalsCheck(array $findings): array
    {
        if (!$findings) return ['status' => 'ok', 'detail' => 'No new crashes in the last 15 minutes.'];
        $total = array_sum(array_column($findings, 'count'));
        $first = array_values($findings)[0];
        return ['status' => $total >= 3 ? 'fail' : 'warn', 'detail' => $total . ' PHP fatal error' . ($total === 1 ? '' : 's') . ' in 15 minutes, e.g. ' . mb_substr($first['msg'], 0, 140)];
    }

    // ── AI health ────────────────────────────────────────────────────────────

    /** @return array{status:string, detail:string} */
    public static function aiCheck(int $calls, int $notOk, int $killed): array
    {
        if ($killed > 0 && $killed === $calls && $calls > 0) return ['status' => 'warn', 'detail' => 'AI is switched off (every call in the last hour was stopped by a kill switch).'];
        if ($calls < 3) return ['status' => 'ok', 'detail' => $calls === 0 ? 'No AI calls in the last hour.' : "$calls AI call(s) in the last hour, too few to judge."];
        $share = $notOk / $calls;
        if ($share >= 0.5) return ['status' => 'fail', 'detail' => "$notOk of the last $calls AI calls failed."];
        if ($share >= 0.25) return ['status' => 'warn', 'detail' => "$notOk of the last $calls AI calls failed."];
        return ['status' => 'ok', 'detail' => "$calls AI calls in the last hour, $notOk problem" . ($notOk === 1 ? '' : 's') . '.'];
    }

    // ── History → alerts ─────────────────────────────────────────────────────

    /**
     * @param array<int,array{status:string, run_at:string}> $history one check's runs, NEWEST first
     * @return array{streak:int, since:?string} leading run of 'fail' and when it began
     */
    public static function streak(array $history): array
    {
        $n = 0;
        $since = null;
        foreach ($history as $h) {
            if ($h['status'] !== 'fail') break;
            $n++;
            $since = $h['run_at'];
        }
        return ['streak' => $n, 'since' => $since];
    }

    /** One alert per incident: the key carries when it began, so the same outage never re-sends. */
    public static function alert(string $checkKey, string $detail, string $since, int $runs): ?array
    {
        $label = self::LABELS[$checkKey] ?? $checkKey;
        $t = strtotime($since);
        if ($t === false) return null;
        return [
            'key'  => 'urgent:pulse:' . $checkKey . ':' . date('YmdHi', $t),
            'kind' => 'pulse',
            'text' => $label . " isn't working: " . $runs . ' checks in a row failed (since ' . date('g:i a', $t) . '). ' . rtrim($detail, '.') . '.',
            'url'  => '/crm/ada_appstack.php#pulse:' . $checkKey,
        ];
    }

    /** Ada's colour for a Pulse status. */
    public static function adaState(string $status): string
    {
        return ['ok' => 'green', 'warn' => 'amber', 'fail' => 'red'][$status] ?? 'unknown';
    }
}
