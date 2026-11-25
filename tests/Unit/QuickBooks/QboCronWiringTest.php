<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Core/paths.php';
require_once dirname(__DIR__, 3) . '/app/Core/BusinessCrons.php';

/** QuickBooks sending and contract billing run per business from BusinessCrons, never fanned out inside a cron
 *  (penny_prepare has two crontab lines, so its fan-out ran twice — testcompany, 2026-11-04 17:35:04). */
final class QboCronWiringTest extends TestCase
{
    public function test_penny_runs_only_the_original_business_quickbooks(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Modules/Expenses/Cron/penny_prepare.php');
        $this->assertStringContainsString('QboSyncService::runAll($qconn)', $source);
        $this->assertStringNotContainsString('BusinessCronFanout', $source);
    }

    public function test_registry_runs_qbo_sync_and_contract_billing_per_business(): void
    {
        $r = BusinessCrons::REGISTRY;
        $this->assertSame('/app/Modules/QuickBooks/Cron/qbo_sync.php', $r['qbo_sync_business']['path']);
        $this->assertSame('*/15 * * * *', $r['qbo_sync_business']['expr']);
        $this->assertSame('/app/Modules/Contracts/Cron/contract_billing.php', $r['contract_billing']['path']);
        $this->assertSame('0 3 1 * *', $r['contract_billing']['expr']);
        $this->assertSame('/app/Modules/Contracts/Cron/contract_billing_retry.php', $r['contract_billing_retry']['path']);
        foreach (['qbo_sync_business', 'contract_billing', 'contract_billing_retry'] as $k) {
            $this->assertFileExists(BusinessCrons::scriptPath($r[$k]['path']));
        }
        $retry = (string)file_get_contents(dirname(__DIR__, 3) . '/app/Modules/Contracts/Cron/contract_billing_retry.php');
        $this->assertStringContainsString("'retry-only'", $retry);
    }
}
