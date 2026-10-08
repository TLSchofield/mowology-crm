<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's look-back rules (backlog item 7): each rule family on a fixture from Tim's books.
 */
class LookbackRulesTest extends TestCase
{
    private const CODES = [
        '1010' => ['id' => 1, 'name' => 'Chequing', 'type' => 'asset'],
        '1020' => ['id' => 2, 'name' => 'Savings Account', 'type' => 'asset'],
        '1025' => ['id' => 3, 'name' => 'GST Reserves', 'type' => 'asset'],
        '1320' => ['id' => 4, 'name' => 'Income Tax Instalments Paid', 'type' => 'asset'],
        '2215' => ['id' => 5, 'name' => 'GST/HST Instalments Paid', 'type' => 'liability'],
        '2250' => ['id' => 6, 'name' => 'Income Tax Payable', 'type' => 'liability'],
        '2400' => ['id' => 7, 'name' => 'Credit Card Payable', 'type' => 'liability'],
        '2610' => ['id' => 8, 'name' => 'Loan Payable — RAM 3500HD', 'type' => 'liability'],
        '5100' => ['id' => 9, 'name' => 'Labour — Crew Wages', 'type' => 'expense'],
        '6100' => ['id' => 10, 'name' => 'Fuel & Vehicle', 'type' => 'expense'],
        '6130' => ['id' => 11, 'name' => 'Vehicle Loan', 'type' => 'expense'],
        '6700' => ['id' => 12, 'name' => 'Utilities & Phone', 'type' => 'expense'],
        '6900' => ['id' => 13, 'name' => 'Miscellaneous', 'type' => 'expense'],
    ];

    private static function receipt(array $over): array
    {
        return $over + ['id' => 1, 'expense_date' => '2026-05-10', 'total' => 0, 'gst_amount' => 0, 'pst_amount' => 0, 'amount' => 0,
                        'accounting_category' => 'Materials', 'asset_tag' => null, 'vendor_name' => '', 'vendor_name_raw' => '', 'description' => '',
                        'lines' => [], 'ocr_text' => '', 'has_split' => false, 'job_id' => null];
    }

    private static function kinds(array $props): array
    {
        return array_column($props, 'kind');
    }

    private static function one(array $props, string $kind): array
    {
        foreach ($props as $p) if ($p['kind'] === $kind) return $p;
        self::fail("no {$kind} proposal in: " . implode(', ', self::kinds($props)));
    }

    // ── Split by line ─────────────────────────────────────────────────────

    public function test_lawnboy_seed_for_stock_and_mulch_for_the_job_is_proposed_as_a_split(): void
    {
        $e = self::receipt(['id' => 412, 'vendor_name' => 'LAWNBOY', 'total' => 218.40, 'gst_amount' => 10.00, 'pst_amount' => 8.40, 'amount' => 200,
                            'job_id' => 501, 'lines' => [
                                ['id' => 229, 'name' => 'Richardson Sun & Shade Lawn Seed 5 kg', 'line_total' => 120],
                                ['id' => 230, 'name' => 'Black Composted Bark Mulch', 'line_total' => 80],
                            ]]);
        $lessons = [
            ExpenseSplitService::lineKey('Richardson Sun & Shade Lawn Seed 5 kg') => ['accounting_category' => 'Materials', 'asset_tag' => null, 'is_stock' => 1, 'to_job' => 0, 'times_seen' => 1],
            ExpenseSplitService::lineKey('Black Composted Bark Mulch') => ['accounting_category' => 'Materials', 'asset_tag' => null, 'is_stock' => 0, 'to_job' => 1, 'times_seen' => 1],
        ];
        $p = self::one(LookbackRules::receipt($e, ['lessons' => $lessons]), 'split');
        $by = array_column($p['after']['allocations'], null, 'line_item_id');
        $this->assertTrue($by[229]['is_stock'], 'seed → shop stock');
        $this->assertNull($by[229]['job_id']);
        $this->assertSame(501, $by[230]['job_id'], 'mulch → the Oakridge job');
        $this->assertSame(85, $p['confidence'], 'learned from Tim\'s own split');
        $this->assertSame(['allocations' => []], $p['before']);
    }

