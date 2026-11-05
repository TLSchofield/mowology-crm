<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ContractBillingSourceTest extends TestCase
{
    public function test_locks_per_business_and_gates_every_send(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/app/Modules/Contracts/Cron/contract_billing.php');

        // Other businesses run from BusinessCrons::REGISTRY; no fan-out inside the cron.
        $this->assertStringNotContainsString('BusinessCronFanout', $source);
        $this->assertStringContainsString("'mw_contract_billing_' . Tenant::key()", $source);
        $this->assertSame(2, substr_count($source, '$sender->send('));
        $this->assertSame(2, substr_count($source, 'if (!$sendAllowed)'));
        $this->assertStringContainsString('ContractBillingPolicy::sendAllowed(Tenant::isTest())', $source);
    }
}
