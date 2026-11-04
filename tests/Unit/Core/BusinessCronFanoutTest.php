<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class BusinessCronFanoutTest extends TestCase
{
    protected function tearDown(): void
    {
        Tenant::forTests(null);
    }

    public function test_cli_binary_commands_and_registry_keys(): void
    {
        $this->assertSame('/opt/php', BusinessCronFanout::phpCli('/usr/sbin/php-fpm', '/opt'));
        $this->assertSame('/usr/local/bin/php', BusinessCronFanout::phpCli('/usr/local/bin/php', '/opt'));
        $this->assertSame([
            ['key' => 'rehearsal', 'argv' => ['/php', '/cron.php', '--tenant=rehearsal']],
            ['key' => 'green-leaf', 'argv' => ['/php', '/cron.php', '--tenant=green-leaf']],
        ], BusinessCronFanout::commands(['default', 'rehearsal', 'green-leaf', 'bad key;rm'], '/php', '/cron.php'));

        $registry = [
            'z' => ['hosts' => ['z.ittai.ca'], 'db_name' => 'tenant_z'],
            'bad' => ['hosts' => [], 'db_name' => 'tenant_bad'],
            'a' => ['hosts' => ['a.ittai.ca'], 'db_name' => 'tenant_a'],
        ];
        $this->assertSame(['a', 'z'], Tenant::registeredKeys($registry));
    }

    public function test_run_records_failures_and_continues_and_summarises(): void
    {
        Tenant::forTests(['key' => 'default', 'cfg' => []]);
        $seen = [];
        $spawn = function (array $argv) use (&$seen): array {
            $seen[] = $argv[2];
            if (strpos($argv[2], 'broken') !== false) throw new RuntimeException('boom');
            return ['exit' => 0, 'output' => "first\nfinished\n"];
        };
        $results = BusinessCronFanout::run('/cron.php', 2, $spawn, ['good', 'broken', 'later']);
        $this->assertSame(['--tenant=good', '--tenant=broken', '--tenant=later'], $seen);
        $this->assertSame([0, 1, 0], array_column($results, 'exit'));
        $this->assertSame('finished', $results[0]['last_line']);
        $this->assertStringContainsString('broken failed (exit 1: RuntimeException: boom)', BusinessCronFanout::summary($results));

        Tenant::forTests(['key' => 'x', 'cfg' => []]);
        $this->assertSame([], BusinessCronFanout::run('/cron.php', 2, $spawn, ['good']));
    }

    /** The cron's PHP 8.1 has no proc_open: fork + exec must report exit codes, time out, and survive a failed exec. */
    public function test_fork_spawn_exit_timeout_and_failed_exec(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) $this->markTestSkipped('pcntl not available');
        $php = PHP_BINARY;
        $this->assertSame(3, BusinessCronFanout::spawnFork([$php, '-r', 'exit(3);'], 10)['exit']);
        $this->assertSame(0, BusinessCronFanout::spawnFork([$php, '-r', 'exit(0);'], 10)['exit']);
        $t = microtime(true);
        $this->assertSame(124, BusinessCronFanout::spawnFork([$php, '-r', 'sleep(30);'], 1)['exit']);
        $this->assertLessThan(10, microtime(true) - $t, 'a stuck child is stopped');
        $this->assertSame(137, BusinessCronFanout::spawnFork(['/nonexistent/php', 'x'], 5)['exit'], 'exec failure kills the child (SIGKILL)');
    }

    public function test_cli_binary_prefers_the_override_then_83(): void
    {
        $this->assertSame(['/opt/cpanel/ea-php83/root/usr/bin/php'], BusinessCronFanout::CLI_CANDIDATES);
        $this->assertNotSame('', BusinessCronFanout::cliBinary());
    }
}
