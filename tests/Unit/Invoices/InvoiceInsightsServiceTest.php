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

    public function testCashLineBelowAndAboveFloor(): void
    {
        $base = ['floor' => 2000, 'opening_as_of' => '2026-11-20', 'band' => ['pct' => 20, 'thin' => false], 'weeks' => [
            ['start' => '2026-11-23', 'closing' => 3000, 'closing_low' => 2600, 'closing_high' => 3400],
            ['start' => '2026-11-30', 'closing' => 1500, 'closing_low' => 1100, 'closing_high' => 1900],
        ]];
        $below = InvoiceInsightsService::cashLine($base, '2026-11-20');
        $this->assertSame(['start' => '2026-11-30', 'closing' => 1500.0], $below['low']);
        $this->assertTrue($below['below_floor']);
        $this->assertSame('Cash dips to $1,500 the week of Nov 30 — under your $2,000 floor', $below['headline']);

        $base['weeks'][1]['closing'] = 2500;
        $base['band']['thin'] = true;
        $above = InvoiceInsightsService::cashLine($base, '2026-11-20');
        $this->assertFalse($above['below_floor']);
        $this->assertTrue($above['thin']);
        $this->assertSame('Stays above your $2,000 floor; lowest is $2,500 the week of Nov 30', $above['headline']);
        $this->assertNull(InvoiceInsightsService::cashLine(null, '2026-11-20'));
        $this->assertNull(InvoiceInsightsService::cashLine(['weeks' => []], '2026-11-20'));
        unset($base['opening_as_of']);
        $this->assertNull(InvoiceInsightsService::cashLine($base, '2026-11-20'));   // no fresh bank balance
    }

    public function testHoldersGroupSortAndCountLateMoney(): void
    {
        $h = InvoiceInsightsService::holders([
            ['who' => 'Acme', 'amount' => 100, 'date' => '2026-12-03', 'late' => false],
            ['who' => 'Acme', 'amount' => 50, 'date' => '2026-11-28', 'late' => true],
            ['who' => 'Beta', 'amount' => 80, 'date' => '2026-12-10', 'late' => true],
            ['who' => 'Gamma', 'amount' => 20, 'date' => '2026-12-01', 'late' => false],
        ], '2026-11-20', 2);
        $this->assertSame(['Acme', 'Beta'], array_column($h['rows'], 'who'));
        $this->assertSame([150.0, 80.0], array_column($h['rows'], 'amount'));
        $this->assertSame([2, 1], array_column($h['rows'], 'count'));
        $this->assertSame('Nov 28', $h['rows'][0]['expect']);
        $this->assertSame([130.0, 2, 130.0, 250.0], [$h['late_total'], $h['late_clients'], $h['late_top_total'], $h['total']]);
        $this->assertSame('$130.00 is late, across 2 clients', $h['headline']);
    }

    public function testHoldersHeadlineWhenNothingIsLate(): void
    {
        $h = InvoiceInsightsService::holders([['who' => 'Acme', 'amount' => 75, 'date' => '2026-12-03', 'late' => false]], '2026-11-20');
        $this->assertSame('Nothing late — $75.00 due in over the next weeks', $h['headline']);
    }

    public function testYearOverYearHeadlinesUpDownAndWithoutPriorYear(): void
    {
        $current = ['created_at' => '2026-11-01', 'paid_at' => '2026-11-10', 'total' => 112];
        $prior = ['created_at' => '2025-11-01', 'paid_at' => '2025-11-10', 'total' => 100];
        $up = InvoiceInsightsService::build([$current, $prior], [], '2026-11-20');
        $this->assertSame(100.0, $up['last_year_same_month']);
        $this->assertSame(12.0, $up['yoy_pct']);
        $this->assertSame('$112.00 this month, up 12% on last November', $up['money_headline']);

        $current['total'] = 80;
        $down = InvoiceInsightsService::build([$current, $prior], [], '2026-11-20');
        $this->assertSame('$80.00 this month, down 20% on last November', $down['money_headline']);
        $none = InvoiceInsightsService::build([$current], [], '2026-11-20');
        $this->assertNull($none['last_year_same_month']);
        $this->assertNull($none['yoy_pct']);
        $this->assertSame('$80.00 this month', $none['money_headline']);
    }

    public function testPayHeadlineComparesWithEarliestMonthThatHasData(): void
    {
        $paid = [
            ['created_at' => '2026-06-01', 'paid_at' => '2026-06-21', 'total' => 10],
            ['created_at' => '2026-11-01', 'paid_at' => '2026-11-11', 'total' => 10],
        ];
        $s = InvoiceInsightsService::build($paid, [], '2026-11-20');
        $this->assertSame('Paid in 15 days on average — latest month 10 days faster than in Jun', $s['pay_headline']);
        $one = InvoiceInsightsService::build([$paid[1]], [], '2026-11-20');
        $this->assertSame('Paid in 10 days on average', $one['pay_headline']);
    }

    public function testHoldersKeepsLongOverdueInvoicesWithNoExpectedDate(): void
    {
        $h = InvoiceInsightsService::holders([['who' => 'Old Debt Ltd', 'amount' => 900, 'date' => '', 'late' => true]], '2026-11-24');
        $this->assertSame('', $h['rows'][0]['expect']);
        $this->assertSame('$900.00 is late, across 1 client', $h['headline']);
    }

    public function testHasDataIgnoresPaidInvoicesOlderThanTheTwelveMonthWindow(): void
    {
        $s = InvoiceInsightsService::build([['created_at' => '2025-03-01', 'paid_at' => '2025-03-10', 'total' => 50]], [], '2026-11-20');
        $this->assertFalse($s['has_data']);
    }

    public function testEachMonthCarriesTheSameMonthLastYear(): void
    {
        $paid = [
            ['created_at' => '2025-10-20', 'paid_at' => '2025-11-10', 'total' => 100],
            ['created_at' => '2025-10-25', 'paid_at' => '2025-11-20', 'total' => 50],
            ['created_at' => '2026-10-20', 'paid_at' => '2026-11-10', 'total' => 80],
        ];
        $s = InvoiceInsightsService::build($paid, [], '2026-11-24');
        $this->assertSame(['Nov', 80.0, 150.0], [$s['months'][11]['label'], $s['months'][11]['total'], $s['months'][11]['last_year']]);
        $this->assertSame(0.0, $s['months'][0]['last_year']);
    }
}
