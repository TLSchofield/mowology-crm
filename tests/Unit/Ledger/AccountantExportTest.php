<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class AccountantExportTest extends TestCase
{
    public function test_periods_for_a_calendar_year_end(): void
    {
        $t = '2026-11-28';
        $this->assertSame(['2026-11-01', '2026-11-30'], AccountantExport::period('this_month', $t));
        $this->assertSame(['2026-10-01', '2026-10-31'], AccountantExport::period('last_month', $t));
        $this->assertSame(['2026-01-01', '2026-11-28'], AccountantExport::period('this_year', $t));
        $this->assertSame(['2025-01-01', '2025-12-31'], AccountantExport::period('last_year', $t));
        $this->assertNull(AccountantExport::period('whenever', $t));
    }

    public function test_periods_follow_a_june_year_end(): void
    {
        $this->assertSame(['2025-07-01', '2026-06-30'], AccountantExport::period('last_year', '2026-11-28', '06-30'));
        $this->assertSame(['2026-07-01', '2026-11-28'], AccountantExport::period('this_year', '2026-11-28', '06-30'));
    }

    public function test_january_last_month_crosses_the_year(): void
    {
        $this->assertSame(['2025-12-01', '2025-12-31'], AccountantExport::period('last_month', '2026-01-15'));
    }

    private function line(array $o = []): array
    {
        return $o + ['id' => 5, 'entry_date' => '2026-03-02', 'display_kind' => 'System', 'source_type' => 'expense', 'source_id' => 42, 'memo' => 'Receipt',
                     'code' => '6100', 'account_name' => 'Supplies', 'gifi_code' => '8811', 'debit' => '80.50', 'credit' => '0.00', 'description' => 'Soil',
                     'created_by_name' => null, 'proposed_by' => null, 'gst_amount' => '3.83', 'pst_amount' => '0', 'vendor_name' => 'Garden Co'];
    }

    public function test_a_receipt_line_carries_gst_vendor_and_receipt_ref(): void
    {
        $r = AccountantExport::lineRow($this->line());
        $this->assertCount(count(AccountantExport::COLUMNS), $r);
        $this->assertSame([80.5, null, 'Soil', 'system', 3.83, null, 'Garden Co', 'R42'], array_slice($r, 8));
        $this->assertSame('expense 42', $r[3]);
    }

    public function test_other_lines_leave_vendor_and_receipt_ref_blank(): void
    {
        $r = AccountantExport::lineRow($this->line(['source_type' => 'bank_import', 'vendor_name' => 'Should not show', 'gst_amount' => '0']));
        $this->assertNull($r[12]);
        $this->assertNull($r[14]);
        $this->assertNull($r[15]);
    }

    public function test_the_columns_an_accountant_asks_for_are_there(): void
    {
        $labels = array_column(AccountantExport::COLUMNS, 0);
        foreach (['Date', 'Account', 'Line description', 'Debit', 'Credit', 'GST', 'Vendor', 'Receipt ref'] as $want) $this->assertContains($want, $labels);
    }
}
