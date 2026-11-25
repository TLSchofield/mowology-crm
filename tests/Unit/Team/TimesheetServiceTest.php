<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TimesheetService's pure week-shaping logic (mobile My Timesheet).
 */
class TimesheetServiceTest extends TestCase
{
    public function test_week_start_is_monday_of_given_date(): void
    {
        // 2026-09-16 is a Wednesday
        $this->assertSame('2026-09-14', TimesheetService::weekStartFor('2026-09-16'));
        $this->assertSame('2026-09-14', TimesheetService::weekStartFor('2026-09-14'));
        // Sunday belongs to the week that started the previous Monday
        $this->assertSame('2026-09-14', TimesheetService::weekStartFor('2026-09-20'));
    }

    public function test_week_start_falls_back_to_now_on_bad_input(): void
    {
        $now = strtotime('2026-09-18 10:00:00');
        $this->assertSame('2026-09-14', TimesheetService::weekStartFor('not-a-date', $now));
        $this->assertSame('2026-09-14', TimesheetService::weekStartFor('', $now));
    }

    public function test_week_always_has_seven_days_mon_to_sun(): void
    {
        $week = TimesheetService::buildWeek([], [], '2026-09-14', time());
        $this->assertCount(7, $week['days']);
        $this->assertSame('2026-09-14', $week['days'][0]['date']);
        $this->assertSame('2026-09-20', $week['days'][6]['date']);
        $this->assertSame('2026-09-20', $week['week_end']);
        $this->assertSame(0, $week['total_seconds']);
    }

    public function test_closed_entries_sum_per_day_and_week(): void
    {
        $clock = [
            ['id' => 1, 'clock_in' => '2026-09-14 08:00:00', 'clock_out' => '2026-09-14 12:00:00', 'status' => 'completed', 'notes' => ''],
            ['id' => 2, 'clock_in' => '2026-09-14 13:00:00', 'clock_out' => '2026-09-14 16:30:00', 'status' => 'edited', 'notes' => 'fixed by office'],
            ['id' => 3, 'clock_in' => '2026-09-15 08:00:00', 'clock_out' => '2026-09-15 09:00:00', 'status' => 'completed', 'notes' => null],
        ];
        $week = TimesheetService::buildWeek($clock, [], '2026-09-14', time());

        $this->assertSame(4 * 3600 + 12600, $week['days'][0]['total_seconds']);
        $this->assertSame(3600, $week['days'][1]['total_seconds']);
        $this->assertSame(4 * 3600 + 12600 + 3600, $week['total_seconds']);
        $this->assertTrue($week['days'][0]['entries'][1]['edited']);
        $this->assertSame('fixed by office', $week['days'][0]['entries'][1]['notes']);
        $this->assertNull($week['days'][0]['entries'][0]['notes']);
    }

    public function test_open_entry_counts_up_to_now(): void
    {
        $now   = strtotime('2026-09-16 10:30:00');
        $clock = [['id' => 9, 'clock_in' => '2026-09-16 08:00:00', 'clock_out' => null, 'status' => 'active']];
        $week  = TimesheetService::buildWeek($clock, [], '2026-09-14', $now);

        $entry = $week['days'][2]['entries'][0];
        $this->assertTrue($entry['is_open']);
        $this->assertSame(9000, $entry['duration_seconds']);
        $this->assertSame(9000, $week['total_seconds']);
    }

    public function test_entries_outside_the_week_are_ignored(): void
    {
        $clock = [['id' => 1, 'clock_in' => '2026-09-21 08:00:00', 'clock_out' => '2026-09-21 09:00:00', 'status' => 'completed']];
        $week  = TimesheetService::buildWeek($clock, [], '2026-09-14', time());
        $this->assertSame(0, $week['total_seconds']);
    }

    public function test_job_time_is_tracked_separately_from_clock_time(): void
    {
        $jobs = [
            ['id' => 5, 'visit_id' => 77, 'job_title' => 'Weekly mow', 'property_address' => '12 Elm St',
             'start_time' => '2026-09-14 08:15:00', 'end_time' => '2026-09-14 09:00:00', 'duration_minutes' => 45],
            // No end_time recorded — fall back to duration_minutes
            ['id' => 6, 'visit_id' => 78, 'job_title' => 'Hedge trim', 'property_address' => null,
             'start_time' => '2026-09-14 10:00:00', 'end_time' => null, 'duration_minutes' => 30],
        ];
        $week = TimesheetService::buildWeek([], $jobs, '2026-09-14', time());

        $this->assertSame(2700 + 1800, $week['days'][0]['job_seconds']);
        $this->assertSame(2700 + 1800, $week['job_total_seconds']);
        $this->assertSame(0, $week['total_seconds']);
        $this->assertSame(77, $week['days'][0]['jobs'][0]['visit_id']);
    }
}
