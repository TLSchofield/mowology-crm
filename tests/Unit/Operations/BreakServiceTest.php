<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/../../../app/Modules/Team/Services/OvertimeCalculator.php";

/** Breaks from the crew phone's GPS; the BC 5-hour rule (owner, 2026-10-10). */
class BreakServiceTest extends TestCase
{
    private int $in;
    private int $out;

    protected function setUp(): void
    {
        $this->in = strtotime('2026-10-09 07:00');
        $this->out = strtotime('2026-10-09 15:30');
    }

    private function stop(string $from, string $to, string $type, ?string $kind = null, string $name = 'X'): array
    {
        return ['start' => strtotime("2026-10-09 $from"), 'end' => strtotime("2026-10-09 $to"), 'label' => ['type' => $type, 'kind' => $kind, 'name' => $name]];
    }

    public function test_a_coffee_stop_is_a_break_but_a_job_is_not(): void
    {
        $b = BreakService::candidates([
            $this->stop('09:00', '11:30', 'property'),                       // working on a job
            $this->stop('12:05', '12:37', 'unnamed'),                        // lunch pull-in
            $this->stop('13:00', '13:40', 'place', 'dump', 'Vancouver Landfill'),
            $this->stop('14:00', '14:10', 'unnamed'),                        // too short
            $this->stop('07:02', '07:40', 'unnamed'),                        // loading up at the start
        ], $this->in, $this->out);
        $this->assertCount(1, $b);
        $this->assertSame(32, $b[0]['minutes']);
        $this->assertSame('unnamed stop', $b[0]['where']);
    }

    public function test_bc_meal_break_rule(): void
    {
        $this->assertNull(BreakService::complianceGap(290, []));            // under 5 h
        $this->assertNull(BreakService::complianceGap(720, []));            // 12 h = a forgotten clock-out, not a long day
        $this->assertNull(BreakService::complianceGap(510, [32]));
        $this->assertStringContainsString('no break taken', BreakService::complianceGap(510, []));
        $this->assertStringContainsString('longest break was 22 min', BreakService::complianceGap(510, [22]));
    }

    public function test_every_onboarding_question_has_a_default(): void
    {
        foreach (OnboardingQuestions::QUESTIONS as $k => $q) {
            $this->assertCount(4, $q, $k);
            $this->assertNotSame('', $q[0]);
        }
        $this->assertSame('1', OnboardingQuestions::QUESTIONS['breaks_paid'][1]);
    }

    // ── The break button (2026-10-13) ───────────────────────────────────────

    private function t(string $hm): int
    {
        return strtotime("2026-10-09 $hm");
    }

    public function test_a_tapped_break_wins_over_an_overlapping_gps_break_and_is_counted_once(): void
    {
        $m = BreakService::merge([
            ['start' => $this->t('11:55'), 'end' => $this->t('12:40'), 'source' => 'gps'],      // overlaps the tap → dropped
            ['start' => $this->t('12:00'), 'end' => $this->t('12:30'), 'source' => 'manual'],
            ['start' => $this->t('14:00'), 'end' => $this->t('14:20'), 'source' => 'gps'],      // its own → kept
        ], $this->in, $this->out, $this->out);
        $this->assertCount(2, $m);
        $this->assertSame(['manual', 'gps'], array_column($m, 'source'));
        $this->assertSame([30, 20], array_column($m, 'minutes'));
        $this->assertSame(50, array_sum(array_column($m, 'minutes')));
    }

    public function test_an_open_break_runs_to_the_clock_out_or_now_and_replayed_taps_join(): void
    {
        // Still running on an open shift: counts to now.
        $m = BreakService::merge([['start' => $this->t('12:00'), 'end' => null, 'source' => 'manual']],
            $this->in, null, $this->t('12:25'));
        $this->assertSame(25, $m[0]['minutes']);
        $this->assertTrue($m[0]['open']);
        // A break left open past the clock-out is clipped to it; a break before clock-in is clipped too.
        $m = BreakService::merge([['start' => $this->t('15:10'), 'end' => null, 'source' => 'manual'],
                                  ['start' => $this->t('06:50'), 'end' => $this->t('07:10'), 'source' => 'manual']],
            $this->in, $this->out, $this->t('18:00'));
        $this->assertSame([10, 20], array_column($m, 'minutes'));
        // The same tap filed twice (an offline replay) overlaps itself → one break.
        $m = BreakService::merge([['start' => $this->t('12:00'), 'end' => $this->t('12:30'), 'source' => 'manual'],
                                  ['start' => $this->t('12:10'), 'end' => $this->t('12:35'), 'source' => 'manual']],
            $this->in, $this->out, $this->out);
        $this->assertCount(1, $m);
        $this->assertSame(35, $m[0]['minutes']);
    }

