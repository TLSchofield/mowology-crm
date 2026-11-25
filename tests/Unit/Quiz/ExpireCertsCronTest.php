<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The nightly cert-expiry cron must work under CLI and report every run
 * (vault: a cron that skips recordCronRun reads "Never run" forever).
 */
class ExpireCertsCronTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string)file_get_contents(__DIR__ . '/../../../' . $rel);
    }

    public function test_cron_loads_config_for_cli_and_records_on_every_exit(): void
    {
        $s = self::src('app/Modules/Quiz/Cron/expire_certs.php');
        $this->assertStringContainsString("require_once APP_ROOT . '/Core/config.php';", $s);
        $this->assertStringContainsString("recordCronRun('expire_certs'", $s);
        // Every early return is preceded by a recorded run and an answer.
        $returns = preg_match_all('/^\s+return;$/m', $s);
        $this->assertGreaterThan(0, $returns);
        $this->assertSame($returns, preg_match_all('/__expireCertsRecord\([^;]+;\s+__expireCertsOut\([^;]+;\s+return;/', $s));
        $this->assertStringContainsString("__expireCertsRecord('error'", $s);
        $this->assertStringContainsString("__expireCertsRecord('success', \$summary)", $s);
    }

    public function test_registry_row_matches_the_key_and_files(): void
    {
        $reg = self::src('public/crm/database_appstack.php');
        $this->assertStringContainsString("'key'=>'expire_certs'", $reg);
        $this->assertStringContainsString("'/app/Modules/Quiz/Cron/expire_certs.php'", $reg);
        $this->assertFileExists(__DIR__ . '/../../../public/crm/cron/expire_certs.php');
    }
}
