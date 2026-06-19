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

    // ── Income statement ─────────────────────────────────────────────────────────

    /** @test */
    public function income_statement_nets_revenue_minus_expenses(): void
    {
        $is = $this->service()->buildIncomeStatement($this->ledgerRows());
        $this->assertEqualsWithDelta(400.00, $is['total_revenue'],  0.001);
        $this->assertEqualsWithDelta(200.00, $is['total_expenses'], 0.001);
        $this->assertEqualsWithDelta(200.00, $is['net_income'],     0.001);
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
}
