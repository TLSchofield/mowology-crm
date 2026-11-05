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

    /**
     * The PHP to start a business's run with. The cron's own `/usr/local/bin/php` is cPanel's PHP 8.1 with
     * proc_open/popen disabled; the 8.3 CLI (what the web runs) has nothing disabled (probed 2026-11-04).
     * CRON_PHP_BINARY (secrets.php) overrides.
     */
    public const CLI_CANDIDATES = ['/opt/cpanel/ea-php83/root/usr/bin/php'];

    public static function cliBinary(): string
    {
        if (defined('CRON_PHP_BINARY') && is_executable((string)CRON_PHP_BINARY)) return (string)CRON_PHP_BINARY;
        foreach (self::CLI_CANDIDATES as $bin) {
            if (is_executable($bin)) return $bin;
        }
        return self::phpCli((string)PHP_BINARY, (string)PHP_BINDIR);
    }

    /** proc_open where it exists (output captured); else fork + exec (the cron's PHP 8.1 has only pcntl). */
    public static function defaultSpawn(): callable
    {
        if (function_exists('proc_open')) return [self::class, 'spawnProcOpen'];
        if (function_exists('pcntl_fork') && function_exists('pcntl_exec')) return [self::class, 'spawnFork'];
        return static function (): array {
            throw new RuntimeException('Neither proc_open nor pcntl_fork/pcntl_exec is available to this PHP');
        };
    }

    /** @return array{exit: int, output: string} */
    public static function spawnProcOpen(array $argv, int $timeout): array
    {
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
    }

    /**
     * fork + exec. The child's output goes straight to this cron's output (not captured); the business's own
     * cron_runs holds the detail. A child whose exec fails kills itself with SIGKILL: a normal exit would run
     * PDO destructors on the database socket it shares with this process and close ours.
     * @return array{exit: int, output: string}
     */
    public static function spawnFork(array $argv, int $timeout): array
    {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('Could not fork for the business cron');
        if ($pid === 0) {
            @pcntl_exec((string)$argv[0], array_map('strval', array_slice($argv, 1)));
            fwrite(STDERR, 'exec failed: ' . $argv[0] . "\n");
            posix_kill(getmypid(), 9);
            exit(127);   // not reached
        }
        $started = microtime(true);
        $status = 0;
        $timedOut = false;
        while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            if (microtime(true) - $started >= $timeout) {
                $timedOut = true;
                posix_kill($pid, 15);
                for ($i = 0; $i < 50 && pcntl_waitpid($pid, $status, WNOHANG) === 0; $i++) usleep(100000);
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, 9);
                    pcntl_waitpid($pid, $status);
                }
                break;
            }
            usleep(100000);
        }
        if ($timedOut) return ['exit' => 124, 'output' => 'timed out after ' . $timeout . ' s'];
        $exit = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 128 + (int)pcntl_wtermsig($status);
        return ['exit' => (int)$exit, 'output' => $exit === 0 ? '' : 'see that business\'s cron log'];
    }

    public static function commands(array $keys, string $php, string $script): array
    {
        $out = [];
        foreach ($keys as $key) {
            $key = (string)$key;
            // Any key the registry accepts (argv array: no shell, so no quoting to worry about).
            if ($key === Tenant::DEFAULT || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key)) continue;
            $out[] = ['key' => $key, 'argv' => [$php, $script, '--tenant=' . $key]];
        }
        return $out;
    }

    public static function run(string $script, int $timeoutSec = 900, ?callable $spawn = null, ?array $keys = null): array
    {
        if (PHP_SAPI !== 'cli' || !Tenant::isDefault()) return [];
        $php = self::cliBinary();
        $spawn = $spawn ?? self::defaultSpawn();

        $results = [];
        foreach (self::commands($keys ?? Tenant::registeredKeys(), $php, $script) as $command) {
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

    public static function runPairs(array $pairs, ?callable $spawn = null): array
    {
        if (PHP_SAPI !== 'cli' || !Tenant::isDefault()) return [];
        $php = self::cliBinary();
        $spawn = $spawn ?? self::defaultSpawn();
        $results = [];
        foreach ($pairs as $pair) {
            $key = (string)($pair['key'] ?? '');
            if ($key === Tenant::DEFAULT || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key)) continue;
            $started = microtime(true);
            try {
                $r = $spawn([$php, (string)($pair['script'] ?? ''), '--tenant=' . $key], (int)($pair['timeout'] ?? 900));
                $exit = (int)($r['exit'] ?? 1);
                $output = (string)($r['output'] ?? '');
            } catch (Throwable $e) {
                $exit = 1;
                $output = get_class($e) . ': ' . $e->getMessage();
            }
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: []), 'strlen'));
            $last = $lines ? (string)end($lines) : '';
            $last = function_exists('mb_substr') ? mb_substr($last, 0, 300) : substr($last, 0, 300);
            $results[] = ['key' => $key, 'cron' => (string)($pair['cron'] ?? ''), 'exit' => $exit,
                          'last_line' => $last, 'seconds' => round(microtime(true) - $started, 3)];
        }
        return $results;
    }

    /**
     * Start a process and do not wait for it (pulse_check starts the business runner this way, Tim 2026-11-04).
     * One fork: the child starts its own session and execs at once. It never runs PHP's normal exit — that would run
     * the parent's shutdown functions (CronLock release) and close the MySQL socket the two share. An exec that fails
     * ends the child with SIGKILL, which the parent sees within 0.3 s and reports as false. The exited child is reaped
     * by init once this short-lived cron ends.
     */
    public static function startDetached(array $argv): bool
    {
        if (!$argv || !is_executable((string)$argv[0])) return false;
        if (function_exists('pcntl_fork') && function_exists('pcntl_exec') && function_exists('posix_setsid')) {
            $pid = pcntl_fork();
            if ($pid === -1) return false;
            if ($pid === 0) {
                posix_setsid();
                @pcntl_exec((string)$argv[0], array_map('strval', array_slice($argv, 1)));
                posix_kill(getmypid(), 9);
                exit(127);   // not reached
            }
            usleep(300000);
            $status = 0;
            if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                return pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;   // finished already: only a clean exit counts
            }
            return true;
        }
        if (function_exists('proc_open')) {
            $pipes = [];
            $process = proc_open($argv, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
            return is_resource($process);
        }
        return false;
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
