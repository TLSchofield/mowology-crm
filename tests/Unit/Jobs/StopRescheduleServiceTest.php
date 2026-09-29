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

        $this->db->exec("CREATE TABLE calendar_stops (id INTEGER PRIMARY KEY, crew_id INT, property_id INT, stop_date TEXT, route_order INT, estimated_arrival TEXT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, stop_id INT, plan_id INT, status TEXT, scheduled_date TEXT, scheduled_time_start TEXT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, estimated_duration_minutes INT)");

        $this->db->exec("INSERT INTO job_plans (id, estimated_duration_minutes) VALUES (1, 60), (2, 500)");
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

    public function testMovesStopAndOnlyItsScheduledVisits(): void
    {
        $result = (new StopRescheduleService($this->db))->reschedule(100, '2026-10-06');

        $this->assertSame(100, $result['stop_id']);
        $this->assertSame('2026-10-02', $result['old_date']);
        $this->assertFalse($result['merged']);
        $this->assertSame('2026-10-06', $this->stop(100)['stop_date']);
        $this->assertSame('2026-10-06', $this->visit(200)['scheduled_date']);
        // Finished work stays on the day it was done.
        $this->assertSame('2026-10-02', $this->visit(201)['scheduled_date']);
    }

    public function testAppendToEndPutsStopLastOnTheNewDay(): void
    {
        $result = (new StopRescheduleService($this->db))->reschedule(100, '2026-10-05', null, null, true);

        $this->assertSame(5, $result['new_route_order']);
        $this->assertSame(5, (int)$this->stop(100)['route_order']);
    }

    public function testExplicitRouteOrderWinsOverAppend(): void
    {
        (new StopRescheduleService($this->db))->reschedule(100, '2026-10-05', 2, '10:30', true);

        $stop = $this->stop(100);
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
}
