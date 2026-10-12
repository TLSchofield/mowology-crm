<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Visit status guard + what a stop completion bills (2026-10-12 review). */
final class VisitTransitionRulesTest extends TestCase
{
    public function testCompletedNeverBecomesNotDone(): void
    {
        foreach (['skipped', 'weather', 'cancelled'] as $to) {
            $this->assertFalse(VisitLifecycleService::canTransition('completed', $to), $to);
        }
        $this->assertTrue(VisitLifecycleService::canTransition('completed', 'completed'));
        $this->assertTrue(VisitLifecycleService::canTransition('completed', 'scheduled'));
        $this->assertTrue(VisitLifecycleService::canTransition('skipped', 'completed'));
        $this->assertTrue(VisitLifecycleService::canTransition('scheduled', 'skipped'));
    }

    public function testCompletingAStopLeavesSkippedVisitsOut(): void
    {
        $v = [['visit_id' => 1, 'visit_status' => 'scheduled'], ['visit_id' => 2, 'visit_status' => 'skipped']];
        $this->assertSame([1], array_column(VisitLifecycleService::billableAtStop($v), 'visit_id'));
    }

    public function testAllSkippedStopStillCompletes(): void
    {
        $v = [['visit_id' => 3, 'visit_status' => 'skipped']];
        $this->assertSame([3], array_column(VisitLifecycleService::billableAtStop($v), 'visit_id'));
    }
}