    public function test_diesel_and_a_coffee_is_a_split_not_a_category_change(): void
    {
        $e = self::receipt(['vendor_name' => 'Chevron', 'total' => 104.50, 'gst_amount' => 4.98, 'amount' => 99.52, 'accounting_category' => 'Fuel', 'asset_tag' => 'truck',
                            'ocr_text' => 'CHEVRON DIESEL 60.2 L', 'lines' => [['id' => 1, 'name' => 'Diesel', 'line_total' => 95.00], ['id' => 2, 'name' => 'Coffee', 'line_total' => 4.52]]]);
        $props = LookbackRules::receipt($e, []);
        $p = self::one($props, 'split');
        $this->assertNotContains('fuel_rule', self::kinds($props));
        $cats = array_column($p['after']['allocations'], 'accounting_category');
        $this->assertSame(['Fuel', 'Meals'], $cats);
        $this->assertLessThan(0, $p['gst'], 'the coffee\'s GST is only half claimable');
    }

    // ── Tim's fuel rules ──────────────────────────────────────────────────

    public function test_diesel_filed_as_materials_goes_to_fuel_for_the_truck(): void
    {
        $e = self::receipt(['vendor_name' => 'Esso', 'total' => 120.00, 'gst_amount' => 5.71, 'amount' => 114.29, 'ocr_text' => 'ESSO DIESEL 70 L']);
        $p = self::one(LookbackRules::receipt($e), 'fuel_rule');
        $this->assertSame(['accounting_category' => 'Fuel', 'asset_tag' => 'truck'], $p['after']);
        $this->assertSame(90, $p['confidence']);
    }

    public function test_small_gas_fill_is_for_the_equipment(): void
    {
        $e = self::receipt(['vendor_name' => 'Shell', 'total' => 32.00, 'gst_amount' => 1.52, 'amount' => 30.48, 'accounting_category' => 'Fuel', 'asset_tag' => 'truck',
                            'ocr_text' => 'SHELL REGULAR UNLEADED 18 L']);
        $p = self::one(LookbackRules::receipt($e), 'fuel_rule');
        $this->assertSame(['asset_tag' => 'equipment'], $p['after']);
    }

    // ── GST ───────────────────────────────────────────────────────────────

    public function test_landfill_fees_carry_no_gst(): void
    {
        $e = self::receipt(['vendor_name' => 'Vancouver Landfill', 'accounting_category' => 'Disposal/Dump', 'total' => 52.50, 'gst_amount' => 2.50, 'amount' => 50.00]);
        $p = self::one(LookbackRules::receipt($e), 'gst_exempt');
        $this->assertSame(['gst_amount' => 0.0, 'amount' => 52.5], $p['after']);
        $this->assertSame(-2.5, $p['gst']);
        $this->assertSame(88, $p['confidence']);
    }

    public function test_translink_fare_has_no_gst_and_none_is_fine(): void
    {
        $with = self::receipt(['vendor_name' => 'TransLink Compass', 'accounting_category' => 'Overhead', 'total' => 3.35, 'gst_amount' => 0.16, 'amount' => 3.19]);
        $this->assertContains('gst_exempt', self::kinds(LookbackRules::receipt($with)));
        $without = self::receipt(['vendor_name' => 'TransLink Compass', 'accounting_category' => 'Overhead', 'total' => 3.35, 'gst_amount' => 0, 'amount' => 3.35]);
        $this->assertNotContains('gst_exempt', self::kinds(LookbackRules::receipt($without)));
        $this->assertNotContains('gst_missing', self::kinds(LookbackRules::receipt($without, ['vendor_gst_share' => 1.0])));
    }

    public function test_gst_not_five_percent_and_printed_on_the_receipt(): void
    {
        $e = self::receipt(['vendor_name' => 'Rona', 'total' => 107.00, 'gst_amount' => 7.00, 'amount' => 100.00, 'ocr_text' => "SUBTOTAL 101.90\nGST 5.10\nTOTAL 107.00"]);
        $p = self::one(LookbackRules::receipt($e), 'gst_rate');
        $this->assertEqualsWithDelta(5.0, $p['after']['gst_amount'], 0.001);
        // 100 × 5% = 5.00 isn't printed → low confidence (a GST-free line can explain it)
        $this->assertSame(45, $p['confidence']);
    }

    public function test_missing_gst_when_the_vendor_always_charges_it_and_it_is_printed(): void
    {
        $e = self::receipt(['vendor_name' => 'Home Depot', 'total' => 105.00, 'gst_amount' => 0, 'amount' => 105.00, 'ocr_text' => 'GST 5.00']);
        $p = self::one(LookbackRules::receipt($e, ['vendor_gst_share' => 0.95]), 'gst_missing');
        $this->assertSame(5.0, $p['after']['gst_amount']);
        $this->assertSame(100.0, $p['after']['amount']);
        $this->assertSame(5.0, $p['gst']);
    }

    // ── Personal ──────────────────────────────────────────────────────────

