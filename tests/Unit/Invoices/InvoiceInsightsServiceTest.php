<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class InvoiceInsightsServiceTest extends TestCase
{
    public function testMoneyInByMonthAndPayDays(): void
    {
        $paid = [
            ['created_at' => '2026-10-01 09:00:00', 'paid_at' => '2026-10-11 09:00:00', 'total' => '100.00'],
            ['created_at' => '2026-10-05 09:00:00', 'paid_at' => '2026-10-25 09:00:00', 'total' => '50.50'],
            ['created_at' => '2026-11-01 09:00:00', 'paid_at' => '2026-11-04 09:00:00', 'total' => '200'],
            ['created_at' => '2025-01-01 09:00:00', 'paid_at' => '2025-01-05 09:00:00', 'total' => '999'],   // older than 12 months: ignored
        ];
        $s = InvoiceInsightsService::build($paid, [], '2026-11-20');
        $this->assertCount(12, $s['months']);
        $this->assertSame('Dec', $s['months'][0]['label']);
        $this->assertSame(['Nov', true, 200.0], [$s['months'][11]['label'], $s['months'][11]['now'], $s['months'][11]['total']]);
        $this->assertSame(150.5, $s['months'][10]['total']);
        $this->assertSame(350.5, $s['year_total']);
        $this->assertSame(200.0, $s['this_month']);
        $this->assertCount(6, $s['pay_days']);
        $this->assertSame(15.0, $s['pay_days'][4]['days']);   // Oct: (10 + 20) / 2
        $this->assertSame(3.0, $s['pay_days'][5]['days']);
        $this->assertNull($s['pay_days'][0]['days']);
        $this->assertSame(11.0, $s['avg_days']);   // (10 + 20 + 3) / 3
    }

    public function testOwedByHowLate(): void
    {
        $open = [
            ['due_date' => '2026-12-01', 'balance_due' => '100'],   // not due
            ['due_date' => null, 'balance_due' => '10'],            // no date: not due
            ['due_date' => '2026-11-20', 'balance_due' => '5'],     // due today: not late
            ['due_date' => '2026-11-10', 'balance_due' => '20'],    // 10 late
            ['due_date' => '2026-10-21', 'balance_due' => '30'],    // 30 late
            ['due_date' => '2026-10-20', 'balance_due' => '40'],    // 31 late
            ['due_date' => '2026-08-01', 'balance_due' => '70'],    // 61+
        ];
        $s = InvoiceInsightsService::build([], $open, '2026-11-20');
        $this->assertSame([3, 2, 1, 1], array_column($s['aging'], 'count'));
        $this->assertSame([115.0, 50.0, 40.0, 70.0], array_column($s['aging'], 'amount'));
        $this->assertSame(275.0, $s['owed_total']);
        $this->assertSame(7, $s['owed_count']);
        $this->assertTrue($s['has_data']);
    }

    public function testLateDaysAreCalendarDaysAcrossDaylightSaving(): void
    {
        $prev = date_default_timezone_get();
        date_default_timezone_set('America/Vancouver');
        try {
            $s = InvoiceInsightsService::build([], [['due_date' => '2026-02-06', 'balance_due' => '10']], '2026-03-09');   // 31 days late
            $this->assertSame([0, 0, 1, 0], array_column($s['aging'], 'count'));
        } finally {
            date_default_timezone_set($prev);
        }
    }

    public function testEmptyAndBrokenDatabases(): void
    {
        $s = InvoiceInsightsService::build([], [], '2026-11-20');
        $this->assertFalse($s['has_data']);
        $this->assertNull($s['avg_days']);
        $this->assertSame(0.0, $s['year_total']);
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->assertFalse(InvoiceInsightsService::snapshot($db, '2026-11-20')['has_data']);
        $db->exec("CREATE TABLE invoices (status TEXT, created_at TEXT, paid_at TEXT, total REAL, due_date TEXT, balance_due REAL)");
        $db->exec("INSERT INTO invoices VALUES ('paid','2026-11-01 00:00:00','2026-11-03 00:00:00',80,NULL,0), ('sent','2026-11-01 00:00:00',NULL,60,'2026-11-10',60), ('draft','2026-11-01 00:00:00',NULL,10,NULL,10)");
        $s = InvoiceInsightsService::snapshot($db, '2026-11-20');
        $this->assertSame([80.0, 60.0, 1], [$s['this_month'], $s['owed_total'], $s['owed_count']]);
    }
}
