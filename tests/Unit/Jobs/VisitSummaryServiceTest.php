<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VisitSummaryServiceTest extends TestCase
{
    public function testChecklistUsesWhatCrewTickedAndCounts(): void
    {
        $c = VisitSummaryService::shapeChecklist(
            [['item' => 'Mow front', 'checked' => true], ['item' => 'Edge', 'checked' => false, 'note' => ' gate stuck ']],
            ['ignored']
        );
        $this->assertSame(2, $c['total']);
        $this->assertSame(1, $c['done']);
        $this->assertSame('gate stuck', $c['items'][1]['note']);
    }

    public function testChecklistFallsBackToPlanTemplateUnticked(): void
    {
        $c = VisitSummaryService::shapeChecklist(null, ['Mow', 'Blow', '']);
        $this->assertSame(2, $c['total']);
        $this->assertSame(0, $c['done']);
        $this->assertFalse($c['items'][0]['done']);
    }

    public function testChecklistToleratesGarbage(): void
    {
        $this->assertSame(0, VisitSummaryService::shapeChecklist('x', 5)['total']);
    }

    public function testMaterialsDropNamelessRowsAndTidyQuantity(): void
    {
        $m = VisitSummaryService::shapeMaterials([['name' => 'Fertilizer', 'qty' => '2.50', 'unit' => 'bag'], ['qty' => 1], 'bad', ['name' => 'Salt', 'qty' => 3]]);
        $this->assertCount(2, $m);
        $this->assertSame('2.5', $m[0]['qty']);
        $this->assertSame('3', $m[1]['qty']);
        $this->assertSame([], VisitSummaryService::shapeMaterials(null));
    }

    public function testRepeatWording(): void
    {
        $this->assertSame('One time', VisitSummaryService::repeatLabel(['is_recurring' => 0]));
        $this->assertSame('Every week', VisitSummaryService::repeatLabel(['is_recurring' => 1, 'recurrence_pattern' => 'weekly']));
        $this->assertSame('Every 2 weeks', VisitSummaryService::repeatLabel(['is_recurring' => 1, 'recurrence_pattern' => 'biweekly']));
        $this->assertSame('Every month', VisitSummaryService::repeatLabel(['is_recurring' => 1, 'recurrence_pattern' => 'monthly']));
        $this->assertSame('Every 3 days', VisitSummaryService::repeatLabel(['is_recurring' => 1, 'recurrence_pattern' => 'custom', 'recurrence_interval' => 3, 'recurrence_interval_unit' => 'days']));
    }

    public function testLengthPrefersTheVisitsOwnResize(): void
    {
        $this->assertSame(45, VisitSummaryService::lengthMinutes(['duration_override_minutes' => 45], ['estimated_duration_minutes' => 60]));
        $this->assertSame(60, VisitSummaryService::lengthMinutes(['duration_override_minutes' => null], ['estimated_duration_minutes' => 60]));
        $this->assertNull(VisitSummaryService::lengthMinutes([], []));
    }

    public function testServiceLabelsReadAsPlainWords(): void
    {
        $this->assertSame('Snow removal', VisitSummaryService::serviceLabel('snow_removal'));
        $this->assertSame('Pressure washing', VisitSummaryService::serviceLabel('pressure_washing'));
        $this->assertSame('Visit', VisitSummaryService::serviceLabel(''));
    }
}