    public function test_gym_and_netflix_are_proposed_as_owner_draw_never_more(): void
    {
        foreach (['GoodLife Fitness' => 'a gym / fitness membership', 'NETFLIX.COM' => 'a streaming subscription'] as $vendor => $what) {
            $e = self::receipt(['vendor_name' => $vendor, 'accounting_category' => 'Overhead', 'total' => 21.00, 'gst_amount' => 1.00, 'amount' => 20.00]);
            $props = LookbackRules::receipt($e);
            $this->assertSame(['personal'], self::kinds($props), $vendor . ': nothing else is proposed for a personal receipt');
            $p = $props[0];
            $this->assertStringContainsString($what, $p['title']);
            $this->assertSame('Personal', $p['after']['accounting_category']);
            $this->assertSame(0.0, $p['after']['gst_amount']);
            $this->assertSame(-1.0, $p['gst']);
            $this->assertLessThan(LookbackRules::BULK_CONFIDENCE, $p['confidence'], 'Tim decides each one');
        }
    }

    public function test_grocery_store_is_personal_only_when_the_basket_is_groceries(): void
    {
        $water = self::receipt(['vendor_name' => 'Save-On-Foods', 'accounting_category' => 'Meals', 'total' => 8, 'lines' => [['id' => 1, 'name' => 'Ice bag', 'line_total' => 8]]]);
        $this->assertNotContains('personal', self::kinds(LookbackRules::receipt($water)));
        $basket = self::receipt(['vendor_name' => 'Save-On-Foods', 'accounting_category' => 'Meals', 'total' => 30, 'lines' => [['id' => 1, 'name' => 'Milk 2L', 'line_total' => 6], ['id' => 2, 'name' => 'Bread', 'line_total' => 4]]]);
        $this->assertContains('personal', self::kinds(LookbackRules::receipt($basket)));
    }

    // ── Vendor memory, meals ──────────────────────────────────────────────

    public function test_vendor_usually_filed_elsewhere(): void
    {
        $e = self::receipt(['vendor_name' => 'Northwest Landscape Supply', 'accounting_category' => 'Other', 'total' => 300, 'gst_amount' => 14.29, 'amount' => 285.71]);
        $p = self::one(LookbackRules::receipt($e, ['vendor_history' => ['materials' => 9, 'tools/equipment' => 1]]), 'vendor_category');
        $this->assertSame('Materials', $p['after']['accounting_category']);
        $this->assertSame(85, $p['confidence']);
        $this->assertSame('learned', $p['source']);
    }

    public function test_all_food_goes_to_meals_with_half_the_gst(): void
    {
        $e = self::receipt(['vendor_name' => 'Tim Hortons', 'accounting_category' => 'Overhead', 'total' => 10.50, 'gst_amount' => 0.50, 'amount' => 10.00,
                            'lines' => [['id' => 1, 'name' => 'Coffee', 'line_total' => 3], ['id' => 2, 'name' => 'Breakfast sandwich', 'line_total' => 7]]]);
        $p = self::one(LookbackRules::receipt($e), 'meals_category');
        $this->assertSame('Meals', $p['after']['accounting_category']);
        $this->assertSame(-0.25, $p['gst']);
    }

    // ── Duplicates ────────────────────────────────────────────────────────

    public function test_same_ticket_number_twice_is_a_duplicate_and_the_later_copy_goes(): void
    {
        $rows = [
            ['id' => 10, 'expense_date' => '2026-06-03', 'total' => 64.20, 'gst_amount' => 3.06, 'vendor_key' => 'vancouver landfill', 'receipt_media_id' => 1, 'status' => 'approved',
             'facts' => ['doc_number' => '43176009', 'doc_kind' => 'ticket']],
            ['id' => 11, 'expense_date' => '2026-06-04', 'total' => 64.20, 'gst_amount' => 3.06, 'vendor_key' => 'vancouver landfill', 'receipt_media_id' => 2, 'status' => 'forwarded',
             'facts' => ['doc_number' => '43176009', 'doc_kind' => 'ticket']],
        ];
        $d = LookbackRules::duplicates($rows);
        $this->assertCount(1, $d);
        $this->assertSame(11, $d[0]['subject_id']);
        $this->assertSame('cancelled', $d[0]['after']['status']);
        $this->assertSame(95, $d[0]['confidence']);
        $this->assertSame(-3.06, $d[0]['gst']);

        $rows[1]['facts']['doc_number'] = '43176011';
        $this->assertSame([], LookbackRules::duplicates($rows), 'different ticket numbers: two real dump runs');
        $rows[1]['facts']['doc_number'] = '43176009';
        $this->assertSame([], LookbackRules::duplicates($rows, ['10:11' => true]), 'Tim said they are not duplicates');
    }

