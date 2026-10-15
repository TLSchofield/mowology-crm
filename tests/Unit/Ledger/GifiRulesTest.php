<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** GIFI validator (CRA RC4088 Rev. 23): unmapped accounts and totals that don't recompute block the export. */
class GifiRulesTest extends TestCase
{
    private const KNOWN = ['1002' => 'Deposits', '1062' => 'Trade AR', '2680' => 'Taxes payable', '2707' => 'Credit card loans',
                           '3500' => 'Common shares', '3600' => 'Retained earnings', '3700' => 'Dividends declared',
                           '8000' => 'Trade sales', '8320' => 'Purchases', '8715' => 'Bank charges', '9270' => 'Other expenses',
                           '2599' => 'Total assets', '9999' => 'Net income'];

    /** A small, balanced year: opening RE 1,000 + capital 100; sales 5,000; materials 2,000; bank fees 50. */
    private function accounts(): array
    {
        $a = static fn($code, $name, $type, $gifi, $end, $period, $status = 'confirmed') =>
            ['code' => $code, 'name' => $name, 'type' => $type, 'sub_type' => null, 'gifi_code' => $gifi,
             'gifi_status' => $status, 'end' => $end, 'period' => $period, 'lines' => 3];
        return [
            $a('1010', 'Chequing', 'asset', '1002', 3450.40, 2350.40),
            $a('1100', 'AR', 'asset', '1062', 900.00, 900.00),
            $a('2200', 'GST collected', 'liability', '2680', -250.00, -250.00),
            $a('2210', 'GST ITC', 'liability', '2680', 100.00, 100.00),
            $a('2400', 'Card', 'liability', '2707', -150.40, -150.40),
            $a('3100', 'Capital', 'equity', '3500', -100.00, 0.0),
            $a('3200', 'Retained earnings', 'equity', '3600', -1000.00, 0.0),
            $a('4900', 'Services', 'revenue', '8000', -5000.00, -5000.00),
            $a('5200', 'Materials', 'expense', '8320', 2000.00, 2000.00),
            $a('6800', 'Bank charges', 'expense', '8715', 50.00, 50.00),
        ];
    }

    private function ni(array $accts): float
    {
        return GifiService::ledgerNetIncome($accts);
    }

    public function test_balanced_confirmed_mapping_validates_and_recomputes_required_totals(): void
    {
        $acc = $this->accounts();
        $r = GifiRules::build($acc, self::KNOWN, $this->ni($acc));
        $this->assertTrue($r['ok'], json_encode($r['problems']));
        $t = $r['totals'];
        $this->assertSame(4350, $t['2599']);
        $this->assertSame(300, $t['3499'], 'ITC nets against GST collected under 2680; card 150');
        $this->assertSame(5000, $t['8299']);
        $this->assertSame(2050, $t['9368']);
        $this->assertSame(2950, $t['9999']);
        $this->assertSame(3950, $t['3849'], 'RE end = start 1,000 + net income 2,950');
        $this->assertSame(4050, $t['3620']);
        $this->assertSame($t['2599'], $t['3499'] + $t['3620']);
        foreach (GifiRules::REQUIRED as $req) $this->assertArrayHasKey($req, $t);
    }

    public function test_an_unmapped_account_with_activity_blocks_the_export(): void
    {
        $acc = $this->accounts();
        $acc[9]['gifi_code'] = null;
        $r = GifiRules::build($acc, self::KNOWN, $this->ni($acc));
        $this->assertFalse($r['ok']);
        $this->assertSame(1, $r['unmapped']);
        $this->assertSame('unmapped', $r['problems'][0]['kind']);
    }

    public function test_an_unmapped_account_with_no_activity_does_not_block(): void
    {
        $acc = $this->accounts();
        $acc[] = ['code' => '6400', 'name' => 'Marketing', 'type' => 'expense', 'sub_type' => null, 'gifi_code' => null,
                  'gifi_status' => null, 'end' => 0, 'period' => 0, 'lines' => 0];
        $this->assertTrue(GifiRules::build($acc, self::KNOWN, $this->ni($acc))['ok']);
    }

