<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * "Not registered for GST" (onboarding, 2026-11-06): the business charges no GST and claims no input tax credit.
 * A registered business — or one that never answered — must post exactly as before.
 */
class GstRegistrationTest extends TestCase
{
    private function db(?string $registered): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT, description TEXT)');
        $db->exec('CREATE TABLE business_settings (id INTEGER PRIMARY KEY, gst_rate REAL)');
        $db->exec('INSERT INTO business_settings VALUES (1, 5.00)');
        $db->exec('CREATE TABLE tax_rates (id INTEGER PRIMARY KEY, code TEXT, rate REAL, is_active INT)');
        if ($registered !== null) $db->exec("INSERT INTO ops_settings VALUES ('gst_registered', '$registered', '')");
        return $db;
    }

    private function sync(PDO $db): LedgerSyncService
    {
        return new LedgerSyncService($db, new LedgerService($db));
    }

    private const RECEIPT = ['id' => 4, 'expense_date' => '2026-03-03', 'total' => 105.00, 'gst_amount' => 5.00, 'pst_amount' => 0,
        'accounting_category' => 'Materials', 'payment_method' => 'credit_card'];

    private function lines(PDO $db): array
    {
        $sync = $this->sync($db);
        return (new LedgerService($db))->buildExpenseEntry($sync->mapExpenseToExpenseArgs(self::RECEIPT, ['materials' => '5200'], ['materials' => 2]))['lines'];
    }

    public function test_only_an_explicit_no_switches_gst_off(): void
    {
        $this->assertSame(0.0, TaxEngine::gstRate($this->db('no')));
        $this->assertFalse(TaxEngine::gstRegistered($this->db('no')));
        foreach (['yes', 'unsure', null] as $a) {
            $this->assertSame(0.05, TaxEngine::gstRate($this->db($a)), (string)$a);
            $this->assertTrue(TaxEngine::gstRegistered($this->db($a)));
        }
        $this->assertSame(0.05, TaxEngine::gstRate(new PDO('sqlite::memory:')), 'no tables at all: today\'s 5%');
        $this->assertSame(0.05, TaxEngine::configuredGstRate($this->db('no')), 'the rate on the paper stays');
    }

    public function test_a_non_registrant_charges_no_gst(): void
    {
        $t = (new TaxEngine($this->db('no')))->calculateFromSubtotal(200.00);
        $this->assertSame(0.0, $t['gst_amount']);
        $this->assertSame(200.00, $t['grand_total']);
        $g = (new TaxEngine($this->db('no')))->calculateFromGross(200.00);
        $this->assertSame(200.00, $g['subtotal']);
        $this->assertSame(0.0, $g['gst_amount']);
        $this->assertSame(10.00, (new TaxEngine($this->db('yes')))->calculateFromSubtotal(200.00)['gst_amount']);
        // contract_billing / the invoice screens: round($subtotal * $taxRate, 2) with the rate from gstRate()
        $this->assertSame(0.0, round(1500.00 * TaxEngine::gstRate($this->db('no')), 2));
    }

    public function test_a_non_registrant_receipt_posts_gst_into_the_cost_and_nothing_to_2210(): void
    {
        $lines = $this->lines($this->db('no'));
        $this->assertSame(['5200', '2400'], array_column($lines, 'account'), 'no 2210 line');
        $this->assertEqualsWithDelta(105.00, $lines[0]['debit'], 0.001);
        $this->assertEqualsWithDelta(105.00, $lines[1]['credit'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float)$lines[0]['gst_amount'], 0.001);
    }

    public function test_a_registered_receipt_posts_exactly_as_before(): void
    {
        $expected = [
            ['account' => '5200', 'debit' => 100.00, 'credit' => 0, 'gst_amount' => 5.00, 'pst_amount' => 0.0, 'cost_type_id' => 2, 'service_type' => null, 'job_id' => null, 'vendor_id' => null],
            ['account' => LedgerService::ACC_GST_ITC, 'debit' => 5.00, 'credit' => 0, 'gst_amount' => 5.00],
            ['account' => '2400', 'debit' => 0, 'credit' => 105.00, 'vendor_id' => null],
        ];
        foreach (['yes', 'unsure', null] as $a) {
            $db = $this->db($a);
            $this->assertSame($expected, $this->lines($db), (string)$a);
            $this->assertArrayNotHasKey('claim_itc', $this->sync($db)->mapExpenseToExpenseArgs(self::RECEIPT, ['materials' => '5200'], ['materials' => 2]), 'registered args are unchanged');
        }
    }

    public function test_a_non_registrant_split_receipt_and_bank_lines_claim_nothing(): void
    {
        $ledger = new LedgerService($this->db('no'));
        $e = $ledger->buildExpenseEntry(['id' => 9, 'date' => '2026-03-03', 'net' => 100.0, 'gst' => 5.0, 'pst' => 0.0, 'expense_account' => '5200', 'funding' => '2400', 'claim_itc' => false,
            'allocations' => [['account' => '5200', 'net' => 60.0, 'gst' => 3.0, 'pst' => 0.0], ['account' => '5300', 'net' => 40.0, 'gst' => 2.0, 'pst' => 0.0]]]);
        $this->assertNotContains(LedgerService::ACC_GST_ITC, array_column($e['lines'], 'account'));
        $this->assertEqualsWithDelta(array_sum(array_column($e['lines'], 'debit')), array_sum(array_column($e['lines'], 'credit')), 0.001);
        $this->assertEqualsWithDelta(105.0, array_sum(array_column($e['lines'], 'debit')), 0.001);

        $bank = ['id' => 51, 'transaction_date' => '2025-02-28', 'type' => 'expense', 'account_id' => 6100, 'account_type' => 'expense', 'account_code' => '6100',
            'bank_account_id' => 1, 'amount' => 105.00, 'gst_amount' => 5.00, 'pst_amount' => 0];
        $no = $this->sync($this->db('no'))->bankRowToEntryArgs($bank, 10, 11, 1, []);
        $this->assertSame([6100, 1], array_column($no['lines'], 'account_id'), 'no ITC line');
        $this->assertEqualsWithDelta(105.00, $no['lines'][0]['debit'], 0.001);
        $this->assertEqualsWithDelta(105.00, $no['lines'][1]['credit'], 0.001);
        $yes = $this->sync($this->db('yes'))->bankRowToEntryArgs($bank, 10, 11, 1, []);
        $this->assertSame([6100, 10, 1], array_column($yes['lines'], 'account_id'));
        $this->assertEqualsWithDelta(100.00, $yes['lines'][0]['debit'], 0.001);
        $refund = $this->sync($this->db('no'))->bankRowToEntryArgs(['amount' => -105.00, 'gst_amount' => 5.00, 'transaction_date' => '2026-09-03', 'type' => 'expense',
            'account_id' => 6100, 'bank_account_id' => 1, 'id' => 52, 'description' => 'REFUND'], 10, 11, 99, []);
        $this->assertSame([1, 6100], array_column($refund['lines'], 'account_id'));
        $this->assertEqualsWithDelta(105.00, $refund['lines'][1]['credit'], 0.001);
    }

    public function test_the_receipt_gst_check_still_reads_the_paper_rate(): void
    {
        $this->assertNull(ExpenseGate::taxCheck(['total' => 105.0, 'gst_amount' => 5.0], TaxEngine::configuredGstRate($this->db('no')))['level'], 'a 5% GST line is normal on a non-registrant\'s receipt');
    }

    public function test_charged_rate_is_zero_only_when_not_registered(): void
    {
        $this->assertSame(0.0, TaxEngine::chargedRate($this->db('no'), 0.05));
        foreach (['yes', 'unsure', null] as $a) $this->assertSame(0.05, TaxEngine::chargedRate($this->db($a), 0.05));
        $this->assertSame(0.0, TaxEngine::chargedRate($this->db('yes'), 0.0));
    }

    public function test_quote_totals_carry_no_tax_for_a_non_registrant(): void
    {
        $lines = [['line_total' => 100.00, 'tax_rate' => 5], ['line_total' => 50.00, 'tax_rate' => 5]];
        $no = (new QuoteService($this->db('no')))->calculateTotals($lines);
        $this->assertSame(150.0, (float)$no['subtotal']);
        $this->assertSame(0.0, (float)$no['tax_amount']);
        $this->assertSame(150.0, (float)$no['total']);
        $this->assertSame(0.0, (float)$no['tax_rate']);
        $yes = (new QuoteService($this->db('yes')))->calculateTotals($lines);
        $this->assertGreaterThan(0.0, (float)$yes['tax_amount'], 'registered: unchanged');
    }

    public function test_a_non_registrant_claims_no_itc_in_the_gst_report(): void
    {
        $db = $this->db('no');
        $this->assertSame([], (new GstReportService($db))->itcExpenses('2026-01-01', '2026-12-31'));
    }
}
