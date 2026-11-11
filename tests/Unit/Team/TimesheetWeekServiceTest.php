<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The week grid: days, totals, overtime, flags, and the reopen rule for an approved week. */
class TimesheetWeekServiceTest extends TestCase
{
    private function shift(int $id, string $in, string $out, int $min, string $status = 'completed'): array
    {
        return ['id' => $id, 'clock_in' => $in, 'clock_out' => $out, 'total_minutes' => $min, 'status' => $status, 'notes' => ''];
    }

    public function test_the_week_runs_monday_to_sunday_and_sunday_belongs_to_the_week_before(): void
    {
        $this->assertSame('2026-10-05', TimesheetWeekService::weekStartOf('2026-10-07'));
        $this->assertSame('2026-10-05', TimesheetWeekService::weekStartOf('2026-10-11'), 'Sunday');
        $this->assertSame('2026-10-12', TimesheetWeekService::weekStartOf('2026-10-12'));
        $d = TimesheetWeekService::weekDates('2026-10-05');
        $this->assertSame(['2026-10-05', '2026-10-11'], [$d[0], $d[6]]);
    }

    public function test_minutes_read_as_hours_and_minutes(): void
    {
        $this->assertSame('7h 32m', TimesheetWeekService::fmt(452));
        $this->assertSame('8h', TimesheetWeekService::fmt(480));
        $this->assertSame('45m', TimesheetWeekService::fmt(45));
        $this->assertSame('—', TimesheetWeekService::fmt(0));
    }

    public function test_nigels_real_week_adds_up_by_day(): void
    {
        // Jobber's Oct 5-11: 6h50, 7h32, 7h30, nothing Thursday, 7h11.
        $clock = [
            $this->shift(1, '2026-10-05 08:03:00', '2026-10-05 14:53:00', 410),
            $this->shift(2, '2026-10-06 07:47:00', '2026-10-06 15:19:00', 452),
            $this->shift(3, '2026-10-07 07:41:00', '2026-10-07 15:11:00', 450),
            $this->shift(4, '2026-10-09 07:50:00', '2026-10-09 15:01:00', 431),
        ];
        $p = TimesheetWeekService::buildPerson('2026-10-05', ['id' => 9, 'name' => 'Nigel Casey', 'rate' => 37.0], $clock, [], [], ['id' => 103, 'status' => 'submitted']);
        $this->assertSame(410, $p['days']['2026-10-05']['shift_min']);
        $this->assertSame(0, $p['days']['2026-10-08']['shift_min']);
        $this->assertSame(1743, $p['total_min']);   // 29h03
        $this->assertSame(0, $p['ot_min']);
        $this->assertSame('NC', $p['initials']);
        $this->assertSame('Waiting for approval', $p['status_word']);
        $this->assertFalse($p['locked']);
    }

    public function test_a_long_day_is_overtime_and_a_voided_shift_is_ignored(): void
    {
        $clock = [
            $this->shift(1, '2026-10-06 07:47:00', '2026-10-06 19:47:00', 720),
            $this->shift(2, '2026-10-06 20:00:00', '2026-10-06 21:00:00', 60, 'void'),
        ];
        $p = TimesheetWeekService::buildPerson('2026-10-05', ['id' => 9, 'name' => 'Nigel Casey', 'rate' => 37.0], $clock, [], [], null);
        $this->assertSame(720, $p['days']['2026-10-06']['shift_min']);
        $this->assertSame(240, $p['ot_min'], '12 h: 8 regular, 4 at 1.5x');
        $this->assertCount(1, $p['days']['2026-10-06']['entries']);
        $this->assertSame('Open', $p['status_word']);
        $this->assertNull($p['timesheet_id']);
    }

    public function test_a_timer_that_ran_past_the_shift_is_flagged_with_how_much(): void
    {
        $clock = [$this->shift(1, '2026-10-06 07:47:00', '2026-10-06 19:47:00', 720)];
        $jobs = [['id' => 5, 'start_time' => '2026-10-06 15:26:00', 'end_time' => '2026-10-07 20:00:00', 'duration_minutes' => 1713,
                  'status' => 'completed', 'job_title' => 'FULL MAINTENANCE', 'job_number' => 'V012', 'property_address' => '998 West 19th Avenue']];
        $p = TimesheetWeekService::buildPerson('2026-10-05', ['id' => 9, 'name' => 'Nigel Casey', 'rate' => 37.0], $clock, $jobs, [], null);
        $day = $p['days']['2026-10-06'];
        $this->assertSame(1713 - 720, $day['anomaly_min']);
        $this->assertTrue($day['jobs'][0]['next_day']);
        $this->assertSame('timer_past_shift', $p['flags'][0]['code']);
    }

