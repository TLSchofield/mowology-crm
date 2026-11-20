<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Modules/Contracts/Services/ContractBillingPolicy.php';

class ContractBillingPolicyTest extends TestCase
{
    public function test_sending_is_allowed_for_live_businesses_and_refused_for_test_businesses(): void
    {
        $this->assertTrue(ContractBillingPolicy::sendAllowed(false));
        $this->assertFalse(ContractBillingPolicy::sendAllowed(true));
    }
}
