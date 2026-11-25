<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Completing a visit ends every active timer on it, whoever owns it; pausing ends only
 * the caller's own. Regression for 2026-10-06: the truck login's GPS auto-start owned the
 * only timer, so Mark Complete from a crew phone threw "No active timer for this job".
 */
class VisitTimerStopPolicyTest extends TestCase
{
    private const TIM = 1;
    private const TRUCK = 7;
    private const NIGEL = 6;

    public function testCompletingStopsTruckTimerWhenCallerHasNone(): void
    {
        // DB rows come back as strings
        $plan = VisitTimerStopPolicy::decide(self::TIM, [['id' => '352', 'user_id' => '7']], true, 'in_progress');
        $this->assertNull($plan['error']);
        $this->assertSame([352], $plan['stop']);
        $this->assertNull($plan['own']);
        $this->assertTrue($plan['complete']);
    }

    public function testCompletingStopsOwnTimerFirstThenEveryoneElses(): void
    {
        $active = [
            ['id' => 12, 'user_id' => self::TRUCK],
            ['id' => 11, 'user_id' => self::TIM],
            ['id' => 10, 'user_id' => self::NIGEL],
        ];
        $plan = VisitTimerStopPolicy::decide(self::TIM, $active, true, 'in_progress');
        $this->assertSame([11, 12, 10], $plan['stop']);
        $this->assertSame(11, $plan['own']);
        $this->assertTrue($plan['complete']);
    }

    public function testCompletingAfterDepartureAutoStopCompletesWithNoTimer(): void
    {
        $plan = VisitTimerStopPolicy::decide(self::TIM, [], true, 'in_progress');
        $this->assertNull($plan['error']);
        $this->assertSame([], $plan['stop']);
        $this->assertTrue($plan['complete']);
    }

    public function testAlreadyCompletedVisitIsANoOpNotAnError(): void
    {
        $plan = VisitTimerStopPolicy::decide(self::TIM, [], true, 'completed');
        $this->assertNull($plan['error']);
        $this->assertSame([], $plan['stop']);
        $this->assertFalse($plan['complete']);
    }

    public function testSkippedVisitWithNoTimerStillRefuses(): void
    {
        $plan = VisitTimerStopPolicy::decide(self::TIM, [], true, 'skipped');
        $this->assertSame(VisitTimerStopPolicy::NO_TIMER, $plan['error']);
        $this->assertFalse($plan['complete']);
    }

    public function testPauseNeverTouchesSomeoneElsesTimer(): void
    {
        $plan = VisitTimerStopPolicy::decide(self::TIM, [['id' => 352, 'user_id' => self::TRUCK]], false, 'in_progress');
        $this->assertSame(VisitTimerStopPolicy::NO_TIMER, $plan['error']);
        $this->assertSame([], $plan['stop']);

        $plan = VisitTimerStopPolicy::decide(self::TIM, [
            ['id' => 352, 'user_id' => self::TRUCK],
            ['id' => 351, 'user_id' => self::TIM],
        ], false, 'in_progress');
        $this->assertSame([351], $plan['stop']);
        $this->assertFalse($plan['complete']);
    }
}
