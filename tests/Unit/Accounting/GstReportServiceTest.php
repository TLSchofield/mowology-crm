<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The one GST calculation: ITCs from approved or posted expenses only, and the
 * meals & entertainment limit (ETA s.236) as its own line.
 */
class GstReportServiceTest extends TestCase
{
    private function settings(): array
    {
        return GstReportService::parseSettings(null, null);
    }

    public function test_defaults_are_meals_at_fifty_percent(): void
    {
        $s = $this->settings();
        $this->assertSame(['Meals'], $s['meals_categories']);
        $this->assertSame(0.5, $s['meals_rate']);
    }

    public function test_settings_parse_and_reject_nonsense(): void
    {
        $s = GstReportService::parseSettings(' Meals , Entertainment ,', '0.75');
        $this->assertSame(['Meals', 'Entertainment'], $s['meals_categories']);
        $this->assertSame(0.75, $s['meals_rate']);
        $this->assertSame(0.5, GstReportService::parseSettings('', '1.5')['meals_rate']);
        $this->assertSame(0.5, GstReportService::parseSettings('', 'abc')['meals_rate']);
        $this->assertSame(['Meals'], GstReportService::parseSettings(' , ', '0.5')['meals_categories']);
    }

    public function test_is_meals_is_case_insensitive_and_exact(): void
    {
        $this->assertTrue(GstReportService::isMeals('meals', ['Meals']));
        $this->assertTrue(GstReportService::isMeals(' MEALS ', ['Meals']));
        $this->assertFalse(GstReportService::isMeals('Meals & Fuel', ['Meals']));
        $this->assertFalse(GstReportService::isMeals(null, ['Meals']));
        $this->assertFalse(GstReportService::isMeals('', ['Meals']));
    }

    public function test_meals_itc_is_limited_and_shown_as_its_own_line(): void
    {
        $inv = [['subtotal' => 1000, 'tax_amount' => 50], ['subtotal' => 200, 'tax_amount' => 10]];
        $exp = [
            ['id' => 1, 'gst_amount' => 20.00, 'category' => 'Fuel'],
            ['id' => 2, 'gst_amount' => 3.15,  'category' => 'Meals'],
            ['id' => 3, 'gst_amount' => 1.01,  'category' => 'meals'],
        ];
        $r = GstReportService::summarize($inv, $exp, $this->settings());
        $this->assertSame(1200.0, $r['line_101']);
        $this->assertSame(60.0, $r['line_103']);
        $this->assertSame(24.16, $r['itc_gross']);
        $this->assertSame(4.16, $r['meals_gst']);
        // 3.15 → 1.58 (round half up), 1.01 → 0.51: claimable 2.09, limited off 2.07
        $this->assertSame(2.07, $r['meals_limit']);
        $this->assertSame(22.09, $r['line_106']);
        $this->assertSame(37.91, $r['line_109']);
        $this->assertFalse($r['is_refund']);
        $this->assertSame('Meals ITC limited to 50%: −$2.07', $r['meals_limit_label']);
        $this->assertTrue($r['expenses'][1]['meals_limited']);
        $this->assertSame(1.58, $r['expenses'][1]['itc_claimable']);
        $this->assertSame(20.0, $r['expenses'][0]['itc_claimable']);
        $this->assertSame('Fuel', $r['itc_by_category'][0]['category']);
    }

    public function test_no_meals_means_no_limit_and_refund_flag(): void
    {
        $r = GstReportService::summarize([], [['gst_amount' => 12.5, 'category' => 'Materials']], $this->settings());
        $this->assertSame(0.0, $r['meals_limit']);
        $this->assertSame(12.5, $r['line_106']);
        $this->assertSame(-12.5, $r['line_109']);
        $this->assertTrue($r['is_refund']);
    }

    public function test_rate_setting_changes_the_limit(): void
    {
        $s = GstReportService::parseSettings('Meals', '1');   // accountant says full ITC
        $r = GstReportService::summarize([], [['gst_amount' => 10, 'category' => 'Meals']], $s);
        $this->assertSame(0.0, $r['meals_limit']);
        $this->assertSame(10.0, $r['line_106']);
    }

    public function test_period_range(): void
    {
        $this->assertSame(['2026-04-01', '2026-06-30', 'Q2 2026'], GstReportService::periodRange(2026, 2));
        $this->assertSame(['2025-01-01', '2025-12-31', 'Full Year 2025'], GstReportService::periodRange(2025, 0));
    }

    public function test_only_approved_or_posted_expenses_are_read(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, expense_date TEXT, description TEXT, vendor_id INT,
                   vendor_name_raw TEXT, amount REAL, gst_amount REAL, total REAL, status TEXT, accounting_category TEXT)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT)");
        $rows = [
            [1, 'approved', 5.0], [2, 'forwarded', 4.0], [3, 'draft', 100.0],
            [4, 'pending_approval', 50.0], [5, 'rejected', 7.0], [6, 'approved', 0.0],
        ];
        $st = $db->prepare("INSERT INTO expenses VALUES (?, '2026-05-10', 'x', NULL, 'Vendor', 10, ?, 10, ?, 'Materials')");
        foreach ($rows as [$id, $status, $gst]) $st->execute([$id, $gst, $status]);
        $db->exec("INSERT INTO expenses VALUES (7, '2026-07-01', 'x', NULL, 'Vendor', 10, 9, 10, 'approved', 'Materials')");   // out of range

        $svc = new GstReportService($db);
        $ids = array_map('intval', array_column($svc->itcExpenses('2026-04-01', '2026-06-30'), 'id'));
        $this->assertSame([1, 2], $ids);

        $db->exec("INSERT INTO ops_settings VALUES ('gst_meals_itc_categories', 'Meals,Entertainment'), ('gst_meals_itc_rate', '0.5')");
        $this->assertSame(['Meals', 'Entertainment'], $svc->settings()['meals_categories']);
    }

    public function test_what_changed_diff_is_new_minus_old(): void
    {
        $old = GstReportService::withNet(['line_101' => 100.0, 'line_103' => 5.0, 'line_106' => 8.0]);
        $this->assertSame(-3.0, $old['line_109']);
        $d = GstReportService::diffLines($old, ['line_101' => 100.0, 'line_103' => 5.0, 'line_106' => 2.0, 'line_109' => 3.0]);
        $this->assertSame(['line_101' => 0.0, 'line_103' => 0.0, 'line_106' => -6.0, 'line_109' => 6.0], $d);
        $this->assertNull(GstReportService::diffLines(['line_106' => null], ['line_106' => 1.0])['line_106']);
    }
}
