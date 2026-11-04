<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class QboCronWiringTest extends TestCase
{
    public function test_penny_fans_out_to_the_business_qbo_cron(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Modules/Expenses/Cron/penny_prepare.php');
        $this->assertStringContainsString("BusinessCronFanout::run(APP_ROOT . '/Modules/QuickBooks/Cron/qbo_sync.php', 300)", $source);
        $this->assertStringContainsString('QboSyncService::runAll($qconn)', $source);
    }
}