    public function test_a_running_shift_is_open_and_counts_nothing_yet(): void
    {
        $clock = [['id' => 1, 'clock_in' => '2026-10-08 07:30:00', 'clock_out' => null, 'total_minutes' => null, 'status' => 'active', 'notes' => '']];
        $p = TimesheetWeekService::buildPerson('2026-10-05', ['id' => 9, 'name' => 'A B', 'rate' => 30.0], $clock, [], [], null);
        $this->assertTrue($p['days']['2026-10-08']['open']);
        $this->assertSame(0, $p['total_min']);
        $this->assertSame('shift_running', $p['flags'][0]['code']);
        $this->assertNull($p['days']['2026-10-08']['entries'][0]['out']);
    }

    public function test_an_approved_week_is_marked_locked(): void
    {
        $p = TimesheetWeekService::buildPerson('2026-10-05', ['id' => 9, 'name' => 'A B', 'rate' => 30.0], [], [], [], ['id' => 7, 'status' => 'approved']);
        $this->assertTrue($p['locked']);
        $this->assertSame('Approved', $p['status_word']);
    }

    // ── Reopening an approved week ─────────────────────────────────────────────

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE timesheets (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT, reviewer_notes TEXT)");
        $db->exec("INSERT INTO timesheets VALUES (103, 9, 'approved', NULL), (104, 9, 'submitted', NULL), (105, 5, 'approved', 'earlier note')");
        return $db;
    }

    public function test_reopening_needs_a_reason_and_goes_back_to_waiting_for_approval(): void
    {
        $db = $this->db();
        $admin = ['id' => 1, 'role' => 'admin', 'full_name' => 'Tim'];
        $this->assertNotNull(TimesheetReopenService::reopen($db, 103, $admin, '  '), 'no reason, no reopen');
        $this->assertSame('approved', $db->query("SELECT status FROM timesheets WHERE id = 103")->fetchColumn());

        $this->assertNull(TimesheetReopenService::reopen($db, 103, $admin, 'Nigel timer ran overnight'));
        $this->assertSame('submitted', $db->query("SELECT status FROM timesheets WHERE id = 103")->fetchColumn());
        $this->assertStringContainsString('Nigel timer ran overnight', (string)$db->query("SELECT reviewer_notes FROM timesheets WHERE id = 103")->fetchColumn());
        $this->assertStringContainsString('by Tim', (string)$db->query("SELECT reviewer_notes FROM timesheets WHERE id = 103")->fetchColumn());
    }

    public function test_an_earlier_review_note_is_kept(): void
    {
        $db = $this->db();
        $this->assertNull(TimesheetReopenService::reopen($db, 105, ['id' => 1, 'role' => 'manager', 'full_name' => 'Tim'], 'wrong day'));
        $notes = (string)$db->query("SELECT reviewer_notes FROM timesheets WHERE id = 105")->fetchColumn();
        $this->assertStringContainsString('earlier note', $notes);
        $this->assertStringContainsString('wrong day', $notes);
    }

    public function test_nobody_reopens_their_own_week_and_crew_cannot_reopen_at_all(): void
    {
        $db = $this->db();
        $this->assertNotNull(TimesheetReopenService::reopen($db, 103, ['id' => 9, 'role' => 'admin', 'full_name' => 'Nigel'], 'because'));
        $this->assertNotNull(TimesheetReopenService::reopen($db, 103, ['id' => 3, 'role' => 'crew', 'full_name' => 'Crew'], 'because'));
        $this->assertSame('approved', $db->query("SELECT status FROM timesheets WHERE id = 103")->fetchColumn());
    }

    public function test_an_already_open_week_is_left_alone_and_a_missing_one_is_refused(): void
    {
        $db = $this->db();
        $admin = ['id' => 1, 'role' => 'admin', 'full_name' => 'Tim'];
        $this->assertNull(TimesheetReopenService::reopen($db, 104, $admin, 'check'));
        $this->assertNull($db->query("SELECT reviewer_notes FROM timesheets WHERE id = 104")->fetchColumn(), 'nothing appended to a week that was not approved');
        $this->assertNotNull(TimesheetReopenService::reopen($db, 999, $admin, 'check'));
    }
}
