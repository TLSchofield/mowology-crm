<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class InvoiceCrewTimeTest extends TestCase
{
    /** @test */
    public function it_sums_two_people_and_reports_the_wall_clock_span(): void
    {
        $entries = [
            $this->entry(1, 'Alex', '2026-10-01 10:00:00', '2026-10-01 10:46:00'),
            $this->entry(2, 'Sam', '2026-10-01 10:02:00', '2026-10-01 10:48:00'),
        ];

        $result = InvoiceCrewTime::summarise($entries, null, strtotime('2026-10-01 11:00:00'));

        $this->assertSame(2, $result['crew_count']);
        $this->assertSame(92, $result['crew_minutes']);
        $this->assertSame('2026-10-01 10:00:00', $result['arrived']);
        $this->assertSame('2026-10-01 10:48:00', $result['left']);
        $this->assertSame(48, $result['on_site_minutes']);
    }

    /** @test */
    public function it_merges_multiple_rows_for_one_person(): void
    {
        $entries = [
            $this->entry(7, 'Jo', '2026-10-01 09:00:00', '2026-10-01 09:20:00'),
            $this->entry(7, 'Jo', '2026-10-01 09:30:00', '2026-10-01 09:45:00'),
        ];

        $result = InvoiceCrewTime::summarise($entries, null, strtotime('2026-10-01 10:00:00'));

        $this->assertSame(1, $result['crew_count']);
        $this->assertSame([['name' => 'Jo', 'minutes' => 35]], $result['people']);
    }

    /** @test */
    public function it_ignores_travel_apportioned_rows(): void
    {
        $entry = $this->entry(1, 'Alex', '2026-10-01 08:00:00', '2026-10-01 08:30:00');
        $entry['time_source'] = 'travel_apportioned';

        $result = InvoiceCrewTime::summarise([$entry], null, strtotime('2026-10-01 09:00:00'));

        $this->assertFalse($result['has_time']);
        $this->assertSame('none', $result['source']);
        $this->assertSame(0, $result['crew_count']);
    }

    /** @test */
    public function estimated_time_counts_but_does_not_change_real_arrival_or_departure(): void
    {
        $real = $this->entry(1, 'Alex', '2026-10-01 10:00:00', '2026-10-01 10:20:00');
        $estimated = $this->entry(2, 'Sam', '2026-10-01 00:00:00', null, 30, 'completed', 'cluster_apportioned');

        $result = InvoiceCrewTime::summarise([$estimated, $real], null, strtotime('2026-10-01 11:00:00'));
        $this->assertSame(50, $result['crew_minutes']);
        $this->assertTrue($result['estimated']);
        $this->assertSame('2026-10-01 10:00:00', $result['arrived']);
        $this->assertSame('2026-10-01 10:20:00', $result['left']);
        // The estimate may carry a crews.id in user_id: pooled, never named or counted as a person.
        $this->assertSame(1, $result['crew_count']);
        $this->assertSame(['Estimated', 'Alex'], array_column($result['people'], 'name'));

        $onlyEstimated = InvoiceCrewTime::summarise([$estimated], null, strtotime('2026-10-01 11:00:00'));
        $this->assertTrue($onlyEstimated['has_time']);
        $this->assertNull($onlyEstimated['arrived']);
        $this->assertNull($onlyEstimated['left']);
        $this->assertNull($onlyEstimated['on_site_minutes']);
        $this->assertSame(0, $onlyEstimated['crew_count']);
    }

    /** @test */
    public function a_running_timer_uses_now_and_has_no_departure(): void
    {
        $entry = $this->entry(1, 'Alex', '2026-10-01 10:00:00', null, null, 'active');

        $result = InvoiceCrewTime::summarise([$entry], null, strtotime('2026-10-01 10:20:00'));

        $this->assertSame(20, $result['crew_minutes']);
        $this->assertTrue($result['running']);
        $this->assertNull($result['left']);
    }

    /** @test */
    public function it_falls_back_to_visit_duration_when_there_are_no_entries(): void
    {
        $visit = [
            'actual_duration_minutes' => 45,
            'started_at' => '2026-10-01 10:00:00',
            'completed_at' => '2026-10-01 10:45:00',
        ];

        $result = InvoiceCrewTime::summarise([], $visit, strtotime('2026-10-01 11:00:00'));

        $this->assertSame('visit', $result['source']);
        $this->assertSame(45, $result['crew_minutes']);
        $this->assertSame(45, $result['on_site_minutes']);
    }

    /** @test */
    public function empty_entries_and_visit_have_no_time(): void
    {
        $result = InvoiceCrewTime::summarise([], [], strtotime('2026-10-01 11:00:00'));

        $this->assertFalse($result['has_time']);
        $this->assertSame('none', $result['source']);
    }

    /** @test */
    public function it_formats_minutes_compactly(): void
    {
        $this->assertSame('0 min', InvoiceCrewTime::formatMinutes(0));
        $this->assertSame('46 min', InvoiceCrewTime::formatMinutes(46));
        $this->assertSame('1 h', InvoiceCrewTime::formatMinutes(60));
        $this->assertSame('1 h 32 min', InvoiceCrewTime::formatMinutes(92));
    }

    /** @test */
    public function database_failures_return_an_empty_summary(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willThrowException(new PDOException('database unavailable'));

        $result = (new InvoiceCrewTime($db))->forVisit(123);

        $this->assertFalse($result['has_time']);
        $this->assertSame('none', $result['source']);
    }

    private function entry(int $userId, string $name, string $start, ?string $end,
        ?int $duration = null, string $status = 'completed', string $source = 'gps'): array
    {
        return [
            'user_id' => $userId,
            'full_name' => $name,
            'start_time' => $start,
            'end_time' => $end,
            'duration_minutes' => $duration,
            'status' => $status,
            'time_source' => $source,
        ];
    }
}