    // ── Bank lines ────────────────────────────────────────────────────────

    private static function tx(array $over): array
    {
        return $over + ['id' => 1, 'transaction_date' => '2026-05-01', 'type' => 'expense', 'amount' => 0, 'gst_amount' => 0, 'description' => '',
                        'code' => '6900', 'money_in' => false, 'linked' => false, 'key' => ''];
    }

    public function test_wave_payroll_is_information_only(): void
    {
        $p = LookbackRules::bankLine(self::tx(['description' => 'WAVE PYRL 0012345', 'amount' => 1830.22, 'code' => '6900']), self::CODES);
        $this->assertSame('payroll', $p['kind']);
        $this->assertSame([], $p['after']);
        $lines = [self::tx(['id' => 5, 'description' => 'WAVE PYRL 1', 'key' => 'WAVE PYRL', 'code' => '6900'])];
        $this->assertSame(['proposals' => [], 'ask' => []], LookbackRules::payees($lines, self::CODES), 'never sent to Claude or filed');
    }

    public function test_td_loan_payment_goes_to_2610(): void
    {
        $p = LookbackRules::bankLine(self::tx(['description' => 'TD ON-LINE LOANS 4455', 'amount' => 363.08, 'code' => '6130']), self::CODES);
        $this->assertSame('loan', $p['kind']);
        $this->assertSame(['account' => '2610'], $p['after']);
        $this->assertNull(LookbackRules::bankLine(self::tx(['description' => 'TD ON-LINE LOANS 4455', 'amount' => 363.08, 'code' => '2610']), self::CODES), 'already there');
    }

    public function test_card_payment_and_savings_are_transfers(): void
    {
        $this->assertSame('2400', LookbackRules::bankLine(self::tx(['description' => 'TD VISA PREAUTH PYMT', 'amount' => 900]), self::CODES)['after']['account']);
        $this->assertSame('1025', LookbackRules::bankLine(self::tx(['description' => 'TFR-TO GST RESERVES', 'amount' => 500]), self::CODES)['after']['account']);
        $this->assertSame('1020', LookbackRules::bankLine(self::tx(['description' => 'TFR-TO 6827 SAVINGS', 'amount' => 500]), self::CODES)['after']['account']);
        $this->assertNull(LookbackRules::bankLine(self::tx(['description' => 'TD VISA PREAUTH PYMT', 'amount' => 900, 'money_in' => true]), self::CODES), 'money in is not moved here');
    }

    public function test_cra_payments_by_the_accountants_amounts_never_an_expense(): void
    {
        $gst = LookbackRules::bankLine(self::tx(['description' => 'GOVT CANADA TAX PAYMENT', 'amount' => 3500, 'transaction_date' => '2026-07-15']), self::CODES);
        $this->assertSame(['cra_tax', '2215'], [$gst['kind'], $gst['after']['account']]);
        $bal = LookbackRules::bankLine(self::tx(['description' => 'CRA BUSINESS PAYMENT', 'amount' => 10454, 'transaction_date' => '2026-06-28']), self::CODES);
        $this->assertSame('2250', $bal['after']['account']);
        $inst = LookbackRules::bankLine(self::tx(['description' => 'RECEIVER GENERAL', 'amount' => 3690, 'transaction_date' => '2026-06-29']), self::CODES);
        $this->assertSame('1320', $inst['after']['account']);
        $this->assertSame('1320', LookbackRules::bankLine(self::tx(['description' => 'CRA', 'amount' => 875, 'transaction_date' => '2026-08-31']), self::CODES)['after']['account']);
        $early = LookbackRules::bankLine(self::tx(['description' => 'CRA', 'amount' => 875, 'transaction_date' => '2026-03-31']), self::CODES);
        $this->assertSame(['cra_other', []], [$early['kind'], $early['after']], 'an $875 before July is not an instalment — listed, not filed');
        $lines = [self::tx(['id' => 9, 'description' => 'CRA BUSINESS PAYMENT', 'key' => 'CRA BUSINESS PAYMENT', 'code' => '6900'])];
        $this->assertSame([], LookbackRules::payees($lines, self::CODES)['ask'], 'never asked about as an expense');
    }

