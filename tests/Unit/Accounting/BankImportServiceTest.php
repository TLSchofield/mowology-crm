<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for BankImportService pure logic methods.
 *
 * Covers: detectPreset(), getPresets(), parseNumber() (via reflection),
 *         parseDate() (via reflection), parseCSV() (via reflection).
 *
 * No database connection required — all DB calls are bypassed via PDO mock
 * or by testing private methods directly through ReflectionMethod.
 */
class BankImportServiceTest extends TestCase
{
    private BankImportService $service;
    private \ReflectionClass $ref;

    protected function setUp(): void
    {
        $pdo = $this->createMock(PDO::class);
        $this->service = new BankImportService($pdo);
        $this->ref = new \ReflectionClass(BankImportService::class);
    }

    // ── detectPreset() ────────────────────────────────────────────────────────

    /** Encode an ASCII string as the UTF-8-wrapped EBCDIC bytes smalot emits. */
    private function toEbcdicUtf8(string $ascii): string
    {
        $map = [' ' => 0x40, '.' => 0x4B, ',' => 0x6B, '(' => 0x4D, ')' => 0x5D];
        for ($i = 0; $i <= 8; $i++) $map[chr(ord('A') + $i)] = 0xC1 + $i;
        for ($i = 0; $i <= 8; $i++) $map[chr(ord('J') + $i)] = 0xD1 + $i;
        for ($i = 0; $i <= 7; $i++) $map[chr(ord('S') + $i)] = 0xE2 + $i;
        for ($i = 0; $i <= 9; $i++) $map[chr(ord('0') + $i)] = 0xF0 + $i;
        $out = '';
        foreach (str_split($ascii) as $c) {
            $out .= $c === "\n" ? "\n" : (isset($map[$c]) ? mb_chr($map[$c], 'UTF-8') : $c);
        }
        return $out;
    }

