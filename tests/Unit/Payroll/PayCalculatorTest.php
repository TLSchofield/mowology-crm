<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Modules/Team/Services/OvertimeCalculator.php';

/**
 * Penny Payroll step 2 (2026-10-21): hours → gross pay under the BC Employment Standards Act, one test per rule.
 * Rate $30.00/h unless stated. October 2026: Sun 4, Sun 11, Mon 12 = Thanksgiving (a statutory holiday).
 */
class PayCalculatorTest extends TestCase
{
    private function calc(array $in): array
    {
        return (new PayCalculator('BC'))->calculate($in + ['rate_cents' => 3000, 'hire_date' => '2020-03-01', 'vacation_mode' => 'accrue']);
    }

    private function sum(array $r, string $code): int
    {
        return array_sum(array_map(fn($l) => $l['code'] === $code ? $l['minutes'] : 0, $r['lines']));
    }

    public function test_daily_overtime_8_and_12_hours(): void
    {
        $r = $this->calc(['period_start' => '2026-10-13', 'period_end' => '2026-10-13', 'days' => ['2026-10-13' => 780]]);
        $this->assertSame([480, 240, 60], [$this->sum($r, 'REG'), $this->sum($r, 'OT15'), $this->sum($r, 'OT20')]);
        $this->assertSame(24000 + 18000 + 6000, $r['gross_cents'], '8 h × $30 + 4 h × $45 + 1 h × $60');
    }

    public function test_a_sunday_counts_toward_that_weeks_40(): void
    {
        $days = [];
        foreach (['2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23'] as $d) $days[$d] = 480;   // Sun–Fri
        $r = $this->calc(['period_start' => '2026-10-18', 'period_end' => '2026-10-24', 'days' => $days]);
        $this->assertSame(2400, $this->sum($r, 'REG'));
        $this->assertSame(480, $this->sum($r, 'OT15'), 'Friday is past 40 h');
        $fri = array_values(array_filter($r['lines'], fn($l) => $l['date'] === '2026-10-23'));
        $this->assertSame('OT15', $fri[0]['code']);
        $this->assertStringContainsString('past 40 h in the week', $fri[0]['explain']);
    }

    public function test_only_the_first_8_hours_of_each_day_count_toward_the_week(): void
    {
        $days = [];
        foreach (['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23'] as $d) $days[$d] = 600;   // 5 × 10 h
        $r = $this->calc(['period_start' => '2026-10-18', 'period_end' => '2026-10-24', 'days' => $days]);
        $this->assertSame(2400, $this->sum($r, 'REG'));
        $this->assertSame(600, $this->sum($r, 'OT15'), '2 h a day of daily overtime, no weekly overtime on top');
    }

    public function test_a_period_starting_mid_week_counts_the_earlier_days_of_that_week(): void
    {
        $r = $this->calc(['period_start' => '2026-10-21', 'period_end' => '2026-10-24',   // Wed–Sat (e.g. a semi-monthly cut)
            'prior_days' => ['2026-10-18' => 480, '2026-10-19' => 480, '2026-10-20' => 480],
            'days' => ['2026-10-21' => 480, '2026-10-22' => 480, '2026-10-23' => 480, '2026-10-24' => 480]]);
        $this->assertSame(480 * 2, $this->sum($r, 'REG'), 'Wed + Thu regular (hits 40 h at the end of Thu)');
        $this->assertSame(480 * 2, $this->sum($r, 'OT15'), 'Fri + Sat past 40 h');
        $this->assertSame(0, count(array_filter($r['lines'], fn($l) => $l['date'] < '2026-10-21')), 'prior days are never paid again');
    }

    public function test_statutory_holiday_off_pays_an_average_days_pay(): void
    {
        $history = [];
        for ($i = 1; $i <= 30; $i++) {
            $d = date('Y-m-d', strtotime("2026-10-12 -{$i} days"));
            $history[$d] = in_array((int)date('w', strtotime($d)), [0, 6], true) ? ['wages_cents' => 0, 'earned' => false] : ['wages_cents' => 24000, 'earned' => true];
        }
        $r = $this->calc(['period_start' => '2026-10-11', 'period_end' => '2026-10-17', 'days' => [], 'history' => $history]);
        $stat = array_values(array_filter($r['lines'], fn($l) => $l['code'] === 'STAT'));
        $this->assertCount(1, $stat);
        $this->assertSame(24000, $stat[0]['amount_cents'], '$240 a day on every day earned');
        $this->assertStringContainsString("average day's pay", $stat[0]['explain']);
    }

