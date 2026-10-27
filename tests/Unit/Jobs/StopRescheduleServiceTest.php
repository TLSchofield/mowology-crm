<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Exercises StopRescheduleService end to end against in-memory SQLite.
 * NOW() is shimmed since the service's SQL targets MySQL.
 */
final class StopRescheduleServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('NOW', static fn () => '2026-09-29 08:00:00', 0);

        $this->db->exec("CREATE TABLE calendar_stops (id INTEGER PRIMARY KEY, crew_id INT, property_id INT, stop_date TEXT, route_order INT, estimated_arrival TEXT, estimated_departure TEXT, status TEXT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, visit_number TEXT, stop_id INT, plan_id INT, status TEXT, original_scheduled_date TEXT, scheduled_date TEXT, scheduled_time_start TEXT, sequence_index INT, assigned_crew_id INT, status_changed_at TEXT, completed_at TEXT, completion_notes TEXT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, plan_number TEXT, estimated_duration_minutes INT)");
        // VisitCarryGuard looks for After photos before a move (2026-10-27).
        $this->db->exec("CREATE TABLE media_links (id INTEGER PRIMARY KEY, media_id INT, context_type TEXT, context_id INT, category TEXT)");
        $this->db->exec("CREATE TABLE media_assets (id INTEGER PRIMARY KEY, captured_at TEXT, created_at TEXT)");

        $this->db->exec("INSERT INTO job_plans (id, plan_number, estimated_duration_minutes) VALUES (1, 'JOB-2026-0001', 60), (2, 'JOB-2026-0002', 500)");
        // Stop 100: Fri Oct 2, crew 10, one scheduled + one completed visit.
        $this->db->exec("INSERT INTO calendar_stops (id, crew_id, property_id, stop_date, route_order) VALUES (100, 10, 1, '2026-10-02', 1)");
        $this->db->exec("INSERT INTO job_visits (id, stop_id, plan_id, status, scheduled_date) VALUES (200, 100, 1, 'scheduled', '2026-10-02')");
        $this->db->exec("INSERT INTO job_visits (id, stop_id, plan_id, status, scheduled_date) VALUES (201, 100, 1, 'completed', '2026-10-02')");
        // Two other stops already on Mon Oct 5 for a different property.
        $this->db->exec("INSERT INTO calendar_stops (id, crew_id, property_id, stop_date, route_order) VALUES (110, 10, 2, '2026-10-05', 1), (111, 11, 3, '2026-10-05', 4)");
    }

    private function stop(int $id): ?array
    {
        $row = $this->db->query("SELECT * FROM calendar_stops WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function visit(int $id): array
    {
        return $this->db->query("SELECT * FROM job_visits WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
    }

    public function testMovesOnlyScheduledVisitsAndLeavesFinishedWorkOnItsStop(): void
    {
        // Stop 100 holds a completed visit: the stop (and that history) stays on Oct 2; the
        // scheduled visit gets a new stop on the new day (2026-10-12).
        $result = (new StopRescheduleService($this->db))->reschedule(100, '2026-10-06');

        $this->assertNotSame(100, $result['stop_id']);
        $this->assertSame('2026-10-02', $result['old_date']);
        $this->assertFalse($result['merged']);
        $this->assertSame('2026-10-02', $this->stop(100)['stop_date']);
        $this->assertSame('2026-10-06', $this->stop($result['stop_id'])['stop_date']);
        $this->assertSame('2026-10-06', $this->visit(200)['scheduled_date']);
        $this->assertSame($result['stop_id'], (int)$this->visit(200)['stop_id']);
        $this->assertSame('2026-10-02', $this->visit(201)['scheduled_date']);
        $this->assertSame(100, (int)$this->visit(201)['stop_id']);
    }

    public function testAStopWithOnlyScheduledWorkMovesWhole(): void
    {
        $this->db->exec("DELETE FROM job_visits WHERE id = 201");
        $result = (new StopRescheduleService($this->db))->reschedule(100, '2026-10-06');
        $this->assertSame(100, $result['stop_id']);
        $this->assertSame('2026-10-06', $this->stop(100)['stop_date']);
    }

    public function testAppendToEndPutsStopLastOnTheNewDay(): void
    {
        $result = (new StopRescheduleService($this->db))->reschedule(100, '2026-10-05', null, null, true);

        $this->assertSame(5, $result['new_route_order']);
        $this->assertSame(5, (int)$this->stop($result['stop_id'])['route_order']);
    }

    public function testExplicitRouteOrderWinsOverAppend(): void
    {
        $result = (new StopRescheduleService($this->db))->reschedule(100, '2026-10-05', 2, '10:30', true);

        $stop = $this->stop($result['stop_id']);
        $this->assertSame(2, (int)$stop['route_order']);
        $this->assertSame('10:30', $stop['estimated_arrival']);
        $this->assertSame('10:30', $this->visit(200)['scheduled_time_start']);
    }

    public function testMergesIntoExistingStopForSamePropertyAndCrew(): void
    {
        $this->db->exec("INSERT INTO calendar_stops (id, crew_id, property_id, stop_date, route_order) VALUES (120, 10, 1, '2026-10-07', 1)");

        $result = (new StopRescheduleService($this->db))->reschedule(100, '2026-10-07');

        $this->assertTrue($result['merged']);
        $this->assertSame(120, $result['stop_id']);
        // The completed visit keeps its stop; the old stop is only removed when empty.
        $this->assertNotNull($this->stop(100));
        $this->assertSame(100, (int)$this->visit(201)['stop_id']);
        $this->db->exec("DELETE FROM job_visits WHERE id = 201");
        $this->db->exec("INSERT INTO job_visits (id, stop_id, plan_id, status, scheduled_date) VALUES (202, 100, 1, 'scheduled', '2026-10-02')");
        (new StopRescheduleService($this->db))->reschedule(100, '2026-10-07');
        $this->assertNull($this->stop(100));
        $this->assertSame(120, (int)$this->visit(200)['stop_id']);
        $this->assertSame('2026-10-07', $this->visit(200)['scheduled_date']);
    }

    public function testUnassignedStopMergesOnlyWithAnotherUnassignedStop(): void
    {
        $this->db->exec("INSERT INTO calendar_stops (id, crew_id, property_id, stop_date, route_order) VALUES (130, NULL, 1, '2026-10-02', 2)");
        $this->db->exec("INSERT INTO job_visits (id, stop_id, plan_id, status, scheduled_date) VALUES (230, 130, 1, 'scheduled', '2026-10-02')");
        $this->db->exec("INSERT INTO calendar_stops (id, crew_id, property_id, stop_date, route_order) VALUES (131, NULL, 1, '2026-10-08', 1), (132, 10, 1, '2026-10-09', 1)");

        $svc = new StopRescheduleService($this->db);
        $this->assertFalse($svc->reschedule(130, '2026-10-09')['merged']);
        $this->assertTrue($svc->reschedule(130, '2026-10-08')['merged']);
    }

    public function testCapacityWarningFiresOnlyWhenTheDayWouldOverflow(): void
    {
        $svc = new StopRescheduleService($this->db);
        $this->assertNull($svc->capacityWarning(100, '2026-10-05'));

        // Crew 10 already has a 500-minute job on Oct 5; +60 = 560 > 540.
        $this->db->exec("INSERT INTO job_visits (id, stop_id, plan_id, status, scheduled_date) VALUES (210, 110, 2, 'scheduled', '2026-10-05')");
        $warning = $svc->capacityWarning(100, '2026-10-05');

        $this->assertNotNull($warning);
        $this->assertSame(560, $warning['minutes_after']);
        $this->assertNull($svc->capacityWarning(100, '2026-10-02'), 'same date never warns');
    }

    public function testIsMovableNeedsScheduledWorkAndNoRunningTimer(): void
    {
        $svc = new StopRescheduleService($this->db);
        $this->assertTrue($svc->isMovable(100));

        $this->db->exec("UPDATE job_visits SET status = 'in_progress' WHERE id = 201");
        $this->assertFalse($svc->isMovable(100));

        $this->db->exec("UPDATE job_visits SET status = 'completed' WHERE stop_id = 100");
        $this->assertFalse($svc->isMovable(100));
    }

    public function testRejectsBadInput(): void
    {
        $svc = new StopRescheduleService($this->db);
        $this->assertFalse(StopRescheduleService::isValidDate('2026-02-30'));
        $this->assertFalse(StopRescheduleService::isValidDate('10/05/2026'));

        try {
            $svc->reschedule(999, '2026-10-05');
            $this->fail('expected Stop not found');
        } catch (Exception $e) {
            $this->assertSame('Stop not found', $e->getMessage());
        }

        $this->expectExceptionMessage('Invalid date format');
        $svc->reschedule(100, 'tomorrow');
    }

    public function testAScheduledVisitWithAnAfterPhotoIsCompletedHereAndReplacedOnTheNewDay(): void
    {
        // Tisdall, 2026-10-27: photographed Before and After, never marked complete, then the
        // stop was moved a week on — and the old photos travelled with it.
        $this->db->exec("UPDATE job_visits SET visit_number = 'JOB-2026-0001-V001', sequence_index = 1, assigned_crew_id = 10, scheduled_time_start = '09:00:00' WHERE id = 200");
        $this->db->exec("UPDATE job_visits SET sequence_index = 2 WHERE id = 201");
        $this->db->exec("INSERT INTO media_assets (id, captured_at, created_at) VALUES (5, '2026-10-02 10:40:00', '2026-10-02 10:41:00')");
        $this->db->exec("INSERT INTO media_links (media_id, context_type, context_id, category) VALUES (5, 'job_visit', 200, 'after')");

        $result = (new StopRescheduleService($this->db))->reschedule(100, '2026-10-09');

        // The photographed visit stays on Oct 2, completed at the After photo's time.
        $old = $this->visit(200);
        $this->assertSame('completed', $old['status']);
        $this->assertSame('2026-10-02', $old['scheduled_date']);
        $this->assertSame(100, (int)$old['stop_id']);
        $this->assertSame('2026-10-02 10:40:00', $old['completed_at']);
        $this->assertSame(VisitCarryGuard::NOTE, $old['completion_notes']);
        $this->assertSame([200], $result['settled_visit_ids']);

        // A fresh scheduled visit for the same plan and crew sits on the new day's stop.
        $this->assertCount(1, $result['fresh_visit_ids']);
        $fresh = $this->visit($result['fresh_visit_ids'][0]);
        $this->assertSame('scheduled', $fresh['status']);
        $this->assertSame('2026-10-09', $fresh['scheduled_date']);
        $this->assertSame($result['stop_id'], (int)$fresh['stop_id']);
        $this->assertSame(1, (int)$fresh['plan_id']);
        $this->assertSame(10, (int)$fresh['assigned_crew_id']);
        $this->assertSame('09:00:00', $fresh['scheduled_time_start']);
        $this->assertSame('JOB-2026-0001-V003', $fresh['visit_number']);
        $this->assertNull($fresh['completed_at']);
        $this->assertSame('2026-10-09', $this->stop($result['stop_id'])['stop_date']);
        $this->assertSame('2026-10-02', $this->stop(100)['stop_date']);
    }

    public function testAVisitWithoutProofOfWorkStillMovesAsBefore(): void
    {
        $result = (new StopRescheduleService($this->db))->reschedule(100, '2026-10-09');
        $this->assertSame([], $result['settled_visit_ids']);
        $this->assertSame([], $result['fresh_visit_ids']);
        $this->assertSame('scheduled', $this->visit(200)['status']);
        $this->assertSame('2026-10-09', $this->visit(200)['scheduled_date']);
    }
}