    public function test_same_payee_filed_different_ways_and_default_account_lines(): void
    {
        $lines = [];
        foreach ([1, 2, 3, 4] as $i) $lines[] = self::tx(['id' => $i, 'description' => 'TELUS MOBILITY ' . $i, 'key' => 'TELUS MOBILITY', 'code' => '6700', 'amount' => 95]);
        $lines[] = self::tx(['id' => 5, 'description' => 'TELUS MOBILITY 5', 'key' => 'TELUS MOBILITY', 'code' => '6100', 'amount' => 95]);
        $lines[] = self::tx(['id' => 6, 'description' => 'TELUS MOBILITY 6', 'key' => 'TELUS MOBILITY', 'code' => '6900', 'amount' => 95]);
        $lines[] = self::tx(['id' => 7, 'description' => 'MYSTERY CO', 'key' => 'MYSTERY CO', 'code' => '6900', 'amount' => 12]);
        $r = LookbackRules::payees($lines, self::CODES);
        $by = array_column($r['proposals'], null, 'subject_id');
        $this->assertSame('payee_consistency', $by[5]['kind']);
        $this->assertSame('6700', $by[5]['after']['account']);
        $this->assertSame('default_account', $by[6]['kind']);
        $this->assertSame(['MYSTERY CO' => [7]], $r['ask'], 'no history: asked once per payee');

        $r2 = LookbackRules::payees([$lines[6]], self::CODES, ['MYSTERY CO' => '6700']);
        $this->assertSame('learned', $r2['proposals'][0]['source'], 'Penny\'s confirmed bank rule decides it for free');
    }

    // ── Journal ───────────────────────────────────────────────────────────

    public function test_journal_checks(): void
    {
        $this->assertTrue(LookbackRules::balanced([['debit' => 10, 'credit' => 0], ['debit' => 0, 'credit' => 10]]));
        $this->assertFalse(LookbackRules::balanced([['debit' => 10, 'credit' => 0], ['debit' => 0, 'credit' => 9.99]]));

        $d = LookbackRules::doubles([
            ['id' => 5, 'source_type' => 'expense', 'source_id' => 412, 'entry_date' => '2026-10-07'],
            ['id' => 9, 'source_type' => 'expense', 'source_id' => 412, 'entry_date' => '2026-10-07'],
            ['id' => 7, 'source_type' => 'adjusting', 'source_id' => 5, 'entry_date' => '2026-10-07'],
        ]);
        $this->assertSame([['entry_id' => 5, 'keep' => 9, 'source_type' => 'expense', 'source_id' => 412, 'date' => '2026-10-07']], $d);

        $posted = [['account_id' => 10, 'debit' => 95.24, 'credit' => 0], ['account_id' => 2, 'debit' => 4.76, 'credit' => 0], ['account_id' => 7, 'debit' => 0, 'credit' => 100]];
        $this->assertNull(LookbackRules::compareEntry($posted, $posted, 2));
        $moved = $posted; $moved[0]['account_id'] = 13;
        $this->assertSame('journal_account', LookbackRules::compareEntry($posted, $moved, 2));
        $noGst = [['account_id' => 10, 'debit' => 100, 'credit' => 0], ['account_id' => 7, 'debit' => 0, 'credit' => 100]];
        $this->assertSame('journal_gst', LookbackRules::compareEntry($posted, $noGst, 2));
        $more = [['account_id' => 10, 'debit' => 105.24, 'credit' => 0], ['account_id' => 2, 'debit' => 4.76, 'credit' => 0], ['account_id' => 7, 'debit' => 0, 'credit' => 110]];
        $this->assertSame('journal_amount', LookbackRules::compareEntry($posted, $more, 2));
    }

    // ── GST per quarter ───────────────────────────────────────────────────

    public function test_a_filed_quarter_says_adjust_on_the_next_return(): void
    {
        $q = LookbackRules::gstByQuarter([
            ['date' => '2026-02-10', 'gst' => -2.50, 'status' => 'open'],
            ['date' => '2026-03-01', 'gst' => -1.00, 'status' => 'applied'],
            ['date' => '2026-05-01', 'gst' => 5.00, 'status' => 'open'],
        ], [['period_from' => '2026-01-01', 'period_to' => '2026-03-31', 'filed_on' => '2026-04-28']]);
        $this->assertSame(-2.5, $q[0]['open']);
        $this->assertTrue($q[0]['filed']);
        $this->assertStringContainsString('adjust ITCs by $-3.50 on your next return', $q[0]['say']);
        $this->assertFalse($q[1]['filed']);
        $this->assertSame('', $q[1]['say']);
        $this->assertSame(5.0, $q[1]['open']);
    }

    public function test_signature_ignores_number_formatting(): void
    {
        $this->assertSame(LookbackRules::signature(['gst_amount' => 2.5, 'amount' => 50]), LookbackRules::signature(['amount' => 50.0, 'gst_amount' => '2.50' + 0]));
        $this->assertNotSame(LookbackRules::signature(['account' => '6900']), LookbackRules::signature(['account' => '6700']));
    }
}
