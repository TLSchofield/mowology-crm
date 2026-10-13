<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2026-10-13: forgot_clockout_sms.php read ops_settings.`value` / `key` and died with
 * "Unknown column 'value'" on every run (48 a day). The columns are setting_key / setting_value
 * (migration 202). No code may query ops_settings by the wrong names.
 */
class OpsSettingsColumnsTest extends TestCase
{
    public function test_ops_settings_is_read_by_its_real_column_names(): void
    {
        $root = dirname(__DIR__, 3);
        $bad = [];
        foreach (['app', 'public'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php' || strpos($f->getPathname(), '/vendor/') !== false) continue;
                $src = (string)file_get_contents($f->getPathname());
                if (stripos($src, 'ops_settings') === false) continue;
                if (preg_match('/SELECT\s+`?value`?\s+FROM\s+ops_settings|ops_settings\s+WHERE\s+`?key`?\s*=/i', $src)) {
                    $bad[] = substr($f->getPathname(), strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $bad, 'ops_settings columns are setting_key / setting_value');
    }

    public function test_forgot_clockout_cron_reads_setting_value(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/app/Modules/Team/Cron/forgot_clockout_sms.php');
        $this->assertStringContainsString("SELECT setting_value FROM ops_settings WHERE setting_key = 'forgot_clockout_sms_enabled'", $src);
    }
}