    public function test_working_a_statutory_holiday_is_time_and_a_half_plus_the_average_day(): void
    {
        $history = [];
        for ($i = 1; $i <= 30; $i++) $history[date('Y-m-d', strtotime("2026-10-12 -{$i} days"))] = ['wages_cents' => 20000, 'earned' => $i <= 20];
        $r = $this->calc(['period_start' => '2026-10-11', 'period_end' => '2026-10-17', 'days' => ['2026-10-12' => 780], 'history' => $history]);
        $this->assertSame(720, $this->sum($r, 'STATW15'));
        $this->assertSame(60, $this->sum($r, 'STATW20'));
        $this->assertSame(0, $this->sum($r, 'OT15'), 'the holiday premium replaces daily overtime, never both');
        $stat = array_values(array_filter($r['lines'], fn($l) => $l['code'] === 'STAT'))[0];
        $this->assertSame(30000, $stat['amount_cents'], '30 days × $200 ÷ 20 days earned = $300');
        $this->assertSame(54000 + 6000 + 30000, $r['gross_cents'], '12 h × $45 + 1 h × $60 + $300');
    }

    public function test_not_yet_eligible_means_an_ordinary_day_and_says_why(): void
    {
        $r = $this->calc(['period_start' => '2026-10-11', 'period_end' => '2026-10-17', 'hire_date' => '2026-10-01', 'days' => ['2026-10-12' => 480]]);
        $this->assertSame(480, $this->sum($r, 'REG'));
        $this->assertSame(0, $this->sum($r, 'STATW15'));
        $this->assertStringContainsString('employed less than 30 days', implode(' ', $r['problems']));
    }

    public function test_vacation_pay_is_4_percent_of_all_wages_including_overtime(): void
    {
        $in = ['period_start' => '2026-10-13', 'period_end' => '2026-10-13', 'days' => ['2026-10-13' => 780], 'hire_date' => '2024-01-01'];
        $accrued = $this->calc($in);
        $this->assertSame(1920, $accrued['vacation_accrued_cents'], '4% of $480');
        $this->assertSame(48000, $accrued['gross_cents'], 'accrued is not paid now');
        $paid = $this->calc($in + ['vacation_mode' => 'each_cheque']);
        $this->assertSame(48000 + 1920, $paid['gross_cents']);
        $this->assertSame(0, $paid['vacation_accrued_cents']);
    }

    public function test_vacation_rises_to_6_percent_from_the_fifth_anniversary(): void
    {
        $r = $this->calc(['period_start' => '2026-10-19', 'period_end' => '2026-10-20', 'hire_date' => '2021-10-20',
            'days' => ['2026-10-19' => 480, '2026-10-20' => 480]]);
        $this->assertSame((int)round(24000 * 0.04 + 24000 * 0.06), $r['vacation_accrued_cents'], 'the day before at 4%, the anniversary at 6%');
    }

    public function test_a_rate_below_the_minimum_wage_on_that_day_is_flagged(): void
    {
        $r = $this->calc(['period_start' => '2026-05-30', 'period_end' => '2026-06-02', 'rate_cents' => 1800,
            'days' => ['2026-05-30' => 480, '2026-06-02' => 480]]);
        $text = implode(' ', $r['problems']);
        $this->assertStringContainsString('Tue Jun 2', $text, '$18.25 from 1 June');
        $this->assertStringNotContainsString('Sat May 30', $text, '$17.85 before 1 June');
    }

    public function test_whole_weeks_match_the_existing_overtime_calculator(): void
    {
        $days = ['2026-10-18' => 300, '2026-10-19' => 545, '2026-10-20' => 760, '2026-10-21' => 480, '2026-10-22' => 600,
                 '2026-10-23' => 510, '2026-10-24' => 420, '2026-10-25' => 480, '2026-10-26' => 900];
        $r = $this->calc(['period_start' => '2026-10-18', 'period_end' => '2026-10-31', 'days' => $days]);
        $o = OvertimeCalculator::calculatePeriod($days, 30.0);
        $this->assertSame($o['regular_minutes'], $this->sum($r, 'REG'));
        $this->assertSame($o['ot_1_5_minutes'], $this->sum($r, 'OT15'));
        $this->assertSame($o['ot_2_0_minutes'], $this->sum($r, 'OT20'));
        $this->assertEqualsWithDelta($o['gross_pay'] * 100, $r['gross_cents'], 2, 'to the cent, allowing per-day rounding');
    }

    public function test_bad_input_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc(['period_start' => '2026-10-18', 'period_end' => '2026-10-24', 'days' => ['2026-10-30' => 60]]);
    }
}
