<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Penny's 13-week cash-flow forecast — the pure rules (owner, 2026-10-10). */
class CashFlowForecastServiceTest extends TestCase
{
    public function test_weeks_start_on_monday(): void
    {
        $this->assertSame('2026-10-05', CashFlowForecastService::weekStart('2026-10-10'));
        $this->assertSame('2026-10-12', CashFlowForecastService::weekStart('2026-10-12'));
    }

    public function test_running_balance_and_low_point(): void
    {
        $f = CashFlowForecastService::build(5000, '2026-10-10', [
            ['date' => '2026-10-20', 'amount' => -4000, 'kind' => 'bill', 'label' => 'A'],
            ['date' => '2026-10-28', 'amount' => 1000, 'kind' => 'invoice', 'label' => 'B'],
            ['date' => '2027-06-01', 'amount' => 9999, 'kind' => 'invoice', 'label' => 'too late'],
        ], 13, 2000);
        $this->assertCount(13, $f['weeks']);
        $this->assertSame(5000.0, $f['weeks'][0]['closing']);
        $this->assertSame(1000.0, $f['weeks'][2]['closing']);   // week of Oct 19
        $this->assertSame(2000.0, $f['weeks'][3]['closing']);   // week of Oct 26
        $this->assertSame(['start' => '2026-10-19', 'closing' => 1000.0], $f['low']);
        $this->assertTrue($f['below_floor']);
        $this->assertSame(2000.0, end($f['weeks'])['closing']);  // the June invoice is outside 13 weeks
    }

    public function test_overdue_money_lands_in_the_first_week(): void
    {
        $f = CashFlowForecastService::build(0, '2026-10-10', [['date' => '2026-08-01', 'amount' => 500, 'kind' => 'invoice', 'label' => 'old']]);
        $this->assertSame(500.0, $f['weeks'][0]['in']);
    }

    public function test_client_lateness(): void
    {
        $this->assertSame(12, CashFlowForecastService::lateness([10, 12, 30]));
        $this->assertSame(0, CashFlowForecastService::lateness([-5, -3, -1]));   // early payers count as on time
        $this->assertSame(90, CashFlowForecastService::lateness([200, 300]));
        $this->assertNull(CashFlowForecastService::lateness([]));
        $this->assertSame('2026-11-14', CashFlowForecastService::expectedPayDate('2026-11-01', 13, '2026-10-10'));
        $this->assertSame('2026-10-10', CashFlowForecastService::expectedPayDate('2026-09-01', 5, '2026-10-10'));
    }

    public function test_biweekly_truck_loan_is_found(): void
    {
        $lines = [];
        foreach (['2026-08-07', '2026-08-21', '2026-09-04', '2026-09-18', '2026-10-02'] as $d) {
            $lines[] = ['transaction_date' => $d, 'amount' => 363.08, 'description' => 'TD ON-LINE LOANS'];
        }
        $lines[] = ['transaction_date' => '2026-09-10', 'amount' => 80, 'description' => 'LAWNBOY'];
        $b = CashFlowForecastService::biweekly($lines, '2026-10-10');
        $this->assertCount(1, $b);
        $this->assertSame(363.08, $b[0]['amount']);
        $this->assertSame('2026-10-16', $b[0]['next']);
    }

    public function test_dates_repeat_inside_the_window(): void
    {
        $this->assertSame(['2026-10-15', '2026-11-15', '2026-12-15'], CashFlowForecastService::repeat('2026-10-15', '2026-12-31', 'month'));
        $this->assertSame('2026-11-15', CashFlowForecastService::nextMonthDay('2026-10-20', 15));
        $this->assertSame('2026-10-15', CashFlowForecastService::nextMonthDay('2026-10-10', 15));
    }

    public function test_tax_payments_are_not_everyday_spending(): void
    {
        $this->assertTrue(CashFlowForecastService::isTaxPayment('ONLINE PAYMENT CRA BUSINESS'));
        $this->assertTrue(CashFlowForecastService::isTaxPayment('RECEIVER GENERAL GST'));
        $this->assertFalse(CashFlowForecastService::isTaxPayment('CHEVRON 16&MACDONALD'));
    }

    public function test_winter_uses_last_winters_spending(): void
    {
        $m = ['2025-12' => 1500.0, '2025-08' => 5000.0];
        $this->assertSame([1500.0, 'same month last year (Dec 2025)'], CashFlowForecastService::everydayForWeek('2026-12-07', $m, 4900));
        $this->assertSame([4900.0, 'average of the last 8 weeks'], CashFlowForecastService::everydayForWeek('2026-10-12', $m, 4900));
    }

    public function test_plans_are_projected_past_the_calendar(): void
    {
        $this->assertSame(['2026-11-12', '2026-11-26'], CashFlowForecastService::projectVisits(['2026-10-15', '2026-10-29'], '2026-12-01', '2027-01-03'));
        $this->assertSame([], CashFlowForecastService::projectVisits([], null, '2027-01-03'));
        $this->assertCount(4, CashFlowForecastService::projectVisits(['2026-11-05'], null, '2026-12-31'));   // default every 14 days
    }

    public function test_two_spellings_of_one_person_are_one_payee(): void
    {
        $a = CashFlowForecastService::payee('e-Transfer debit Ref 20260828142613669027 Nigel Casey Nigel ');
        $b = CashFlowForecastService::payee('e-Transfer debit Ref 20260925193121667684 Nigel Casey');
        $this->assertSame('E-transfer to Nigel Casey', $a);
        $this->assertSame($a, $b);
        $this->assertSame('owner', CashFlowForecastService::category('e-Transfer debit Ref 2026 Tim Schofield Tim ', 'Tim Schofield'));
        $this->assertSame('staff', CashFlowForecastService::category('e-Transfer debit Ref 2026 Katrenna Marenych', 'Tim Schofield'));
        $this->assertSame('staff', CashFlowForecastService::category('Preauthorized payment Wave PYRL', 'Tim Schofield'));
        $this->assertSame('other', CashFlowForecastService::category('Point of sale VITAL AUTO REPAIR', 'Tim Schofield'));
    }

    public function test_what_each_week_could_spare_for_tax(): void
    {
        // balance 9k, then a dip to 3k later: only 1k can go now without breaking a 2k floor
        $this->assertSame([1000.0, 1000.0, 2000.0, 2000.0], CashFlowForecastService::safeToPay([9000, 3000, 8000, 4000], 2000));
        $this->assertSame([0.0, 0.0], CashFlowForecastService::safeToPay([9000, 1500], 2000));   // a later dip under the floor leaves nothing to spare
    }

    public function test_seasonal_layoff_window(): void
    {
        $this->assertSame([['name' => 'Nigel Casey', 'from' => '12-01', 'to' => '03-01']], CashFlowForecastService::parseLayoffs('Nigel Casey:12-01:03-01'));
        $this->assertTrue(CashFlowForecastService::inSeason('2026-12-07', '12-01', '03-01'));
        $this->assertTrue(CashFlowForecastService::inSeason('2027-02-15', '12-01', '03-01'));
        $this->assertFalse(CashFlowForecastService::inSeason('2026-11-23', '12-01', '03-01'));
        $this->assertFalse(CashFlowForecastService::inSeason('2027-03-01', '12-01', '03-01'));
    }
}
