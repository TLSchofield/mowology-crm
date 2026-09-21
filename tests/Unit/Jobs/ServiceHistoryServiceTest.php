<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ServiceHistoryServiceTest extends TestCase
{
    public function testWindowIsLastWeekThenThisWeekMondayToSunday(): void
    {
        // 2026-09-23 is a Wednesday.
        $w = ServiceHistoryService::window('2026-09-23');
        $this->assertCount(14, $w);
        $this->assertSame('2026-09-14', $w[0]);   // Monday last week
        $this->assertSame('2026-09-21', $w[7]);   // Monday this week
        $this->assertSame('2026-09-27', $w[13]);  // Sunday this week
    }

    public function testWindowOnASundayStillEndsOnThatSunday(): void
    {
        $w = ServiceHistoryService::window('2026-09-20');
        $this->assertSame('2026-09-07', $w[0]);
        $this->assertSame('2026-09-20', $w[13]);
    }

    public function testWindowOnAMondayStartsSevenDaysBack(): void
    {
        $w = ServiceHistoryService::window('2026-09-21');
        $this->assertSame('2026-09-14', $w[0]);
        $this->assertSame('2026-09-21', $w[7]);
    }

    public function testGridKeepsTheMostSignificantStatePerDayAndCountsCompletions(): void
    {
        $w = ServiceHistoryService::window('2026-09-23');
        $grid = ServiceHistoryService::grid([
            ['date' => '2026-09-15', 'state' => 'skipped'],
            ['date' => '2026-09-15', 'state' => 'completed'],
            ['date' => '2026-09-15', 'state' => 'completed'],
            ['date' => '2026-09-18', 'state' => 'skipped'],
            ['date' => '2026-09-24', 'state' => 'scheduled'],
            ['date' => '2026-01-01', 'state' => 'completed'],   // outside the window
        ], $w);

        $byDate = array_column($grid, null, 'date');
        $this->assertCount(14, $grid);
        $this->assertSame('completed', $byDate['2026-09-15']['state']);
        $this->assertSame(2, $byDate['2026-09-15']['count']);
        $this->assertSame('skipped', $byDate['2026-09-18']['state']);
        $this->assertSame('scheduled', $byDate['2026-09-24']['state']);
        $this->assertSame('none', $byDate['2026-09-16']['state']);
    }

    public function testSummaryForARegularService(): void
    {
        $this->assertSame('First visit', ServiceHistoryService::summary(null, null, '2026-09-23', false));
        $this->assertSame('Last done 6 days ago', ServiceHistoryService::summary('2026-09-17 14:10:00', null, '2026-09-23', false));
        $this->assertSame('Last done yesterday', ServiceHistoryService::summary('2026-09-22 09:00:00', null, '2026-09-23', false));
        // No clock time on a lawn cut — the hour is noise.
        $this->assertSame('Last done today', ServiceHistoryService::summary('2026-09-23 09:00:00', null, '2026-09-23', false));
    }

    public function testASkipSinceTheLastCompletionLeadsTheSummary(): void
    {
        $this->assertSame(
            'Skipped Thu — last done 13 days ago',
            ServiceHistoryService::summary('2026-09-10 11:00:00', '2026-09-17', '2026-09-23', false)
        );
        // A skip BEFORE the last completion is old news.
        $this->assertSame(
            'Last done 6 days ago',
            ServiceHistoryService::summary('2026-09-17 11:00:00', '2026-09-10', '2026-09-23', false)
        );
        $this->assertSame('Skipped Thu — not done yet', ServiceHistoryService::summary(null, '2026-09-17', '2026-09-23', false));
    }

    public function testWinterServicesCarryTheHourAndTheTwentyFourHourCount(): void
    {
        $this->assertSame(
            'Last done today 02:40 · 3 in 24 h',
            ServiceHistoryService::summary('2026-12-04 02:40:00', null, '2026-12-04', true, 3)
        );
        $this->assertSame('Last done yesterday 23:15', ServiceHistoryService::summary('2026-12-03 23:15:00', null, '2026-12-04', true, 1));
        $this->assertSame('Last done 5 days ago', ServiceHistoryService::summary('2026-11-29 04:00:00', null, '2026-12-04', true, 0));
    }

    public function testWinterDetection(): void
    {
        $this->assertTrue(ServiceHistoryService::isWinterService('salt_application'));
        $this->assertTrue(ServiceHistoryService::isWinterService('landscaping', 'Snow Removal - Residential'));
        $this->assertFalse(ServiceHistoryService::isWinterService('lawn_care', 'Weekly cut'));
    }
}
