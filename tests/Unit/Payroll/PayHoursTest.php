<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A pay run pays approved time only, breaks off, and says which days it left out (Fable review 2026-10-21). */
class PayHoursTest extends TestCase
{
    public function test_only_approved_weeks_are_paid_and_the_rest_are_listed(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE time_clock_entries (id INTEGER PRIMARY KEY, user_id INT, clock_in TEXT, total_minutes INT, status TEXT)");
        $db->exec("CREATE TABLE timesheets (id INTEGER PRIMARY KEY, user_id INT, week_start TEXT, week_end TEXT, status TEXT)");
        $db->exec("INSERT INTO timesheets (user_id, week_start, week_end, status) VALUES (7, '2026-10-12', '2026-10-18', 'approved'), (7, '2026-10-19', '2026-10-25', 'submitted')");
        $db->exec("INSERT INTO time_clock_entries (user_id, clock_in, total_minutes, status) VALUES
            (7, '2026-10-13 07:30:00', 300, 'completed'), (7, '2026-10-13 13:00:00', 240, 'edited'),
            (7, '2026-10-14 07:30:00', 500, 'void'),
            (7, '2026-10-20 07:30:00', 480, 'completed'),
            (8, '2026-10-13 07:30:00', 480, 'completed')");
        $breaks = fn(int $u, string $f, string $t) => ['days' => ['2026-10-13' => ['unpaid_minutes' => 30]]];
        $h = (new PayHours($db, $breaks))->forPeriod(7, '2026-10-12', '2026-10-25');
        $this->assertSame(['2026-10-13' => 510], $h['days'], 'two shifts, a void one ignored, 30 min unpaid break off');
        $this->assertSame(['2026-10-20' => 480], $h['unapproved'], 'not approved yet: listed, not paid');
    }

    /** Codex 2026-10-23: two timesheets covering the same day must not double the minutes. */
    public function test_overlapping_timesheets_count_a_shift_once(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE time_clock_entries (id INTEGER PRIMARY KEY, user_id INT, clock_in TEXT, total_minutes INT, status TEXT)");
        $db->exec("CREATE TABLE timesheets (id INTEGER PRIMARY KEY, user_id INT, week_start TEXT, week_end TEXT, status TEXT)");
        $db->exec("INSERT INTO timesheets (user_id, week_start, week_end, status) VALUES (7, '2026-10-12', '2026-10-18', 'approved'), (7, '2026-10-12', '2026-10-18', 'pending')");
        $db->exec("INSERT INTO time_clock_entries (user_id, clock_in, total_minutes, status) VALUES (7, '2026-10-13 07:30:00', 480, 'completed')");
        $h = (new PayHours($db, fn() => ['days' => []]))->forPeriod(7, '2026-10-12', '2026-10-18');
        $this->assertSame(['2026-10-13' => 480], $h['days']);
    }
}

