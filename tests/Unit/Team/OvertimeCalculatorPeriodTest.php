<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Modules/Team/Services/OvertimeCalculator.php';

/** BC overtime over a pay period: each week separately, daily rules always (2026-10-12). */
final class OvertimeCalculatorPeriodTest extends TestCase
{
    public function testTwoWeeksOf36HoursHaveNoWeeklyOvertime(): void
    {
        $days = [];
        foreach (['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'] as $d) {
            $days[$d] = 540; // 9 h days → 1 h daily OT each, 8 h regular each
        }
        $p = OvertimeCalculator::calculatePeriod($days, 20.0);
        $this->assertSame(8 * 480, $p['regular_minutes']);
        $this->assertSame(8 * 60, $p['ot_1_5_minutes']);
        $this->assertCount(2, $p['weeks']);
        // The old path (one 72 h lump) would have paid 32 h at 1.5× and no daily OT.
        $old = OvertimeCalculator::calculateFromWeeklyTotal(array_sum($days), 20.0);
        $this->assertSame(2400, $old['regular_minutes']);
        $this->assertNotEquals($old['gross_pay'], $p['gross_pay']);
    }

    public function testDoubleTimeAfterTwelveHours(): void
    {
        $p = OvertimeCalculator::calculatePeriod(['2026-10-06' => 780], 30.0);
        $this->assertSame(480, $p['regular_minutes']);
        $this->assertSame(240, $p['ot_1_5_minutes']);
        $this->assertSame(60, $p['ot_2_0_minutes']);
        $this->assertSame(round(240 + 180 + 60, 2), $p['gross_pay']); // 8h×30 + 4h×45 + 1h×60
    }

    public function testWeeklyRuleAppliesPerWeekNotPerPeriod(): void
    {
        // Week 1: five 9 h days → 40 h regular + 5 h daily OT; Week 2: 8 h days ×5 = 40 h regular.
        $days = [];
        foreach (['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'] as $d) $days[$d] = 540;
        foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'] as $d) $days[$d] = 480;
        $p = OvertimeCalculator::calculatePeriod($days, 10.0);
        $this->assertSame(4800, $p['regular_minutes']);
        $this->assertSame(300, $p['ot_1_5_minutes']);
        // Saturday 6 h more in week 2 → 6 h over 40 → 1.5×
        $days['2026-10-10'] = 360;
        $p = OvertimeCalculator::calculatePeriod($days, 10.0);
        $this->assertSame(4800, $p['regular_minutes']);
        $this->assertSame(660, $p['ot_1_5_minutes']);
    }

    /** BC ESA s.1: the overtime week begins on Sunday (Fable review 2026-10-21; it was Monday). */
    public function testTheOvertimeWeekStartsOnSunday(): void
    {
        $p = OvertimeCalculator::calculatePeriod(['2026-10-10' => 60, '2026-10-11' => 60, '2026-10-12' => 60], 10.0);
        $this->assertSame(['2026-10-04', '2026-10-11'], array_keys($p['weeks']), 'Sat ends a week; Sun and Mon start the next');
    }

    /** Sunday 8 h + Monday–Friday 8 h = 48 h in one ESA week: 8 h weekly overtime (Monday weeks paid none). */
    public function testASundayShiftCountsTowardThatWeeksForty(): void
    {
        $days = ['2026-10-11' => 480];
        foreach (['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'] as $d) $days[$d] = 480;
        $p = OvertimeCalculator::calculatePeriod($days, 10.0);
        $this->assertSame(480, $p['ot_1_5_minutes']);
        $this->assertSame(2400, $p['regular_minutes']);
    }
}
