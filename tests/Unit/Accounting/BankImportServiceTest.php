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
    public function getPresets_returns_all_six_banks(): void
    {
        $presets = $this->service->getPresets();
        $this->assertCount(6, $presets);
        $this->assertArrayHasKey('td', $presets);
        $this->assertArrayHasKey('rbc', $presets);
        $this->assertArrayHasKey('bmo', $presets);
        $this->assertArrayHasKey('cibc', $presets);
        $this->assertArrayHasKey('scotiabank', $presets);
        $this->assertArrayHasKey('generic', $presets);
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
}
