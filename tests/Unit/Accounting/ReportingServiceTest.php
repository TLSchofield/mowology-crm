<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for ReportingService — financial statements from the journal layer.
 *
 * Focuses on the pure build*() shapers (the accounting logic) with canned
 * account-total rows, proving the tie-out invariants: trial balance ΣDR==ΣCR,
 * balance sheet Assets == Liabilities + Equity + Net Income, and the cost
 * drill-down pivot. One get*() test exercises the SQL wrapper via a mocked PDO.
 */
class ReportingServiceTest extends TestCase
{
    private function service(): ReportingService
    {
        return new ReportingService($this->createMock(PDO::class));
    }

    /**
     * A small balanced ledger:
     *  Bank 1000 DR, AP 300 CR, Capital 500 CR, Revenue 400 CR, Fuel 200 DR.
     *  ΣDR = 1200, ΣCR = 1200. Assets 1000 = Liab 300 + Equity 500 + NetIncome 200.
     */
    private function ledgerRows(): array
    {
        return [
            ['code'=>'1010','name'=>'Bank',   'type'=>'asset',    'normal_balance'=>'debit', 'debit'=>1000,'credit'=>0],
            ['code'=>'2100','name'=>'AP',     'type'=>'liability','normal_balance'=>'credit','debit'=>0,   'credit'=>300],
            ['code'=>'3100','name'=>'Capital','type'=>'equity',   'normal_balance'=>'credit','debit'=>0,   'credit'=>500],
            ['code'=>'4100','name'=>'Lawn',   'type'=>'revenue',  'normal_balance'=>'credit','debit'=>0,   'credit'=>400],
            ['code'=>'6100','name'=>'Fuel',   'type'=>'expense',  'normal_balance'=>'debit', 'debit'=>200, 'credit'=>0],
        ];
    }

    // ── signedBalance ───────────────────────────────────────────────────────────

    /** @test */
    public function signed_balance_respects_normal_side(): void
    {
        $this->assertEqualsWithDelta(40.0,  ReportingService::signedBalance('debit',  100, 60), 0.001);
        $this->assertEqualsWithDelta(40.0,  ReportingService::signedBalance('credit', 60, 100), 0.001);
        $this->assertEqualsWithDelta(-40.0, ReportingService::signedBalance('debit',  60, 100), 0.001);
    }

    // ── Trial balance ───────────────────────────────────────────────────────────

    /** @test */
    public function trial_balance_sums_to_zero(): void
    {
        $tb = $this->service()->buildTrialBalance($this->ledgerRows());
        $this->assertEqualsWithDelta(1200.00, $tb['total_debit'],  0.001);
        $this->assertEqualsWithDelta(1200.00, $tb['total_credit'], 0.001);
        $this->assertTrue($tb['in_balance']);
    }

    // ── Balance sheet ────────────────────────────────────────────────────────────

    /** @test */
    public function balance_sheet_assets_equal_liabilities_plus_equity(): void
    {
        $bs = $this->service()->buildBalanceSheet($this->ledgerRows());
        $this->assertEqualsWithDelta(1000.00, $bs['total_assets'],            0.001);
        $this->assertEqualsWithDelta(300.00,  $bs['total_liabilities'],       0.001);
        $this->assertEqualsWithDelta(500.00,  $bs['total_equity'],            0.001);
        $this->assertEqualsWithDelta(200.00,  $bs['net_income'],              0.001);
        $this->assertEqualsWithDelta(1000.00, $bs['liabilities_plus_equity'], 0.001);
        $this->assertTrue($bs['balances']);
    }

    /** @test 2026-10-13: 2210 ITC is a debit-normal liability; it must reduce liabilities, not add to them. */
    public function balance_sheet_contra_liability_reduces_liabilities(): void
    {
        $rows = [
            ['code' => '1010', 'name' => 'Bank', 'type' => 'asset', 'normal_balance' => 'debit', 'debit' => 1050, 'credit' => 0],
            ['code' => '2200', 'name' => 'GST collected', 'type' => 'liability', 'normal_balance' => 'credit', 'debit' => 0, 'credit' => 100],
            ['code' => '2210', 'name' => 'GST ITC', 'type' => 'liability', 'normal_balance' => 'debit', 'debit' => 50, 'credit' => 0],
            ['code' => '3100', 'name' => 'Capital', 'type' => 'equity', 'normal_balance' => 'credit', 'debit' => 0, 'credit' => 1000],
        ];
        $bs = $this->service()->buildBalanceSheet($rows);
        $this->assertEqualsWithDelta(50.00, $bs['total_liabilities'], 0.001);
        $this->assertEqualsWithDelta(-50.00, $bs['liabilities'][1]['balance'], 0.001);
        $this->assertTrue($bs['balances']);
    }