    public function test_a_suggestion_nobody_confirmed_blocks_the_export(): void
    {
        $acc = $this->accounts();
        $acc[0]['gifi_status'] = 'suggested';
        $r = GifiRules::build($acc, self::KNOWN, $this->ni($acc));
        $this->assertFalse($r['ok']);
        $this->assertSame('unconfirmed', $r['problems'][0]['kind']);
    }

    public function test_totals_that_do_not_recompute_block_the_export(): void
    {
        // An expense mapped to a balance-sheet code: wrong statement.
        $acc = $this->accounts();
        $acc[9]['gifi_code'] = '1002';
        $r = GifiRules::build($acc, self::KNOWN, $this->ni($acc));
        $this->assertFalse($r['ok']);
        $this->assertContains('wrong_statement', array_column($r['problems'], 'kind'));

        // A balance-sheet account dropped from the statements (mapped to a total) → assets ≠ liabilities + equity.
        $acc = $this->accounts();
        $acc[1]['gifi_code'] = '2599';
        $r = GifiRules::build($acc, self::KNOWN, $this->ni($acc));
        $kinds = array_column($r['problems'], 'kind');
        $this->assertContains('total_code', $kinds);
        $this->assertContains('totals', $kinds, '2599 no longer equals 3499 + 3620');

        // Net income in the ledger differs from 9999 (an income-statement account missing from the year).
        $acc = $this->accounts();
        $r = GifiRules::build($acc, self::KNOWN, $this->ni($acc) + 500);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('9999', $r['problems'][0]['message']);
    }

    public function test_a_prior_year_never_closed_counts_in_retained_earnings_at_the_start(): void
    {
        // Last year's 75 of fuel sits on the expense account (no FY opening entry closed it); this year it is reversed.
        $acc = $this->accounts();
        $acc[] = ['code' => '6100', 'name' => 'Fuel', 'type' => 'expense', 'sub_type' => null, 'gifi_code' => '9270',
                  'gifi_status' => 'confirmed', 'end' => 0.0, 'period' => -75.0, 'lines' => 1];
        $acc[0]['period'] += 75;                                   // paid last year, back in the bank this year: same balance
        $r = GifiRules::build($acc, self::KNOWN, $this->ni($acc));
        $this->assertTrue($r['ok'], json_encode($r['problems']));
        $this->assertSame(925, $r['lines']['3660'], 'RE start = 1,000 − last year\'s unclosed fuel 75');
        $this->assertSame(3025, $r['totals']['9999']);
        $this->assertSame(3950, $r['totals']['3849']);
    }

    public function test_unknown_code_blocks(): void
    {
        $acc = $this->accounts();
        $acc[9]['gifi_code'] = '8716';   // real CRA code, but not in this test's known list
        $r = GifiRules::build($acc, self::KNOWN, $this->ni($acc));
        $this->assertContains('unknown_code', array_column($r['problems'], 'kind'));
    }

    public function test_whole_dollar_rounding_is_a_warning_not_a_block(): void
    {
        $acc = $this->accounts();
        $acc[0]['end'] = 3450.90;  $acc[0]['period'] = 2350.90;   // bank +0.50 → rounds up to 3451
        $acc[4]['end'] = -150.90;  $acc[4]['period'] = -150.90;   // card +0.50 credit → 151
        $r = GifiRules::build($acc, self::KNOWN, $this->ni($acc));
        $this->assertNotContains('totals', array_column($r['problems'], 'kind'));
    }

