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
    public function decodeEbcdicStatement_returns_null_for_non_ebcdic(): void
    {
        $this->assertNull($this->callPrivate('decodeEbcdicStatement', "01/05  GROCERY  10.00  90.00\n"));
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
}
