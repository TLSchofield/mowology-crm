<?php
/**
 * AdaServerErrors — Ada reads the server's PHP error log for crashes (2026-10-16).
 *
 * Why: Ada watched scheduled jobs (cron_runs) and the CRM's own system_log, but a crash in a page or a webhook
 * only reaches the PHP error log. The Stripe webhook fataled on 189 card payments (May → October) — every
 * payment recorded, every receipt lost — and nothing told anyone. This check groups the last day's fatal and
 * parse errors by file:line, so a repeating crash is on Ada's desk and in Charlie's brief the same day.
 *
 * Cheap enough for the 15-minute brief: only the log's tail (TAIL_BYTES) is read. Read-only. Pure parsing
 * (parse, checksFrom) is unit tested. Global namespace (require_once + new).
 */
declare(strict_types=1);

require_once __DIR__ . '/AdaRules.php';

class AdaServerErrors
{
    public const TAIL_BYTES = 3_000_000;
    public const WINDOW_HOURS = 24;
    public const RED_AT = 3;          // this many of one crash in the window → urgent
    public const MAX_CHECKS = 8;

    private ?string $logPath;
    private string $now;

    public function __construct(?string $logPath = null, ?string $now = null)
    {
        $this->logPath = $logPath ?? self::defaultLog();
        $this->now = $now ?? date('Y-m-d H:i:s');
    }

    public static function defaultLog(): ?string
    {
        $ini = (string)ini_get('error_log');
        if ($ini !== '' && is_file($ini)) return $ini;
        foreach (['/home/mowology/logs/mowology_ca.php.error.log'] as $p) if (is_file($p)) return $p;
        return null;
    }

    /** Ada's checks for the server errors group. */
    public function checks(): array
    {
        if (!$this->logPath || !is_readable($this->logPath)) {
            return [self::base('php:log') + ['name' => 'Server error log', 'last_worked' => null, 'state' => 'unknown', 'problem' => 'unreadable',
                     'evidence' => "Ada can't read the server's PHP error log, so a crashing page wouldn't reach her.",
                     'handoff' => 'Ada: AdaServerErrors could not open the PHP error log (ini error_log). Point it at the right file.', 'handoff_to' => 'developer']];
        }
        $size = (int)filesize($this->logPath);
        $fh = fopen($this->logPath, 'r');
        if ($size > self::TAIL_BYTES) { fseek($fh, $size - self::TAIL_BYTES); fgets($fh); }
        $tail = (string)stream_get_contents($fh);
        fclose($fh);
        return self::checksFrom(self::parse($tail, $this->now), $this->now);
    }

    /**
     * Fatal / parse errors in the window, grouped by file:line → [{file, line, message, count, first, last}], most first.
     * Log lines look like "[16-Oct-2026 11:54:01 America/Vancouver] PHP Fatal error:  Uncaught Error: … in /home/…/webhook.php:450".
     */
    public static function parse(string $log, string $now, int $hours = self::WINDOW_HOURS): array
    {
        $tz = new DateTimeZone(date_default_timezone_get());
        $since = (new DateTimeImmutable($now, $tz))->modify("-{$hours} hours");
        $groups = [];
        foreach (preg_split('/\R/', $log) as $line) {
            if (!preg_match('/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}) ([^\]]+)\] PHP (Fatal error|Parse error):\s+(.*)$/', $line, $m)) continue;
            try {
                $at = DateTimeImmutable::createFromFormat('d-M-Y H:i:s e', $m[1] . ' ' . trim($m[2]));
            } catch (Throwable $e) { $at = false; }
            if (!$at) continue;
            $at = $at->setTimezone($tz);
            if ($at < $since) continue;
            $msg = $m[4];
            // "… in /path/file.php:450" or "… in /path/file.php on line 450"
            if (!preg_match('#\bin (/\S+?\.php)(?::(\d+)| on line (\d+))#', $msg, $f)) continue;
            $file = preg_replace('#^.*?/public_html/#', '', $f[1]);
            $ln = (int)($f[2] !== '' ? $f[2] : ($f[3] ?? 0));
            $short = trim(preg_replace('/\s+/', ' ', preg_replace('#\s+in /\S+.*$#', '', $msg)));
            $short = preg_replace('/^Uncaught (\w+\\\\?)*\w+:\s*/', '', $short);
            $key = $file . ':' . $ln;
            $stamp = $at->format('Y-m-d H:i:s');
            if (!isset($groups[$key])) $groups[$key] = ['file' => $file, 'line' => $ln, 'message' => mb_substr((string)$short, 0, 160), 'count' => 0, 'first' => $stamp, 'last' => $stamp];
            $groups[$key]['count']++;
            if ($stamp < $groups[$key]['first']) $groups[$key]['first'] = $stamp;
            if ($stamp > $groups[$key]['last']) $groups[$key]['last'] = $stamp;
        }
        $out = array_values($groups);
        usort($out, fn($a, $b) => [$b['count'], $b['last']] <=> [$a['count'], $a['last']]);
        return $out;
    }

    /** One check per crashing place (worst first), or one green "no crashes" check. */
    public static function checksFrom(array $groups, string $now): array
    {
        if (!$groups) {
            return [self::base('php:fatal') + ['name' => 'Server crashes', 'last_worked' => $now, 'state' => 'green', 'problem' => 'ok',
                     'evidence' => 'No page or webhook crashed in the last ' . self::WINDOW_HOURS . ' hours.', 'handoff' => '', 'handoff_to' => '']];
        }
        $out = [];
        foreach (array_slice($groups, 0, self::MAX_CHECKS) as $g) {
            $n = (int)$g['count'];
            $where = $g['file'] . ' line ' . $g['line'];
            $out[] = self::base('php:' . $g['file'] . ':' . $g['line']) + [
                'name' => 'Crash: ' . $g['file'],
                'last_worked' => null,
                'state' => $n >= self::RED_AT ? 'red' : 'amber',
                'problem' => 'crashing',
                'evidence' => $where . ' crashed ' . $n . ' time' . ($n === 1 ? '' : 's') . ' in the last ' . self::WINDOW_HOURS . ' hours'
                              . ' (last ' . substr((string)$g['last'], 11, 5) . '): ' . $g['message'],
                'handoff' => "Ada: PHP fatal at {$where}, {$n}× since {$g['first']} — \"{$g['message']}\". Find the cause and fix it; check what the request was doing before the crash (was anything half-done?).",
                'handoff_to' => 'developer',
            ];
        }
        return $out;
    }

    private static function base(string $system): array
    {
        return ['system' => $system, 'group' => 'Server errors'];
    }
}
