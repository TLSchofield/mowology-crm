<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ContractBillingSourceTest extends TestCase
{
    public function test_cron_fans_out_locks_per_business_and_gates_every_send(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/app/Modules/Contracts/Cron/contract_billing.php');

        $this->assertStringContainsString('BusinessCronFanout::run(__FILE__, 600', $source);
        $this->assertSame(4, substr_count($source, '$runFanout();'));
        $this->assertSame(1, substr_count($source, 'BusinessCronFanout::run('));
        $this->assertSame(1, preg_match(
            '/if \(\(int\)\$lockStmt->fetchColumn\(\) !== 1\).*?\$runFanout\(\);.*?exit/s',
            $source
        ), 'Lock contention must fan out before exiting.');
        $this->assertSame(1, preg_match(
            '/if \(empty\(\$colCheck\)\).*?\$runFanout\(\);.*?exit/s',
            $source
        ), 'A missing required schema column must fan out before exiting.');
        $this->assertSame(1, preg_match(
            '/catch \(Throwable \$e\) \{\s*\$msg = \'Failed to query contracts:.*?\$runFanout\(\);.*?exit/s',
            $source
        ), 'A contracts-query failure must fan out before exiting.');
        $this->assertStringContainsString('$fanoutResults = $runFanout();', $source);
        $this->assertStringContainsString("'mw_contract_billing_' . Tenant::key()", $source);
        $this->assertSame(2, substr_count($source, '$sender->send('));
        $this->assertSame(2, substr_count($source, 'if (!$sendAllowed)'));
        $this->assertStringContainsString('ContractBillingPolicy::sendAllowed(Tenant::isTest())', $source);
    }
}