    private function callPrivate(string $method, ...$args)
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->service, ...$args);
    }

    // ── EBCDIC decode (Vancity 2026+ font) ──────────────────────────────────────

    /** @test */
    public function looksLikeEbcdic_detects_ebcdic_text(): void
    {
        $this->assertTrue($this->callPrivate('looksLikeEbcdic',
            $this->toEbcdicUtf8('MOWOLOGY LAWNS AND LANDSCAPES VANCOUVER BC 14,331.92 OPENING BALANCE')));
        $this->assertFalse($this->callPrivate('looksLikeEbcdic',
            'Plain ASCII bank statement text with a balance of 14,331.92 and more words here'));
    }

    /** @test */
    public function decodeEbcdicStatement_reflows_transactions(): void
    {
        // A minimal EBCDIC statement: opening balance + two date-triplets.
        $ascii = "OPENING BALANCE\n100.00\n"
               . "01MAY POS GROCERY\n10.00\n90.00\n"
               . "02MAY DEPOSIT\n40.00\n130.00\n";
        $decoded = $this->callPrivate('decodeEbcdicStatement', $this->toEbcdicUtf8($ascii));

        $this->assertNotNull($decoded);
        $this->assertStringContainsString('WITHDRAWALS DEPOSITS BALANCE', $decoded);
        $this->assertStringContainsString('OPENING BALANCE 100.00', $decoded);
        $this->assertStringContainsString('01 MAY POSGROCERY 10.00 90.00', $decoded);
        $this->assertStringContainsString('02 MAY DEPOSIT 40.00 130.00', $decoded);
    }

    /** @test */
    public function decodeEbcdicStatement_absorbs_wrapped_description_lines(): void
    {
        // The amount is not on the line immediately after the date — the description
        // wraps across two lines first (real Vancity layout for long merchant names).
        $ascii = "OPENING BALANCE\n100.00\n"
               . "01MAY POS SOUTHLANDS\nNURSERY VANCOUVER\n10.00\n90.00\n";
        $decoded = $this->callPrivate('decodeEbcdicStatement', $this->toEbcdicUtf8($ascii));

        $this->assertNotNull($decoded);
        // wrapped description joined, amount + balance captured (not dropped)
        $this->assertStringContainsString('01 MAY POSSOUTHLANDSNURSERYVANCOUVER 10.00 90.00', $decoded);
    }

    /** @test */
    public function decodeEbcdicStatement_returns_null_for_non_ebcdic(): void
    {
        $this->assertNull($this->callPrivate('decodeEbcdicStatement', "01/05  GROCERY  10.00  90.00\n"));
    }

    // ── parsePdfText: year-boundary correction ──────────────────────────────────

    /** @test */
    public function parsePdfText_corrects_year_across_dec_jan_rollover(): void
    {
        $text = "STATEMENT PERIOD 2025 2026\nWITHDRAWALS DEPOSITS BALANCE\nOPENING BALANCE 1000.00\n"
              . "30 DEC POS GROCERY 100.00 900.00\n"
              . "02 JAN DEPOSIT 200.00 1100.00\n";
        $rows = $this->callPrivate('parsePdfText', $text, false)['rows'];

        $this->assertCount(2, $rows);
        $this->assertSame('2025-12-30', $rows[0]['date']); // December → prior year
        $this->assertSame('2026-01-02', $rows[1]['date']); // January → statement year
    }

    /** @test */
    public function parsePdfText_keeps_single_year_when_no_rollover(): void
    {
        $text = "STATEMENT PERIOD 2024\nWITHDRAWALS DEPOSITS BALANCE\nOPENING BALANCE 1000.00\n"
              . "01 JUN POS A 50.00 950.00\n"
              . "15 JUN POS B 50.00 900.00\n";
        $rows = $this->callPrivate('parsePdfText', $text, false)['rows'];
        $this->assertSame('2024-06-01', $rows[0]['date']);
        $this->assertSame('2024-06-15', $rows[1]['date']);
    }

    // ── parsePdfText: balance-delta classification (#3) ─────────────────────────

    /** @test */
    public function parsePdfText_classifies_by_balance_delta_when_running_balance_present(): void
    {
        // No "WITHDRAWALS DEPOSITS BALANCE" header → not detected as 3-column, but a
        // running-balance column is present; classification must follow the delta.
        $text = "STATEMENT PERIOD 2026\n"
              . "01 MAY POS A 50.00 950.00\n"   // baseline (sets running balance)
              . "02 MAY DEPOSIT B 100.00 1050.00\n"  // balance up → income
              . "03 MAY POS C 200.00 850.00\n";  // balance down → expense
        $rows = $this->callPrivate('parsePdfText', $text, false)['rows'];

        $byDate = [];
        foreach ($rows as $r) { $byDate[$r['date']] = $r['type']; }
        $this->assertSame('income',  $byDate['2026-05-02']);
        $this->assertSame('expense', $byDate['2026-05-03']);
    }

    // ── detectPreset() ────────────────────────────────────────────────────────

    /** @test */
    public function detectPreset_td_filename(): void
    {
        $this->assertSame('td', $this->service->detectPreset('td_statement_2024_jan.csv'));
    }

    /** @test */
    public function detectPreset_rbc_filename(): void
    {
        $this->assertSame('rbc', $this->service->detectPreset('RBC_Account_Summary.csv'));
    }

    /** @test */
    public function detectPreset_bmo_filename(): void
    {
        $this->assertSame('bmo', $this->service->detectPreset('bmo-banking-2024-03.csv'));
    }

    /** @test */
    public function detectPreset_scotiabank_filename(): void
    {
        $this->assertSame('scotiabank', $this->service->detectPreset('scotiabank_transactions.csv'));
    }

    /** @test */
    public function detectPreset_unknown_falls_back_to_generic(): void
    {
        $this->assertSame('generic', $this->service->detectPreset('my_bank_export.csv'));
        $this->assertSame('generic', $this->service->detectPreset('transactions.csv'));
    }

    /** @test */
    public function getPresets_includes_banks_and_credit_cards(): void
    {
        $presets = $this->service->getPresets();
        // Banks
        $this->assertArrayHasKey('td', $presets);
        $this->assertArrayHasKey('rbc', $presets);
        $this->assertArrayHasKey('bmo', $presets);
        $this->assertArrayHasKey('cibc', $presets);
        $this->assertArrayHasKey('scotiabank', $presets);
        $this->assertArrayHasKey('vancity', $presets);
        $this->assertArrayHasKey('generic', $presets);
        // Credit cards
        $this->assertArrayHasKey('td_cc', $presets);
        $this->assertArrayHasKey('vancity_cc', $presets);
        $this->assertArrayHasKey('generic_cc', $presets);
    }

    // ── parseNumber() (private) ───────────────────────────────────────────────

    private function parseNumber(string $input): ?float
    {
        $m = $this->ref->getMethod('parseNumber');
        return $m->invoke($this->service, $input);
    }

    /** @test */
    public function parseNumber_plain_decimal(): void
    {
        $this->assertEqualsWithDelta(100.00, $this->parseNumber('100.00'), 0.001);
        $this->assertEqualsWithDelta(0.99, $this->parseNumber('0.99'), 0.001);
    }

    /** @test */
    public function parseNumber_with_dollar_sign_and_commas(): void
    {
        $this->assertEqualsWithDelta(1234.56, $this->parseNumber('$1,234.56'), 0.001);
        $this->assertEqualsWithDelta(10000.00, $this->parseNumber('$10,000.00'), 0.001);
    }

    /** @test */
    public function parseNumber_parentheses_is_negative(): void
    {
        $this->assertEqualsWithDelta(-50.00, $this->parseNumber('(50.00)'), 0.001);
        $this->assertEqualsWithDelta(-1234.56, $this->parseNumber('($1,234.56)'), 0.001);
    }

    /** @test */
    public function parseNumber_explicit_negative(): void
    {
        $this->assertEqualsWithDelta(-25.00, $this->parseNumber('-25.00'), 0.001);
    }

    /** @test */
    public function parseNumber_empty_strings_return_null(): void
    {
        $this->assertNull($this->parseNumber(''));
        $this->assertNull($this->parseNumber('-'));
        $this->assertNull($this->parseNumber('—'));
        $this->assertNull($this->parseNumber('   '));
    }

    /** @test */
    public function parseNumber_non_numeric_returns_null(): void
    {
        $this->assertNull($this->parseNumber('N/A'));
        $this->assertNull($this->parseNumber('abc'));
    }

    // ── parseDate() (private) ─────────────────────────────────────────────────

    private function parseDate(string $input): ?string
    {
        $m = $this->ref->getMethod('parseDate');
        return $m->invoke($this->service, $input);
    }

    /** @test */
    public function parseDate_iso_format(): void
    {
        $this->assertSame('2024-01-15', $this->parseDate('2024-01-15'));
    }

    /** @test */
    public function parseDate_us_slash_format(): void
    {
        $this->assertSame('2024-01-15', $this->parseDate('01/15/2024'));
    }

    /** @test */
    public function parseDate_month_day_year_text(): void
    {
        $result = $this->parseDate('Jan 15, 2024');
        $this->assertSame('2024-01-15', $result);
    }

    /** @test */
    public function parseDate_empty_returns_null(): void
    {
        $this->assertNull($this->parseDate(''));
        $this->assertNull($this->parseDate('   '));
    }

    // ── parseCSV() (private) ──────────────────────────────────────────────────

    private function parseCSV(string $content, array $mapping, int $skipRows = 1): array
    {
        $m = $this->ref->getMethod('parseCSV');
        return $m->invoke($this->service, $content, $mapping, $skipRows);
    }

    /** @test */
    public function parseCSV_single_amount_positive_is_income(): void
    {
        $csv = "Date,Description,Amount\n2024-01-15,Invoice Payment,500.00";
        $rows = $this->parseCSV($csv, ['date' => 0, 'description' => 1, 'amount' => 2]);

        $this->assertCount(1, $rows);
        $this->assertSame('income', $rows[0]['type']);
        $this->assertEqualsWithDelta(500.00, $rows[0]['amount'], 0.001);
        $this->assertSame('2024-01-15', $rows[0]['date']);
        $this->assertSame('Invoice Payment', $rows[0]['description']);
    }

    /** @test */
    public function parseCSV_single_amount_negative_is_expense(): void
    {
        $csv = "Date,Description,Amount\n2024-01-16,Shell Gas Station,-65.42";
        $rows = $this->parseCSV($csv, ['date' => 0, 'description' => 1, 'amount' => 2]);

        $this->assertCount(1, $rows);
        $this->assertSame('expense', $rows[0]['type']);
        $this->assertEqualsWithDelta(65.42, $rows[0]['amount'], 0.001);
    }

    /** @test */
    public function parseCSV_debit_credit_columns(): void
    {
        $csv = "Date,Desc,Debit,Credit\n2024-01-15,Home Depot,120.00,\n2024-01-16,Client Payment,,800.00";
        $rows = $this->parseCSV($csv, ['date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3]);

        $this->assertCount(2, $rows);
        $this->assertSame('expense', $rows[0]['type']);
        $this->assertEqualsWithDelta(120.00, $rows[0]['amount'], 0.001);
        $this->assertSame('income', $rows[1]['type']);
        $this->assertEqualsWithDelta(800.00, $rows[1]['amount'], 0.001);
    }

    /** @test */
    public function parseCSV_skips_header_rows(): void
    {
        // 2 header rows
        $csv = "Bank Statement\nDate,Description,Amount\n2024-01-15,Test,100.00";
        $rows = $this->parseCSV($csv, ['date' => 0, 'description' => 1, 'amount' => 2], 2);
        $this->assertCount(1, $rows);
    }

    /** @test */
    public function parseCSV_skips_zero_amount_rows(): void
    {
        $csv = "Date,Description,Amount\n2024-01-15,Zero Row,0.00\n2024-01-16,Real Row,50.00";
        $rows = $this->parseCSV($csv, ['date' => 0, 'description' => 1, 'amount' => 2]);
        $this->assertCount(1, $rows);
        $this->assertSame('Real Row', $rows[0]['description']);
    }

    /** @test */
    public function parseCSV_row_result_has_required_keys(): void
    {
        $csv = "Date,Description,Amount\n2024-01-15,Test,100.00";
        $rows = $this->parseCSV($csv, ['date' => 0, 'description' => 1, 'amount' => 2]);

        $this->assertArrayHasKey('date', $rows[0]);
        $this->assertArrayHasKey('description', $rows[0]);
        $this->assertArrayHasKey('amount', $rows[0]);
        $this->assertArrayHasKey('type', $rows[0]);
        $this->assertArrayHasKey('account_id', $rows[0]);
        $this->assertArrayHasKey('is_duplicate', $rows[0]);
        $this->assertFalse($rows[0]['is_duplicate']);
        $this->assertNull($rows[0]['account_id']);
    }

    // ── getPresetKind() ───────────────────────────────────────────────────────

    /** @test */
    public function getPresetKind_bank_presets_are_bank(): void
    {
        $this->assertSame('bank', $this->service->getPresetKind('td'));
        $this->assertSame('bank', $this->service->getPresetKind('vancity'));
        $this->assertSame('bank', $this->service->getPresetKind('generic'));
    }

    /** @test */
    public function getPresetKind_credit_card_presets_are_credit_card(): void
    {
        $this->assertSame('credit_card', $this->service->getPresetKind('td_cc'));
        $this->assertSame('credit_card', $this->service->getPresetKind('vancity_cc'));
        $this->assertSame('credit_card', $this->service->getPresetKind('generic_cc'));
    }

    /** @test */
    public function getPresetKind_unknown_defaults_to_bank(): void
    {
        $this->assertSame('bank', $this->service->getPresetKind('auto'));
        $this->assertSame('bank', $this->service->getPresetKind('nonsense'));
    }

    // ── detectStatementType() ─────────────────────────────────────────────────

    /** @test */
    public function detectStatementType_vancity_credit_card(): void
    {
        $header = 'Card Number,Transaction Date,Posted Date,Description,Amount';
        $this->assertSame('vancity_cc', $this->service->detectStatementType($header, 'vancity_visa_jan.csv'));
    }

    /** @test */
    public function detectStatementType_vancity_bank(): void
    {
        $header = 'Date,Description,Withdrawal,Deposit,Balance';
        $this->assertSame('vancity', $this->service->detectStatementType($header, 'vancity_chequing.csv'));
    }

    /** @test */
    public function detectStatementType_td_credit_card(): void
    {
        $header = 'Transaction Date,Description,Visa Card,Amount';
        $this->assertSame('td_cc', $this->service->detectStatementType($header, 'TD_VISA_statement.csv'));
    }

    /** @test */
    public function detectStatementType_td_bank(): void
    {
        $header = 'Date,Description,Withdrawal,Deposit,Balance';
        $this->assertSame('td', $this->service->detectStatementType($header, 'td_chequing.csv'));
    }

    /**
     * Regression: Vancity's downloadable statement export ("Date,Description,
     * Debits,Credits,Balance") carries no institution name in the header or
     * filename. Before this fix it fell through to the 'generic' preset
     * (single signed amount column), which misread the Debits column as a
     * positive amount and flipped every expense row to income.
     * @test
     */
    public function detectStatementType_unbranded_debit_credit_export_uses_generic_dc(): void
    {
        $header = 'Date,Description,Debits,Credits,Balance';
        $this->assertSame('generic_dc', $this->service->detectStatementType($header, 'Account_Statement_6801_05-Aug-2026.csv'));

        $preset = $this->service->getPreset('generic_dc');
        $this->assertSame('bank', $preset['kind']);
        $this->assertSame(2, $preset['debit']);
        $this->assertSame(3, $preset['credit']);
    }

    /** @test */
    public function detectStatementType_unknown_returns_empty(): void
    {
        $this->assertSame('', $this->service->detectStatementType('foo,bar,baz', 'mystery.csv'));
    }

    // ── isCreditCardPayment() (private) ───────────────────────────────────────

    private function isCreditCardPayment(string $desc): bool
    {
        $m = $this->ref->getMethod('isCreditCardPayment');
        return (bool)$m->invoke($this->service, $desc);
    }

    /** @test */
    public function isCreditCardPayment_matches_payment_thank_you(): void
    {
        $this->assertTrue($this->isCreditCardPayment('PAYMENT - THANK YOU'));
        $this->assertTrue($this->isCreditCardPayment('PAYMENT RECEIVED THANK YOU'));
    }

    /** @test */
    public function isCreditCardPayment_matches_visa_payment(): void
    {
        $this->assertTrue($this->isCreditCardPayment('VISA PAYMENT VANCITY'));
        $this->assertTrue($this->isCreditCardPayment('PAYMENT TO TD VISA'));
        $this->assertTrue($this->isCreditCardPayment('PRE-AUTHORIZED PAYMENT'));
    }

    /** @test */
    public function isCreditCardPayment_ignores_normal_purchases(): void
    {
        $this->assertFalse($this->isCreditCardPayment('SHELL GAS STATION'));
        $this->assertFalse($this->isCreditCardPayment('HOME DEPOT #7012'));
        $this->assertFalse($this->isCreditCardPayment('VISA — JUST A MERCHANT NAME'));
    }

    // ── parseCSV() credit-card routing ────────────────────────────────────────

    private function parseCcCSV(string $content, array $mapping, int $skipRows = 1): array
    {
        $m = $this->ref->getMethod('parseCSV');
        return $m->invoke($this->service, $content, $mapping, $skipRows, 'credit_card');
    }

    /** @test */
    public function parseCSV_cc_charge_is_positive_expense(): void
    {
        // TD single-amount CC: charges positive.
        $csv = "Date,Description,Amount\n2024-01-15,Shell Gas,65.42";
        $rows = $this->parseCcCSV($csv, ['date' => 0, 'description' => 1, 'amount' => 2]);

        $this->assertCount(1, $rows);
        $this->assertSame('expense', $rows[0]['type']);
        $this->assertSame('charge', $rows[0]['cc_role']);
        $this->assertEqualsWithDelta(65.42, $rows[0]['amount'], 0.001);
    }

    /** @test */
    public function parseCSV_cc_refund_is_negative_expense(): void
    {
        // TD single-amount CC: payments/credits negative; a non-payment credit is a refund.
        $csv = "Date,Description,Amount\n2024-01-20,HOME DEPOT REFUND,-30.00";
        $rows = $this->parseCcCSV($csv, ['date' => 0, 'description' => 1, 'amount' => 2]);

        $this->assertCount(1, $rows);
        $this->assertSame('expense', $rows[0]['type']);
        $this->assertSame('refund', $rows[0]['cc_role']);
        $this->assertEqualsWithDelta(-30.00, $rows[0]['amount'], 0.001);
    }

    /** @test */
    public function parseCSV_cc_payment_is_flagged_settlement(): void
    {
        $csv = "Date,Description,Amount\n2024-01-25,PAYMENT - THANK YOU,-500.00";
        $rows = $this->parseCcCSV($csv, ['date' => 0, 'description' => 1, 'amount' => 2]);

        $this->assertCount(1, $rows);
        $this->assertSame('payment', $rows[0]['cc_role']);
        $this->assertTrue($rows[0]['cc_payment']);
        $this->assertEqualsWithDelta(500.00, $rows[0]['amount'], 0.001);
    }

    /** @test */
    public function parseCSV_cc_debit_credit_charge_and_payment(): void
    {
        // Debit column = charge, Credit column = payment/refund.
        $csv = "Date,Desc,Debit,Credit\n2024-01-15,Lowes,120.00,\n2024-01-25,VISA PAYMENT,,500.00";
        $rows = $this->parseCcCSV($csv, ['date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3]);

        $this->assertCount(2, $rows);
        $this->assertSame('charge', $rows[0]['cc_role']);
        $this->assertEqualsWithDelta(120.00, $rows[0]['amount'], 0.001);
        $this->assertSame('payment', $rows[1]['cc_role']);
        $this->assertTrue($rows[1]['cc_payment']);
    }

    // ── applyCcSettlement() (private) ─────────────────────────────────────────

    private function applyCcSettlement(array $row, int $ccPayableId, int $defaultExpenseId): array
    {
        $m = $this->ref->getMethod('applyCcSettlement');
        $handled = $m->invokeArgs($this->service, [&$row, $ccPayableId, $defaultExpenseId]);
        return ['handled' => $handled, 'row' => $row];
    }

    /** @test */
    public function applyCcSettlement_bank_debit_cc_payment_becomes_transfer(): void
    {
        $row = ['type' => 'expense', 'description' => 'VANCITY VISA PAYMENT', 'amount' => 500.0];
        $out = $this->applyCcSettlement($row, 42, 99);

        $this->assertTrue($out['handled']);
        $this->assertSame('transfer', $out['row']['type']);
        $this->assertSame(42, $out['row']['account_id']);
        $this->assertSame('2400', $out['row']['account_code']);
        $this->assertTrue($out['row']['cc_payment']);
        $this->assertStringContainsString('reconcile', strtolower($out['row']['cc_note']));
    }

    /** @test */
    public function applyCcSettlement_falls_back_to_expense_when_no_cc_account(): void
    {
        $row = ['type' => 'expense', 'description' => 'VISA PAYMENT', 'amount' => 500.0];
        $out = $this->applyCcSettlement($row, 0, 99);

        $this->assertTrue($out['handled']);
        $this->assertSame('expense', $out['row']['type']);
        $this->assertSame(99, $out['row']['account_id']);
    }

    /** @test */
    public function applyCcSettlement_ignores_normal_expense(): void
    {
        $row = ['type' => 'expense', 'description' => 'SHELL GAS STATION', 'amount' => 60.0];
        $out = $this->applyCcSettlement($row, 42, 99);

        $this->assertFalse($out['handled']);
        $this->assertSame('expense', $out['row']['type']);
    }

    // ── Manual reconciliation: scoreExpenseTransactionPair() ────────────────────
    //
    // Candidate scoring is pure (no DB) and unit-tested directly. The transactional
    // attach()/detach() write paths follow this codebase's existing convention for
    // side-effecting reconciliation services (see InvoiceReconciliationServiceTest's
    // header comment) — verified on production per the feature's plan, not mocked
    // here, since a faithful PDO-mock of a multi-step guarded UPDATE adds test
    // complexity without adding real coverage over what the guards themselves need.

    private function expenseRow(array $overrides = []): array
    {
        return array_merge([
            'expense_date'    => '2026-07-10',
            'total'           => 84.50,
            'vendor_name_raw' => 'Home Depot',
        ], $overrides);
    }

    private function transactionRow(array $overrides = []): array
    {
        return array_merge([
            'transaction_date' => '2026-07-10',
            'description'      => 'HOME DEPOT #1234 VANCOUVER',
            'amount'           => 84.50,
        ], $overrides);
    }

    /** @test */
    public function scoreExpenseTransactionPair_exact_amount_same_day_vendor_match_scores_high(): void
    {
        $score = $this->callPrivate('scoreExpenseTransactionPair', $this->expenseRow(), $this->transactionRow());

        $this->assertNotNull($score);
        $this->assertSame(90, $score['confidence']); // 50 amount + 20 same-day + 20 vendor
        $this->assertContains('Exact amount match', $score['reasons']);
        $this->assertContains('Same day', $score['reasons']);
        $this->assertContains('Vendor name matches statement description', $score['reasons']);
    }

    /** @test */
    public function scoreExpenseTransactionPair_close_amount_within_tolerance_scores_lower(): void
    {
        // $0.75 off on an $84.50 total is within max(0.5, 2%) but not exact.
        $score = $this->callPrivate('scoreExpenseTransactionPair', $this->expenseRow(), $this->transactionRow(['amount' => 85.25]));

        $this->assertNotNull($score);
        $this->assertContains('Amount close (within 2%)', $score['reasons']);
        $this->assertLessThan(90, $score['confidence']);
    }

    /** @test */
    public function scoreExpenseTransactionPair_unrelated_amount_returns_null(): void
    {
        $score = $this->callPrivate('scoreExpenseTransactionPair', $this->expenseRow(), $this->transactionRow(['amount' => 250.00]));
        $this->assertNull($score);
    }

    /** @test */
    public function scoreExpenseTransactionPair_beyond_match_window_returns_null(): void
    {
        // 15 days apart — findExpenseMatch()'s auto-match window is ±3d, this
        // manual path widens to ±14d, but still has to draw a line somewhere.
        $score = $this->callPrivate('scoreExpenseTransactionPair', $this->expenseRow(), $this->transactionRow(['transaction_date' => '2026-07-25']));
        $this->assertNull($score);
    }

    /** @test */
    public function scoreExpenseTransactionPair_no_vendor_overlap_still_scores_on_amount_and_date(): void
    {
        $score = $this->callPrivate('scoreExpenseTransactionPair', $this->expenseRow(), $this->transactionRow(['description' => 'POS PURCHASE 5591']));

        $this->assertNotNull($score);
        $this->assertNotContains('Vendor name matches statement description', $score['reasons']);
        $this->assertSame(70, $score['confidence']); // 50 amount + 20 same-day, no vendor bonus
    }

    // ── Manual reconciliation: candidateTransactionsForExpense() ────────────────

    /** Build a service whose expense lookup and transaction-candidate queries return canned rows. */
    private function bankImportServiceWith(?array $expenseRow, array $transactionRows): BankImportService
    {
        $expenseStmt = $this->createMock(PDOStatement::class);
        $expenseStmt->method('execute')->willReturn(true);
        $expenseStmt->method('fetch')->willReturn($expenseRow ?: false);

        $txStmt = $this->createMock(PDOStatement::class);
        $txStmt->method('execute')->willReturn(true);
        $txStmt->method('fetchAll')->willReturn($transactionRows);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($expenseStmt, $txStmt) {
                return strpos($sql, 'FROM accounting_transactions at') !== false ? $txStmt : $expenseStmt;
            }
        );

        return new BankImportService($pdo);
    }

    /** @test */
    public function candidateTransactionsForExpense_ranks_high_confidence_first(): void
    {
        $svc = $this->bankImportServiceWith(
            ['id' => 1, 'expense_date' => '2026-07-10', 'total' => 84.50, 'vendor_name_raw' => 'Home Depot'],
            [
                ['id' => 201, 'transaction_date' => '2026-07-15', 'description' => 'POS PURCHASE', 'amount' => 85.00, 'bank_account' => 'Vancity'],
                ['id' => 202, 'transaction_date' => '2026-07-10', 'description' => 'HOME DEPOT #1234', 'amount' => 84.50, 'bank_account' => 'Vancity'],
            ]
        );

        $candidates = $svc->candidateTransactionsForExpense(1);

        $this->assertCount(2, $candidates);
        $this->assertSame(202, $candidates[0]['transaction_id']); // exact amount + same day + vendor wins
        $this->assertGreaterThan($candidates[1]['confidence'], $candidates[0]['confidence']);
    }

    /** @test */
    public function candidateTransactionsForExpense_returns_empty_for_unknown_expense(): void
    {
        $svc = $this->bankImportServiceWith(null, []);
        $this->assertSame([], $svc->candidateTransactionsForExpense(999));
    }

    // ── checkTrueDuplicate() ─────────────────────────────────────────────────

    /**
     * A transaction with reference_type='bank_import' must be detected as a
     * duplicate even when it has no corresponding bank_import_rows entry —
     * e.g. historical imports created outside the standard commit path.
     * Regression test: the query used to require a bank_import_rows JOIN,
     * which silently missed real duplicates when that audit row was absent.
     */
    public function test_checkTrueDuplicate_matches_without_bank_import_rows_entry(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')
            ->with(['expense', 23.61, '2026-07-31']);
        $stmt->method('fetch')->willReturn(['id' => 555]);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')
            ->with($this->callback(function (string $sql) {
                return strpos($sql, 'bank_import_rows') === false
                    && strpos($sql, 'FROM accounting_transactions') !== false
                    && strpos($sql, "reference_type = 'bank_import'") !== false;
            }))
            ->willReturn($stmt);

        $svc = new BankImportService($pdo);
        $m = $this->ref->getMethod('checkTrueDuplicate');
        $m->setAccessible(true);
        $result = $m->invoke($svc, '2026-07-31', 23.61, 'expense');

        $this->assertSame(555, $result);
    }

    public function test_checkTrueDuplicate_returns_null_when_no_match(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $svc = new BankImportService($pdo);
        $m = $this->ref->getMethod('checkTrueDuplicate');
        $m->setAccessible(true);
        $result = $m->invoke($svc, '2026-08-05', 66.15, 'income');

        $this->assertNull($result);
    }

    /**
     * Regression: a prior import of this exact row may have already been
     * reclassified from 'income' to 'transfer' — either auto-matched to an
     * invoice (see the "Book the bank deposit as a cash-clearing TRANSFER"
     * branch in enrichRows/previewImport) or a credit-card settlement. Checking
     * only `type = ?` (the incoming row's own type) missed every such row on
     * re-import, since the earlier copy no longer carries that type — a
     * duplicate silently got re-created instead of skipped. Real case: a July
     * statement re-imported over an already-covered period created 22
     * duplicate rows this way. The query must also match type='transfer'.
     */
    public function test_checkTrueDuplicate_matches_row_already_reclassified_to_transfer(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')
            ->with(['income', 63.68, '2026-07-19']);
        $stmt->method('fetch')->willReturn(['id' => 24440]);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')
            ->with($this->callback(function (string $sql) {
                return strpos($sql, "type IN (?, 'transfer')") !== false;
            }))
            ->willReturn($stmt);

        $svc = new BankImportService($pdo);
        $m = $this->ref->getMethod('checkTrueDuplicate');
        $m->setAccessible(true);
        $result = $m->invoke($svc, '2026-07-19', 63.68, 'income');

        $this->assertSame(24440, $result);
    }

    // ── removeDuplicateRow() ─────────────────────────────────────────────────
    // Guard clauses only — the happy-path DELETE transaction is exercised on
    // production (same convention as attach()/detach() elsewhere in this
    // module). Each guard re-verifies against the DB rather than trusting a
    // caller-supplied "this is safe" flag from an earlier read.

    /** Build a PDO mock whose prepare() dispatches by SQL substring. */
    private function pdoDispatching(array $handlers): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($handlers) {
            foreach ($handlers as $needle => $makeStmt) {
                if (str_contains($sql, $needle)) {
                    return $makeStmt();
                }
            }
            $this->fail('Unexpected query: ' . $sql);
        });
        return $pdo;
    }

    private function stmtReturning($fetchValue, string $fetchMethod = 'fetch'): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method($fetchMethod)->willReturn($fetchValue);
        return $stmt;
    }

    public function test_removeDuplicateRow_throws_when_row_not_found(): void
    {
        $pdo = $this->pdoDispatching([
            'FROM bank_import_rows bir' => fn() => $this->stmtReturning(false),
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Row not found');
        (new BankImportService($pdo))->removeDuplicateRow(999, 1);
    }

    public function test_removeDuplicateRow_refuses_when_no_sibling_remains(): void
    {
        $row = ['id'=>1,'session_id'=>35,'transaction_id'=>100,'transaction_date'=>'2026-07-19','amount'=>63.68,'description'=>'x','tx_type'=>'transfer'];
        $pdo = $this->pdoDispatching([
            'FROM bank_import_rows bir' => fn() => $this->stmtReturning($row),
            'ORDER BY id'                => fn() => $this->stmtReturning([1], 'fetchAll'),
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only record');
        (new BankImportService($pdo))->removeDuplicateRow(1, 1);
    }

    public function test_removeDuplicateRow_refuses_non_transfer_type_when_unverified(): void
    {
        // A row still type='income' with no bank_statement_verifications
        // record may represent real, unrecognized money — must never be
        // silently deleted. Only type='transfer', or a verified-excess row,
        // is safe to remove.
        $row = ['id'=>2,'session_id'=>35,'transaction_id'=>100,'transaction_date'=>'2026-07-19','amount'=>63.68,'description'=>'x','tx_type'=>'income'];
        $pdo = $this->pdoDispatching([
            'FROM bank_import_rows bir'            => fn() => $this->stmtReturning($row),
            'ORDER BY id'                          => fn() => $this->stmtReturning([1, 2], 'fetchAll'),
            'FROM bank_statement_verifications'    => fn() => $this->stmtReturning(false, 'fetchColumn'),
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not type=transfer');
        (new BankImportService($pdo))->removeDuplicateRow(2, 1);
    }

    public function test_removeDuplicateRow_refuses_when_verified_real_count_is_zero(): void
    {
        // A verification record with real_count=0 means NO real transaction
        // matches this (date, amount) at all — a parsing artifact, not a
        // duplicate. Must never be auto-deleted, needs a human to figure out
        // what it actually is.
        $row = ['id'=>2,'session_id'=>35,'transaction_id'=>100,'transaction_date'=>'2026-03-18','amount'=>7774.70,'description'=>'x','tx_type'=>'income'];
        $pdo = $this->pdoDispatching([
            'FROM bank_import_rows bir'            => fn() => $this->stmtReturning($row),
            'ORDER BY id'                          => fn() => $this->stmtReturning([1, 2], 'fetchAll'),
            'FROM bank_statement_verifications'    => fn() => $this->stmtReturning(0, 'fetchColumn'),
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('zero matches');
        (new BankImportService($pdo))->removeDuplicateRow(2, 1);
    }

    public function test_removeDuplicateRow_allows_verified_excess_even_when_type_is_income(): void
    {
        // The real case this exists for: Dorset Realty Group sent 2 real
        // $409.92 payments on the same day, but 6 copies ended up in the DB
        // (re-imported 3x). Verified real_count=2 means ids [59,60] (the two
        // oldest) are kept, and every later id — still type='income', never
        // reclassified to transfer — is legitimately removable.
        $row = ['id'=>172,'session_id'=>35,'transaction_id'=>500,'transaction_date'=>'2026-03-19','amount'=>409.92,'description'=>'x','tx_type'=>'income'];
        $deleteRowsStmt = $this->stmtReturning(null);
        $deleteTxStmt   = $this->stmtReturning(null);
        $pdo = $this->pdoDispatching([
            'FROM bank_import_rows bir'            => fn() => $this->stmtReturning($row),
            'ORDER BY id'                          => fn() => $this->stmtReturning([59, 60, 171, 172, 286, 287], 'fetchAll'),
            'FROM bank_statement_verifications'    => fn() => $this->stmtReturning(2, 'fetchColumn'),
            'FROM invoice_payment_allocations'     => fn() => $this->stmtReturning(0, 'fetchColumn'),
            'FROM etransfer_notifications'         => fn() => $this->stmtReturning(0, 'fetchColumn'),
            'DELETE FROM bank_import_rows'         => fn() => $deleteRowsStmt,
            'DELETE FROM accounting_transactions'  => fn() => $deleteTxStmt,
        ]);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);

        $result = (new BankImportService($pdo))->removeDuplicateRow(172, 1);

        $this->assertSame(172, $result['removed_row_id']);
        $this->assertSame(500, $result['removed_tx_id']);
    }

    public function test_removeDuplicateRow_refuses_verified_keep_row_even_though_amount_matches(): void
    {
        // id=59 is among the two oldest (the ones being KEPT per real_count=2)
        // — must refuse even though the (date,amount) is verified, since this
        // specific row is not excess.
        $row = ['id'=>59,'session_id'=>34,'transaction_id'=>400,'transaction_date'=>'2026-03-19','amount'=>409.92,'description'=>'x','tx_type'=>'income'];
        $pdo = $this->pdoDispatching([
            'FROM bank_import_rows bir'            => fn() => $this->stmtReturning($row),
            'ORDER BY id'                          => fn() => $this->stmtReturning([59, 60, 171, 172, 286, 287], 'fetchAll'),
            'FROM bank_statement_verifications'    => fn() => $this->stmtReturning(2, 'fetchColumn'),
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not type=transfer');
        (new BankImportService($pdo))->removeDuplicateRow(59, 1);
    }

    public function test_removeDuplicateRow_refuses_when_allocation_exists(): void
    {
        $row = ['id'=>1,'session_id'=>35,'transaction_id'=>100,'transaction_date'=>'2026-07-19','amount'=>63.68,'description'=>'x','tx_type'=>'transfer'];
        $pdo = $this->pdoDispatching([
            'FROM bank_import_rows bir'            => fn() => $this->stmtReturning($row),
            'ORDER BY id'                          => fn() => $this->stmtReturning([1, 2], 'fetchAll'),
            'FROM bank_statement_verifications'    => fn() => $this->stmtReturning(false, 'fetchColumn'),
            'FROM invoice_payment_allocations'     => fn() => $this->stmtReturning(1, 'fetchColumn'),
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('payment allocation');
        (new BankImportService($pdo))->removeDuplicateRow(1, 1);
    }

    public function test_removeDuplicateRow_refuses_when_etransfer_notification_references_it(): void
    {
        $row = ['id'=>1,'session_id'=>35,'transaction_id'=>100,'transaction_date'=>'2026-07-19','amount'=>63.68,'description'=>'x','tx_type'=>'transfer'];
        $pdo = $this->pdoDispatching([
            'FROM bank_import_rows bir'            => fn() => $this->stmtReturning($row),
            'ORDER BY id'                          => fn() => $this->stmtReturning([1, 2], 'fetchAll'),
            'FROM bank_statement_verifications'    => fn() => $this->stmtReturning(false, 'fetchColumn'),
            'FROM invoice_payment_allocations'     => fn() => $this->stmtReturning(0, 'fetchColumn'),
            'FROM etransfer_notifications'         => fn() => $this->stmtReturning(1, 'fetchColumn'),
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pending e-Transfer notification');
        (new BankImportService($pdo))->removeDuplicateRow(1, 1);
    }

    // ── parseVerificationCsv() ───────────────────────────────────────────────

    public function test_parseVerificationCsv_counts_debits_and_credits(): void
    {
        $csv = "Date,Description,Debits,Credits,Balance\n"
             . "19-Mar-2026,Preauthorized credit DORSET REALTY GROUP,,409.92,100.00\n"
             . "19-Mar-2026,Preauthorized credit DORSET REALTY GROUP,,409.92,509.92\n"
             . "20-Mar-2026,Point of sale SHELL,20.00,,489.92\n";

        $svc = new BankImportService($this->createMock(PDO::class));
        $counts = $svc->parseVerificationCsv($csv);

        $byKey = [];
        foreach ($counts as $c) { $byKey[$c['date'] . '|' . $c['amount']] = $c['count']; }

        $this->assertSame(2, $byKey['2026-03-19|409.92'] ?? null);
        $this->assertSame(1, $byKey['2026-03-20|20'] ?? null);
    }

    public function test_parseVerificationCsv_ignores_header_and_blank_lines(): void
    {
        $csv = "Date,Description,Debits,Credits,Balance\n\n01-Jan-2026,Test,5.00,,10.00\n\n";
        $svc = new BankImportService($this->createMock(PDO::class));
        $counts = $svc->parseVerificationCsv($csv);
        $this->assertCount(1, $counts);
        $this->assertSame('2026-01-01', $counts[0]['date']);
        $this->assertSame(5.0, $counts[0]['amount']);
    }

    public function test_parseVerificationCsv_skips_unparseable_rows(): void
    {
        $csv = "Date,Description,Debits,Credits,Balance\n"
             . "not-a-date,Junk,1.00,,1.00\n"
             . "01-Jan-2026,OK row,2.00,,2.00\n";
        $svc = new BankImportService($this->createMock(PDO::class));
        $counts = $svc->parseVerificationCsv($csv);
        $this->assertCount(1, $counts);
        $this->assertSame(2.0, $counts[0]['amount']);
    }
}
