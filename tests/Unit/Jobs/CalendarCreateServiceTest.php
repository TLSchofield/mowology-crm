<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CalendarCreateServiceTest extends TestCase
{
    public function testJobWithTimesBuildsAOneOffPlanWithTheWindow(): void
    {
        $p = CalendarCreateService::buildPlanData(['property_id' => 5, 'title' => ' Lawn cut ', 'date' => '2026-11-12',
            'start' => '08:00', 'end' => '09:30', 'assignee_ids' => ['7', '7', '9', 'x']]);
        $this->assertSame(0, $p['is_recurring']);
        $this->assertSame('Lawn cut', $p['title']);
        $this->assertSame('Lawn cut', $p['service_type']);
        $this->assertSame('08:00:00', $p['default_time_start']);
        $this->assertSame('09:30:00', $p['default_time_end']);
        $this->assertSame(90, $p['estimated_duration_minutes']);
        $this->assertSame([7, 9], $p['crew_ids']);
        $this->assertSame(7, $p['default_crew_id']);
    }

    public function testAnytimeLeavesTheWindowEmpty(): void
    {
        $p = CalendarCreateService::buildPlanData(['property_id' => 5, 'title' => 'Walk', 'date' => '2026-11-12', 'anytime' => 1, 'kind' => 'task']);
        $this->assertNull($p['default_time_start']);
        $this->assertNull($p['default_time_end']);
        $this->assertSame('Task', $p['service_type']);
    }

    /** @dataProvider bad */
    public function testRejects(array $in): void
    {
        $this->expectException(InvalidArgumentException::class);
        CalendarCreateService::buildPlanData($in + ['property_id' => 5, 'title' => 'x', 'date' => '2026-11-12', 'start' => '08:00', 'end' => '09:00']);
    }

    public static function bad(): array
    {
        return [[['property_id' => 0]], [['title' => ' ']], [['date' => '2026-02-30']], [['end' => '07:00']]];
    }
}
