<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The week strip's work dot, the same rule as the iPhone (week.php / WeekStripView). */
class WeekStripServiceTest extends TestCase
{
    public function test_dot_rule(): void
    {
        $this->assertNull(WeekStripService::dot(0, 0), 'no stops, no dot');
        $this->assertSame('todo', WeekStripService::dot(4, 1));
        $this->assertSame('done', WeekStripService::dot(4, 0));
    }

    public function test_dots_for_a_week_with_crew_filter(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE calendar_stops (id INTEGER PRIMARY KEY, stop_date TEXT, crew_id INT)");
        $db->exec("CREATE TABLE calendar_stop_crew (stop_id INT, user_id INT)");
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, stop_id INT, status TEXT)");
        $db->exec("INSERT INTO calendar_stops VALUES (1, '2026-10-12', 6), (2, '2026-10-14', 6), (3, '2026-10-14', 9), (4, '2026-10-15', 9)");
        $db->exec("INSERT INTO calendar_stop_crew VALUES (4, 6)");
        $db->exec("INSERT INTO job_visits VALUES (1, 1, 'completed'), (2, 2, 'scheduled'), (3, 3, 'completed'), (4, 4, 'skipped'), (5, 4, 'cancelled')");
        $svc = new WeekStripService($db);
        $this->assertSame(['2026-10-12' => 'done', '2026-10-14' => 'todo', '2026-10-15' => 'done'], $svc->dots('2026-10-12'));
        $this->assertSame(['2026-10-12' => 'done', '2026-10-14' => 'todo', '2026-10-15' => 'done'], $svc->dots('2026-10-12', 6), 'their own stops and stops they are listed on');
        $this->assertSame(['2026-10-14' => 'done', '2026-10-15' => 'done'], $svc->dots('2026-10-12', 9));
    }
}
