<?php
declare(strict_types=1);

require_once __DIR__ . '/Tenant.php';

/** Runs one CLI cron process for each registered business database. */
final class BusinessCronFanout
{
    public static function phpCli(string $binary, string $bindir): string
    {
        $base = strtolower(basename($binary));
        return $binary === '' || strpos($base, 'fpm') !== false || strpos($base, 'cgi') !== false
            ? rtrim($bindir, '/') . '/php' : $binary;
    }

    public static function commands(array $keys, string $php, string $script, array $extraArgs = []): array
    {
        $out = [];
        foreach ($keys as $key) {
            $key = (string)$key;
            // Any key the registry accepts (argv array: no shell, so no quoting to worry about).
            if ($key === Tenant::DEFAULT || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key)) continue;
            $out[] = ['key' => $key, 'argv' => array_merge([$php, $script, '--tenant=' . $key], $extraArgs)];
        }
        return $out;
    }

    public static function run(string $script, int $timeoutSec = 900, ?callable $spawn = null, ?array $keys = null, array $extraArgs = []): array
    {
        if (PHP_SAPI !== 'cli' || !Tenant::isDefault()) return [];
        $php = self::phpCli((string)PHP_BINARY, (string)PHP_BINDIR);
        $spawn = $spawn ?? static function (array $argv, int $timeout): array {
            $pipes = [];
            $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Could not start business cron');
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $output = '';
            $started = microtime(true);
            $timedOut = false;
            do {
                $output .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) break;
                if (microtime(true) - $started >= $timeout) {
                    // SIGTERM, then SIGKILL after 5 s: proc_close() must never wait on a stuck child (Codex review 2026-11-03).
                    $timedOut = true;
                    proc_terminate($process);
                    for ($i = 0; $i < 50 && proc_get_status($process)['running']; $i++) usleep(100000);
                    if (proc_get_status($process)['running']) proc_terminate($process, 9);
                    break;
                }
                usleep(100000);
            } while (true);
            $output .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $knownExit = isset($status['exitcode']) ? (int)$status['exitcode'] : -1;
            $exit = proc_close($process);
            if ($exit < 0 && $knownExit >= 0) $exit = $knownExit;
            return ['exit' => $timedOut ? 124 : (int)$exit, 'output' => $output];
        };

        $results = [];
        foreach (self::commands($keys ?? Tenant::registeredKeys(), $php, $script, $extraArgs) as $command) {
            $started = microtime(true);
            try {
                $r = $spawn($command['argv'], $timeoutSec);
                $exit = (int)($r['exit'] ?? 1);
                $output = (string)($r['output'] ?? '');
            } catch (Throwable $e) {
                $exit = 1;
                $output = get_class($e) . ': ' . $e->getMessage();
            }
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: []), 'strlen'));
            $last = $lines ? (string)end($lines) : '';
            if (function_exists('mb_substr')) $last = mb_substr($last, 0, 300);
            else $last = substr($last, 0, 300);
            $results[] = ['key' => $command['key'], 'exit' => $exit, 'last_line' => $last,
                          'seconds' => round(microtime(true) - $started, 3)];
        }
        return $results;
    }

    public static function summary(array $results): string
    {
        if (!$results) return '';
        $parts = [];
        foreach ($results as $r) {
            $parts[] = $r['key'] . ((int)$r['exit'] === 0 ? ' ok' : ' failed (exit ' . (int)$r['exit']
                . ((string)($r['last_line'] ?? '') !== '' ? ': ' . $r['last_line'] : '') . ')');
        }
        return 'businesses: ' . implode(', ', $parts);
    }
}