    public function test_dividends_reduce_retained_earnings_end(): void
    {
        $acc = $this->accounts();
        $acc[0]['end'] -= 500; $acc[0]['period'] -= 500;
        $acc[] = ['code' => '3400', 'name' => 'Dividends', 'type' => 'equity', 'sub_type' => null, 'gifi_code' => '3700',
                  'gifi_status' => 'confirmed', 'end' => 500, 'period' => 500, 'lines' => 1];
        $r = GifiRules::build($acc, self::KNOWN, $this->ni($acc));
        $this->assertTrue($r['ok'], json_encode($r['problems']));
        $this->assertSame(3450, $r['totals']['3849']);
    }

    public function test_suggestions_are_marked_by_confidence_and_never_invent_the_hard_ones(): void
    {
        $this->assertSame('1002', GifiRules::suggest(['type' => 'asset', 'sub_type' => 'bank', 'name' => 'Chequing Account'])['code']);
        $this->assertSame('9130', GifiRules::suggest(['type' => 'expense', 'sub_type' => 'safety', 'name' => 'PPE & Safety Supplies'])['code'], 'safety gear, not materials (8320)');
        $this->assertSame('8320', GifiRules::suggest(['type' => 'expense', 'sub_type' => 'materials', 'name' => 'Materials & Supplies'])['code']);
        $this->assertSame('2680', GifiRules::suggest(['type' => 'liability', 'sub_type' => 'tax_itc', 'name' => 'GST/HST Input Tax Credits'])['code']);
        $this->assertSame('2707', GifiRules::suggest(['type' => 'liability', 'sub_type' => 'credit_card', 'name' => 'Credit Card Payable'])['code']);
        $this->assertSame('3600', GifiRules::suggest(['type' => 'equity', 'sub_type' => 'retained', 'name' => 'Retained Earnings'])['code']);
        $this->assertSame('8340', GifiRules::suggest(['type' => 'expense', 'sub_type' => 'labour', 'name' => 'Labour — Crew Wages'])['code']);
        // Owner draws, opening-balance equity, loans of unknown term and headers: no guess.
        $this->assertNull(GifiRules::suggest(['type' => 'equity', 'sub_type' => 'draw', 'name' => "Owner's Draw"])['code']);
        $this->assertNull(GifiRules::suggest(['type' => 'equity', 'sub_type' => 'opening', 'name' => 'Opening Balance Equity'])['code']);
        $this->assertNull(GifiRules::suggest(['type' => 'liability', 'sub_type' => 'loan', 'name' => 'Loan Payable'])['code']);
        $this->assertNull(GifiRules::suggest(['type' => 'expense', 'sub_type' => 'cogs_header', 'name' => 'Cost of Services'])['code']);
        $this->assertSame('low', GifiRules::suggest(['type' => 'expense', 'sub_type' => 'misc', 'name' => 'Miscellaneous Expenses'])['confidence']);
    }

    public function test_sections_follow_rc4088_ranges(): void
    {
        $this->assertSame('asset', GifiRules::sectionOf('1002'));
        $this->assertSame('liability', GifiRules::sectionOf('2680'));
        $this->assertSame('total', GifiRules::sectionOf('3499'));
        $this->assertSame('retained', GifiRules::sectionOf('3600'));
        $this->assertSame('revenue', GifiRules::sectionOf('8000'));
        $this->assertSame('cost_of_sales', GifiRules::sectionOf('8320'));
        $this->assertSame('expense', GifiRules::sectionOf('9281'));
        $this->assertSame('tax', GifiRules::sectionOf('9990'));
        $this->assertNull(GifiRules::sectionOf('12'));
    }

    public function test_csv_rows_are_whole_dollars_and_carry_the_required_totals(): void
    {
        $acc = $this->accounts();
        $rows = GifiService::csvRows(GifiRules::build($acc, self::KNOWN, $this->ni($acc)));
        $codes = array_column(array_slice($rows, 1), 0);
        foreach (GifiRules::REQUIRED as $req) $this->assertContains($req, $codes);
        foreach (array_slice($rows, 1) as $r) $this->assertIsInt($r[1]);
    }
}
