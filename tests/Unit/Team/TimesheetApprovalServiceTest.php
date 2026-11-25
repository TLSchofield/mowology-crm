<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Bulk and single approval share one set of checks (Codex review 2026-10-14). */
class TimesheetApprovalServiceTest extends TestCase
{
    private PDO $db;
    private array $recalced = [];

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('NOW', fn() => '2026-10-14 12:00:00');
        $this->db->exec("CREATE TABLE timesheets (id INTEGER PRIMARY KEY, user_id INT, week_start TEXT, status TEXT,
                         reviewed_by INT, reviewed_at TEXT, reviewer_notes TEXT)");
        $this->db->exec("CREATE TABLE time_clock_entries (id INTEGER PRIMARY KEY, user_id INT, status TEXT, clock_in TEXT, clock_out TEXT)");
        $this->db->exec("CREATE TABLE job_time_entries (id INTEGER PRIMARY KEY, user_id INT, status TEXT, start_time TEXT, end_time TEXT)");
        $this->db->exec("INSERT INTO timesheets (id, user_id, week_start, status) VALUES
                         (1, 7, '2026-10-05', 'submitted'),   -- fine
                         (2, 9, '2026-10-05', 'submitted'),   -- the reviewer's own week
                         (3, 8, '2026-10-12', 'submitted'),   -- a shift still running
                         (4, 7, '2026-09-28', 'approved')");
        $this->db->exec("INSERT INTO time_clock_entries VALUES (1, 8, 'active', '2026-10-14 07:00:00', NULL)");
    }

    private function svc(): TimesheetApprovalService
    {
        return new TimesheetApprovalService($this->db, function (int $u, string $w) { $this->recalced[] = "$u@$w"; });
    }

    public function test_bulk_runs_every_check_and_says_why(): void
    {
        $res = $this->svc()->approveMany([1, 2, 3, 4, 1], 9);
        $this->assertSame(1, $res['approved']);
        $this->assertSame('You cannot approve your own timesheet.', $res['refused'][2]);
        $this->assertArrayHasKey(3, $res['refused']);
        $this->assertStringContainsString('already approved', $res['refused'][4]);
        $this->assertSame(['7@2026-10-05'], $this->recalced);   // recalculated only the one it locked
        $this->assertSame('approved', $this->db->query("SELECT status FROM timesheets WHERE id = 1")->fetchColumn());
        $this->assertSame('submitted', $this->db->query("SELECT status FROM timesheets WHERE id = 3")->fetchColumn());
    }

    public function test_a_second_approval_changes_nothing(): void
    {
        $this->assertNull($this->svc()->approve(1, 9, 'ok'));
        $this->assertStringContainsString('already approved', (string)$this->svc()->approve(1, 9));
    }
}
