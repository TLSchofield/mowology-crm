<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ScheduleListServiceTest extends TestCase
{
    private function stop(array $over = []): array
    {
        return array_merge([
            'stop_id' => 1, 'route_order' => 1, 'estimated_arrival' => '09:00:00',
            'property_name' => null, 'company_name' => null, 'contact_name' => 'Marc Nelitz',
            'property_address' => '2448 Larch Street', 'crew_names' => ['Nigel Casey', 'Tim Schofield'], 'crew_name' => null,
            'visits' => [['visit_id' => 10, 'plan_title' => '7 Day Lawn Cut Service', 'service_type' => 'Lawn Care', 'visit_status' => 'scheduled', 'scheduled_time_start' => null]],
        ], $over);
    }

    public function test_headline_prefers_building_then_company_then_client_and_never_the_address(): void
    {
        $this->assertSame('Dorset Tower', ScheduleListService::headline($this->stop(['property_name' => 'Dorset Tower', 'company_name' => 'Dorset Realty'])));
        $this->assertSame('Dorset Realty', ScheduleListService::headline($this->stop(['company_name' => 'Dorset Realty'])));
        $this->assertSame('Marc Nelitz', ScheduleListService::headline($this->stop()));
        $this->assertSame('Marc Nelitz', ScheduleListService::headline($this->stop(['property_name' => '2448 larch street'])));
        $this->assertSame('2448 Larch Street', ScheduleListService::headline($this->stop(['contact_name' => null])));
    }

    public function test_row_carries_service_crew_time_and_history(): void
    {
        $row = ScheduleListService::row($this->stop(), [10 => ['summary' => 'Last done 7 days ago']], '2026-11-12', '2026-11-12');
        $this->assertSame('9:00 AM', $row['time']);
        $this->assertSame(['7 Day Lawn Cut Service'], $row['services']);
        $this->assertSame(['Nigel Casey', 'Tim Schofield'], $row['crew']);
        $this->assertSame('Last done 7 days ago', $row['history']);
        $this->assertFalse($row['needs_attention']);
    }

    public function test_open_visit_on_a_past_day_needs_attention_but_a_completed_one_does_not(): void
    {
        $open = ScheduleListService::row($this->stop(), [], '2026-11-09', '2026-11-12');
        $this->assertTrue($open['needs_attention']);

        $stop = $this->stop(['visits' => [['visit_id' => 10, 'plan_title' => 'Lawn', 'visit_status' => 'completed']]]);
        $done = ScheduleListService::row($stop, [], '2026-11-09', '2026-11-12');
        $this->assertTrue($done['done']);
        $this->assertFalse($done['needs_attention']);
    }

    public function test_skip_line_wins_and_flags_an_open_stop(): void
    {
        $stop = $this->stop(['visits' => [
            ['visit_id' => 10, 'plan_title' => 'Lawn', 'visit_status' => 'scheduled'],
            ['visit_id' => 11, 'plan_title' => 'Edging', 'visit_status' => 'scheduled'],
        ]]);
        $row = ScheduleListService::row($stop, [10 => ['summary' => 'Last done 3 days ago'], 11 => ['summary' => 'Skipped Mon — last done 35 days ago']], '2026-11-12', '2026-11-12');
        $this->assertSame('Skipped Mon — last done 35 days ago', $row['history']);
        $this->assertTrue($row['needs_attention']);
        $this->assertSame(2, $row['visit_count']);
    }

    public function test_days_ascend_and_rows_sort_by_time_then_route_order(): void
    {
        $data = [
            '2026-11-13' => [$this->stop(['stop_id' => 3])],
            '2026-11-12' => [
                $this->stop(['stop_id' => 2, 'estimated_arrival' => '10:00:00']),
                $this->stop(['stop_id' => 1, 'estimated_arrival' => '08:00:00']),
                $this->stop(['stop_id' => 4, 'estimated_arrival' => null, 'visits' => []]),
            ],
        ];
        $days = ScheduleListService::rowsByDay($data, [], '2026-11-12');
        $this->assertSame(['2026-11-12', '2026-11-13'], array_keys($days));
        $this->assertSame([1, 2, 4], array_column($days['2026-11-12'], 'stop_id'));
    }
}