    public function test_paid_breaks_never_change_pay_and_unpaid_takes_off_tapped_minutes_only(): void
    {
        $m = BreakService::merge([
            ['start' => $this->t('12:00'), 'end' => $this->t('12:30'), 'source' => 'manual'],
            ['start' => $this->t('14:00'), 'end' => $this->t('14:25'), 'source' => 'gps'],
        ], $this->in, $this->out, $this->out);
        $this->assertSame(0, BreakService::unpaidMinutes($m, true));        // Mowology: paid → hours unchanged
        $this->assertSame(30, BreakService::unpaidMinutes($m, false));      // a GPS guess never costs pay
        $this->assertSame(480, BreakService::paidMinutes(510, BreakService::unpaidMinutes($m, false)));
        $this->assertSame(510, BreakService::paidMinutes(510, BreakService::unpaidMinutes($m, true)));
        $this->assertSame(0, BreakService::paidMinutes(20, 30));
    }

    public function test_unpaid_breaks_come_off_before_the_daily_overtime_split(): void
    {
        // 8 h 30 clocked with a 30-min unpaid break = 8 h paid: no overtime at all.
        $day = BreakService::paidMinutes(510, 30);
        $pay = OvertimeCalculator::calculatePeriod(['2026-10-09' => $day], 20.0);
        $this->assertSame(480, $pay['regular_minutes']);
        $this->assertSame(0, $pay['ot_1_5_minutes']);
        // Paid breaks: the same day is 30 min of 1.5×.
        $pay = OvertimeCalculator::calculatePeriod(['2026-10-09' => BreakService::paidMinutes(510, 0)], 20.0);
        $this->assertSame(30, $pay['ot_1_5_minutes']);
    }

    public function test_reminder_minutes_are_kept_sensible(): void
    {
        $this->assertSame(60, BreakService::reminderMinutesFrom(null));
        $this->assertSame(60, BreakService::reminderMinutesFrom('0'));
        $this->assertSame(5, BreakService::reminderMinutesFrom('1'));
        $this->assertSame(240, BreakService::reminderMinutesFrom('999'));
        $this->assertSame(45, BreakService::reminderMinutesFrom('45'));
    }

    public function test_bc_flag_counts_a_tapped_break(): void
    {
        $m = BreakService::merge([['start' => $this->t('11:30'), 'end' => $this->t('12:00'), 'source' => 'manual']],
            $this->in, $this->out, $this->out);
        $this->assertNull(BreakService::complianceGap(510, array_column($m, 'minutes')));
        $m = BreakService::merge([['start' => $this->t('11:30'), 'end' => $this->t('11:45'), 'source' => 'manual']],
            $this->in, $this->out, $this->out);
        $this->assertStringContainsString('longest break was 15 min', (string)BreakService::complianceGap(510, array_column($m, 'minutes')));
    }

    public function test_my_timesheet_shows_breaks_and_paid_time(): void
    {
        $clock = [['id' => 7, 'clock_in' => '2026-10-13 07:00:00', 'clock_out' => '2026-10-13 15:30:00', 'status' => 'completed']];
        $merged = BreakService::merge([['start' => strtotime('2026-10-13 12:00'), 'end' => strtotime('2026-10-13 12:30'), 'source' => 'manual']],
            strtotime('2026-10-13 07:00'), strtotime('2026-10-13 15:30'), time());
        $sum = [7 => ['breaks' => $merged, 'break_minutes' => 30, 'unpaid_minutes' => 30]];
        $paid = TimesheetService::buildWeek($clock, [], '2026-10-12', time(), $sum, true);
        $this->assertSame(1800, $paid['break_seconds']);
        $this->assertSame($paid['total_seconds'], $paid['paid_seconds']);
        $this->assertSame('2026-10-13 12:00:00', $paid['days'][1]['entries'][0]['breaks'][0]['start']);
        $unpaid = TimesheetService::buildWeek($clock, [], '2026-10-12', time(), $sum, false);
        $this->assertSame($unpaid['total_seconds'] - 1800, $unpaid['paid_seconds']);
        $this->assertFalse($unpaid['breaks_paid']);
    }
}
