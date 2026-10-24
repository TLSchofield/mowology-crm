<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Core/TenantProvisioner.php';

/** Setting up a new business (Ittai, 2026-10-23): nothing of the original business's own goes with it. */
class TenantProvisionerTest extends TestCase
{
    public function test_the_chart_is_cleaned_of_the_original_business(): void
    {
        $rows = [
            ['id' => 44, 'code' => '1025', 'name' => 'GST Reserves (Savings ••6827)', 'sub_type' => null, 'account_number' => '6827', 'gifi_confirmed_by' => 1],
            ['id' => 47, 'code' => '1300', 'name' => 'Due from Shareholder', 'sub_type' => 'shareholder_loan', 'account_number' => null],
            ['id' => 56, 'code' => '1302', 'name' => 'Shareholder loan — Katrenna Marenych', 'sub_type' => 'shareholder_loan', 'description' => 'Katrenna\'s account'],
            ['id' => 54, 'code' => '2520', 'name' => 'Net pay clearing — Wave', 'sub_type' => null, 'description' => 'Net pay owed to employees until paid'],
            ['id' => 60, 'code' => '6100', 'name' => 'Fuel', 'sub_type' => null, 'description' => 'Diesel for the Dodge per Tim'],
        ];
        $c = array_column(TenantProvisioner::cleanChart($rows), null, 'code');
        $this->assertArrayNotHasKey('1302', $c, 'a loan in a person\'s name stays behind');
        $this->assertSame('Due from Shareholder', $c['1300']['name'], 'the generic one goes');
        $this->assertSame('GST Reserves', $c['1025']['name']);
        $this->assertSame('Net pay clearing', $c['2520']['name']);
        $this->assertStringNotContainsString('Katrenna', json_encode(array_values($c)));
        // Allow-list: free text, account numbers and who-confirmed never travel, whatever they say (Codex 2026-10-23).
        foreach ($c as $row) {
            $this->assertSame([], array_diff(array_keys($row), TenantProvisioner::CHART_COLUMNS));
        }
        $this->assertArrayNotHasKey('description', $c['6100']);
        $this->assertArrayNotHasKey('account_number', $c['1025']);
        $this->assertArrayNotHasKey('gifi_confirmed_by', $c['1025']);
    }

    public function test_account_numbers_and_banks_are_stripped_from_names(): void
    {
        $rows = [
            ['id' => 1, 'code' => '1010', 'name' => 'Operating account 123456789', 'sub_type' => null],
            ['id' => 2, 'code' => '1011', 'name' => 'Chequing ••4410', 'sub_type' => null],
            ['id' => 3, 'code' => '1012', 'name' => 'Savings — RBC 5521', 'sub_type' => null],
            ['id' => 4, 'code' => '1013', 'name' => 'Petty cash', 'sub_type' => null],
            ['id' => 5, 'code' => '1020', 'name' => "Reserve funds (Savings \u{00E2}\u{20AC}\u{00A2}\u{00E2}\u{20AC}\u{00A2}6819)", 'sub_type' => 'bank'],   // stored mis-encoded
            ['id' => 6, 'code' => '2610', 'name' => 'Loan Payable — RAM 3500HD', 'sub_type' => 'loan'],
            ['id' => 7, 'code' => '5100', 'name' => 'Labour — Crew Wages', 'sub_type' => 'labour'],
            ['id' => 8, 'code' => '6130', 'name' => 'Vehicle Loan — RAM 3500HD', 'sub_type' => 'vehicle'],   // production's sub_type is not 'loan'
        ];
        $c = array_column(TenantProvisioner::cleanChart($rows), 'name', 'code');
        $this->assertSame(['1010' => 'Operating account', '1011' => 'Chequing', '1012' => 'Savings', '1013' => 'Petty cash',
            '1020' => 'Reserve funds', '2610' => 'Loan Payable', '5100' => 'Labour — Crew Wages', '6130' => 'Vehicle Loan'], $c);
    }

    public function test_business_keys(): void
    {
        foreach (['testcompany', 'greenleaf', 'abc'] as $k) $this->assertTrue(TenantProvisioner::validKey($k), $k);
        foreach (['default', 'www', 'ab', 'Test', 'a-b', '1abc', str_repeat('a', 21), 'admin'] as $k) $this->assertFalse(TenantProvisioner::validKey($k), $k);
    }

    public function test_the_registry_keeps_other_businesses(): void
    {
        $php = TenantProvisioner::registryFile(['greenleaf' => ['hosts' => ['greenleaf.ittai.ca'], 'db_name' => 'x_greenleaf']], 'testcompany',
            ['hosts' => ['testcompany.ittai.ca'], 'db_name' => 'x_testcompany', 'app_url' => 'https://testcompany.ittai.ca']);
        $f = tempnam(sys_get_temp_dir(), 'reg');
        file_put_contents($f, $php);
        $ITTAI_TENANTS = null;
        include $f;
        unlink($f);
        $this->assertSame(['greenleaf', 'testcompany'], array_keys($ITTAI_TENANTS));
        $this->assertSame('x_testcompany', $ITTAI_TENANTS['testcompany']['db_name']);
        foreach ($ITTAI_TENANTS as $e) $this->assertArrayNotHasKey('db_pass', $e, 'no passwords in tenants.php');
    }
}