    // ── Income statement ─────────────────────────────────────────────────────────

    /** @test */
    public function income_statement_nets_revenue_minus_expenses(): void
    {
        $is = $this->service()->buildIncomeStatement($this->ledgerRows());
        $this->assertEqualsWithDelta(400.00, $is['total_revenue'],  0.001);
        $this->assertEqualsWithDelta(200.00, $is['total_expenses'], 0.001);
        $this->assertEqualsWithDelta(200.00, $is['net_income'],     0.001);
    }

    /** @test 2026-10-13 review: a contra-revenue account (debit-normal) must REDUCE revenue, on the IS and in the BS net income. */
    public function contra_revenue_reduces_revenue_and_net_income(): void
    {
        $rows = [
            ['code' => '1010', 'name' => 'Bank', 'type' => 'asset', 'normal_balance' => 'debit', 'debit' => 950, 'credit' => 0],
            ['code' => '4100', 'name' => 'Lawn', 'type' => 'revenue', 'normal_balance' => 'credit', 'debit' => 0, 'credit' => 1000],
            ['code' => '4950', 'name' => 'Sales discounts', 'type' => 'revenue', 'normal_balance' => 'debit', 'debit' => 50, 'credit' => 0],
            ['code' => '6100', 'name' => 'Fuel', 'type' => 'expense', 'normal_balance' => 'debit', 'debit' => 100, 'credit' => 0],
            ['code' => '6150', 'name' => 'Fuel rebate', 'type' => 'expense', 'normal_balance' => 'credit', 'debit' => 0, 'credit' => 100],
            ['code' => '3100', 'name' => 'Capital', 'type' => 'equity', 'normal_balance' => 'credit', 'debit' => 0, 'credit' => 0],
        ];
        $is = $this->service()->buildIncomeStatement($rows);
        $this->assertEqualsWithDelta(-50.00, $is['revenue'][1]['amount'], 0.001, 'the discount shows as negative revenue');
        $this->assertEqualsWithDelta(950.00, $is['total_revenue'], 0.001);
        $this->assertEqualsWithDelta(0.00, $is['total_expenses'], 0.001, 'the rebate (credit on an expense) reduces expenses');
        $this->assertEqualsWithDelta(950.00, $is['net_income'], 0.001);
        $bs = $this->service()->buildBalanceSheet($rows);
        $this->assertEqualsWithDelta(950.00, $bs['net_income'], 0.001, 'the balance sheet uses the same signs');
        $this->assertTrue($bs['balances']);
    }

    // ── Cost drill-down ──────────────────────────────────────────────────────────

    /** @test */
    public function cost_drilldown_groups_and_totals(): void
    {
        $dd = $this->service()->buildCostDrillDown([
            ['key' => 'Materials', 'amount' => 636.16],
            ['key' => 'Labour',    'amount' => 232.40],
            ['key' => null,        'amount' => 10.00],   // unassigned
        ]);
        $this->assertCount(3, $dd['groups']);
        $this->assertSame('(unassigned)', $dd['groups'][2]['key']);
        $this->assertEqualsWithDelta(878.56, $dd['total'], 0.001);
    }

    // ── DB wrapper (mocked PDO) ──────────────────────────────────────────────────

    /** @test */
    public function get_trial_balance_shapes_db_rows(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn($this->ledgerRows());

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $tb = (new ReportingService($pdo))->getTrialBalance('2026-12-31');
        $this->assertTrue($tb['in_balance']);
        $this->assertEqualsWithDelta(1200.00, $tb['total_debit'], 0.001);
    }

    public function test_income_statement_in_the_pl_tab_shape(): void
    {
        $is = ['revenue' => [['code' => '4000', 'name' => 'Sales', 'amount' => 1000.0]],
               'expenses' => [['code' => '6100', 'name' => 'Fuel', 'amount' => 250.0]],
               'total_revenue' => 1000.0, 'total_expenses' => 250.0, 'net_income' => 750.0];
        $pl = ReportingService::asProfitLoss($is, '2026-01-01', '2026-01-31');
        $this->assertSame(1000.0, $pl['revenue'][0]['total']);
        $this->assertSame(750.0, $pl['net_profit']);
        $this->assertSame(75.0, $pl['margin_pct']);
        $this->assertSame('journal', $pl['source']);
        $this->assertSame(0, ReportingService::asProfitLoss(['revenue' => [], 'expenses' => [], 'total_revenue' => 0, 'total_expenses' => 0, 'net_income' => 0], 'a', 'b')['margin_pct']);
    }
}
