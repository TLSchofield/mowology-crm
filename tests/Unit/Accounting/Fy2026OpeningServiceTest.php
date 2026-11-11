<?php
/**
 * FY2026 opening (migration 1238): one entry dated 2026-01-01 moves every balance-sheet account to
 * the filed Dec-31-2025 figure, closes 2025's revenue and expenses, and balances on retained
 * earnings — which must end at the filed $111,465. 2025 (locked) is never posted into; 2026's
 * income statement never shows the closing.
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Fy2026OpeningServiceTest extends TestCase
{
    private const CHART = [
        // id, code, name, type, sub_type
        [1, '1010', 'Chequing', 'asset', 'bank'], [20, '1020', 'Reserve funds', 'asset', 'bank'], [25, '1025', 'GST Reserves', 'asset', 'bank'],
        [12, '1100', 'Accounts Receivable', 'asset', 'receivable'], [23, '1200', 'Prepaid Expenses', 'asset', 'prepaid'],
        [13, '1250', 'Income Tax Receivable', 'asset', 'receivable'], [14, '1300', 'Due from Shareholder', 'asset', 'shareholder_loan'],
        [15, '1500', 'Equipment & Tools', 'asset', 'fixed'],
        [16, '2100', 'Accounts Payable', 'liability', 'payable'], [3, '2200', 'GST Collected', 'liability', 'tax_payable'],
        [2, '2210', 'GST ITC', 'liability', 'tax_itc'], [7, '2400', 'Credit Card Payable', 'liability', 'credit_card'],
        [17, '2500', 'Due to Government Agencies', 'liability', 'gov_payable'], [18, '2510', 'Income Tax Payable', 'liability', 'tax_payable'],
        [19, '2600', 'Loan Payable', 'liability', 'loan'], [9, '2610', 'Loan Payable RAM 3500HD', 'liability', 'loan'],
        [21, '3100', 'Share Capital', 'equity', 'capital'], [22, '3200', 'Retained Earnings', 'equity', 'retained'],
        [24, '3300', 'Owner\'s Draw', 'equity', 'draw'], [39, '3900', 'Opening Balance Equity', 'equity', 'opening'],
        [5, '4900', 'Other Services', 'revenue', 'service'], [6, '5100', 'Wages', 'expense', 'labour'], [10, '6100', 'Fuel', 'expense', 'vehicle'],
    ];

    /** The CRM's 2025: the FY2024 opening (1067), a year of trading, loan payments, a card bill, a prepaid. */
    private const ENTRIES = [
        ['2025-01-01', 'opening', 2024, [[1, 4865, 0], [12, 37910, 0], [13, 466, 0], [15, 26188, 0], [14, 71066, 0],
                                         [16, 0, 10806], [17, 0, 71047], [19, 0, 32341], [21, 0, 1], [22, 0, 26300]]],
        ['2025-06-30', 'manual', null, [[1, 50000, 0], [5, 0, 47619.05], [3, 0, 2380.95]]],
        ['2025-07-31', 'manual', null, [[6, 20000, 0], [1, 0, 20000]]],
        ['2025-08-31', 'manual', null, [[9, 8455, 0], [1, 0, 8455]]],
        ['2025-09-30', 'manual', null, [[10, 1000, 0], [2, 50, 0], [7, 0, 1050]]],
        ['2025-10-31', 'manual', null, [[23, 300, 0], [1, 0, 300]]],
    ];

    private static function accounts(): array
    {
        return array_map(fn($a) => ['id' => $a[0], 'code' => $a[1], 'name' => $a[2], 'type' => $a[3], 'sub_type' => $a[4]], self::CHART);
    }

    private static function balances(): array
    {
        $b = [];
        foreach (self::ENTRIES as [$d, $t, $s, $lines]) foreach ($lines as [$id, $dr, $cr]) $b[$id] = round(($b[$id] ?? 0) + $dr - $cr, 2);
        return $b;
    }

    private static function line(array $plan, string $code): ?array
    {
        foreach ($plan['groups'] as $g) foreach ($g['accounts'] as $l) if ($l['code'] === $code) return $l;
        return null;
    }

    // ── pure ────────────────────────────────────────────────────────────────

    public function testOpeningBalancesAndLandsRetainedEarningsOnTheFiledFigure(): void
    {
        $plan = Fy2026OpeningService::plan(self::accounts(), self::balances(), Fy2026OpeningService::FILED_2025,
                                           ['1010' => 8000.00, '1020' => 2500.00, '1025' => 517.00, '2400' => 1050.00]);
        $this->assertSame([], $plan['problems']);
        $this->assertTrue($plan['totals']['balanced']);
        $this->assertSame($plan['totals']['debit'], $plan['totals']['credit']);
        $this->assertTrue($plan['filed_check']['balanced']);

        // Cash: savings at their statements, chequing takes the rest of the filed $11,017.
        $this->assertSame(2500.0, self::line($plan, '1020')['target']);
        $this->assertSame(517.0, self::line($plan, '1025')['target']);
        $this->assertSame(8000.0, self::line($plan, '1010')['target']);
        $this->assertSame(11017.0, $plan['groups']['cash']['after']);
        // The RAM loan sits on 2610 only; GST moves into "due to government"; the card keeps its statement.
        $this->assertSame(0.0, self::line($plan, '2600')['target']);
        $this->assertSame(23886.0, self::line($plan, '2610')['target']);
        $this->assertSame(0.0, self::line($plan, '2200')['target']);
        $this->assertSame(0.0, self::line($plan, '2210')['target']);
        $this->assertSame(16432.0, self::line($plan, '2500')['target']);
        $this->assertSame(1050.0, self::line($plan, '2400')['target']);
        $this->assertSame(4798.0, self::line($plan, '2100')['target'], 'AP = filed $5,848 less the card on its statement');
        $this->assertSame(10454.0, self::line($plan, '2510')['target']);
        // Off the filed balance sheet → zeroed and flagged; P&L closed.
        $this->assertSame('unmapped', self::line($plan, '1200')['group']);
        $this->assertSame(0.0, self::line($plan, '1200')['target']);
        $this->assertNotEmpty(array_filter($plan['notes'], fn($n) => strpos($n, '1200') === 0));
        foreach (['4900', '5100', '6100'] as $c) $this->assertSame(0.0, self::line($plan, $c)['target'], $c . ' closed');

        // Retained earnings: line = filed − CRM 3200; correction = filed − implied.
        $re = $plan['retained'];
        $this->assertSame(25365.0, $re['after']);
        $this->assertTrue($re['matches_filed']);
        $this->assertSame(26300.0, $re['crm_account']);
        $this->assertSame(26619.05, $re['closing_pl']);
        $this->assertSame(52919.05, $re['implied']);
        $this->assertSame(-935.0, $re['adjustment']);
        $this->assertSame(-27554.05, $re['unexplained']);
        // The per-line effects add up to the correction.
        $sum = 0.0;
        foreach ($plan['groups'] as $g => $grp) if ($grp['re_effect'] !== null) $sum += $grp['re_effect'];
        $this->assertEqualsWithDelta($re['unexplained'], $sum, 0.01);
    }

    public function testNoStatementKeepsTheCrmBalanceAndAMissingAccountIsAProblem(): void
    {
        $acc = array_values(array_filter(self::accounts(), fn($a) => $a['code'] !== '2510'));
        $plan = Fy2026OpeningService::plan($acc, self::balances(), Fy2026OpeningService::FILED_2025, []);
        $this->assertSame('keep', self::line($plan, '1020')['mode']);
        $this->assertNotEmpty($plan['problems'], 'no income tax payable account');
        $this->assertStringContainsString('2510', implode(' ', $plan['problems']));
    }

    public function testStatementBalanceAtYearEnd(): void
    {
        $st = ['closing' => ['2025-10' => 100.0, '2025-12' => 250.0, '2026-01' => 300.0], 'opening' => 50.0, 'first_date' => '2025-10-02'];
        $this->assertSame(250.0, Fy2026OpeningService::balanceAt($st, '2025-12'));
        $st['closing'] = ['2025-10' => 100.0, '2026-02' => 300.0];
        $this->assertSame(100.0, Fy2026OpeningService::balanceAt($st, '2025-12'), 'no December lines: the balance carries');
        $this->assertSame(75.0, Fy2026OpeningService::balanceAt(['closing' => ['2026-01' => 90.0], 'opening' => 75.0, 'first_date' => '2026-01-04'], '2025-12'));
        $this->assertNull(Fy2026OpeningService::balanceAt(['closing' => [], 'opening' => null, 'first_date' => null], '2025-12'));
    }

    // ── DB ──────────────────────────────────────────────────────────────────

    public function testBookLandsOnTheFiledBalanceSheetNeverTouches2025AndUndoReverses(): void
    {
        $db = self::db();
        $ledger = new LedgerService($db);
        foreach (self::ENTRIES as [$d, $t, $s, $lines]) {
            $ledger->postManual(['entry_date' => $d, 'source_type' => $t, 'source_id' => $s, 'memo' => 'fixture',
                                 'lines' => array_map(fn($l) => ['account_id' => $l[0], 'debit' => $l[1], 'credit' => $l[2]], $lines)]);
        }
        for ($m = 1; $m <= 12; $m++) $db->exec("INSERT INTO accounting_periods (year, month, status) VALUES (2025, $m, 'locked')");
        $before2025 = (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE entry_date < '2026-01-01'")->fetchColumn();

        $svc = new Fy2026OpeningService($db, $ledger);
        $p = $svc->preview();
        $this->assertTrue($p['ready']);
        $this->assertNull($p['booked']);
        $this->assertFalse($svc->book('stale', 1)['ok'], 'signature mismatch refused');

        $res = $svc->book($p['signature'], 1);
        $this->assertTrue($res['ok'], $res['message']);
        $this->assertSame('2026-01-01', $db->query("SELECT entry_date FROM journal_entries WHERE source_type = 'fy_opening'")->fetchColumn());
        $this->assertSame($before2025, (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE entry_date < '2026-01-01'")->fetchColumn(), '2025 untouched');
        $this->assertFalse($svc->book($p['signature'], 1)['ok'], 'booked once');

        $bs = (new ReportingService($db))->getBalanceSheet('2026-01-01');
        $this->assertTrue($bs['balances']);
        $this->assertSame(81986.0, $bs['total_assets']);
        $this->assertSame(0.0, $bs['net_income'], '2025 closed');
        $codes = [];
        foreach (array_merge($bs['assets'], $bs['liabilities'], $bs['equity']) as $a) $codes[$a['code']] = $a['balance'];
        $this->assertSame(25365.0, $codes['3200']);
        $this->assertSame(-14.0, $codes['1300'], '$14 owed to the shareholder (Updated FS)');
        $this->assertSame(51948.0, $codes['1100']);
        $this->assertSame(23886.0, $codes['2610']);
        $this->assertSame(0.0, $codes['2600']);
        $this->assertSame(0.0, (new ReportingService($db))->getIncomeStatement('2026-01-01', '2026-12-31')['net_income'], '2026 P&L leaves the closing out');
        $this->assertSame(26619.05, (new ReportingService($db))->getIncomeStatement('2025-01-01', '2025-12-31')['net_income'], '2025 P&L as it was');

        $u = $svc->undo(1);
        $this->assertTrue($u['ok'], $u['message']);
        $this->assertNull($svc->existing());
        $this->assertSame(0.0, (new ReportingService($db))->getIncomeStatement('2026-01-01', '2026-12-31')['net_income'], 'the reversal is left out too');
        $bs = (new ReportingService($db))->getBalanceSheet('2026-01-01');
        $this->assertSame(26619.05, $bs['net_income'], 'back to the CRM figures');
    }

    public static function db(): PDO
    {
        $db = method_exists(PDO::class, 'connect') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT, name TEXT, type TEXT, sub_type TEXT, normal_balance TEXT,
                   display_order INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1, expense_category_alias TEXT)");
        foreach (self::CHART as $a) {
            $normal = in_array($a[3], ['asset', 'expense'], true) || $a[1] === '2210' ? 'debit' : 'credit';
            $db->prepare("INSERT INTO chart_of_accounts (id, code, name, type, sub_type, normal_balance) VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([$a[0], $a[1], $a[2], $a[3], $a[4], $normal]);
        }
        $db->exec("CREATE TABLE journal_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_date TEXT, memo TEXT, source_type TEXT, source_id INTEGER,
                   period_id INTEGER, status TEXT, is_adjusting INTEGER DEFAULT 0, created_by INTEGER, reversed_by_entry_id INTEGER)");
        $db->exec("CREATE TABLE journal_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, account_id INTEGER, debit REAL DEFAULT 0, credit REAL DEFAULT 0,
                   gst_amount REAL DEFAULT 0, pst_amount REAL DEFAULT 0, description TEXT, job_id INTEGER, contact_id INTEGER, vendor_id INTEGER,
                   crew_user_id INTEGER, cost_type_id INTEGER, service_type TEXT)");
        $db->exec("CREATE TABLE accounting_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, year INTEGER, month INTEGER, status TEXT)");
        $db->exec("CREATE TABLE fy_filed_balances (id INTEGER PRIMARY KEY AUTOINCREMENT, fiscal_year INTEGER, line TEXT, label TEXT, side TEXT, amount REAL, source TEXT)");
        foreach (Fy2026OpeningService::FILED_2025 as $line => $amt) {
            $db->prepare("INSERT INTO fy_filed_balances (fiscal_year, line, label, side, amount, source) VALUES (2025, ?, ?, '', ?, 'Signed FS YE2025')")->execute([$line, $line, $amt]);
        }
        return $db;
    }
}
