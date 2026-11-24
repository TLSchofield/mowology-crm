<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Exercises CrewAssignmentService::assignCrew() end to end against in-memory
 * SQLite. NOW()/CURDATE() are shimmed since the service's SQL targets MySQL.
 */
final class CrewAssignmentServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('NOW', static fn () => '2026-09-24 08:00:00', 0);
        $this->db->sqliteCreateFunction('CURDATE', static fn () => '2026-09-24', 0);

        $this->db->exec("CREATE TABLE calendar_stops (id INTEGER PRIMARY KEY, crew_id INT, property_id INT, stop_date TEXT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE calendar_stop_crew (id INTEGER PRIMARY KEY, stop_id INT, user_id INT)");
        $this->db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, stop_id INT, plan_id INT, status TEXT, scheduled_date TEXT, assigned_crew_id INT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, is_active INT)");
        $this->db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT)");

        $this->db->exec("INSERT INTO properties (id, address, city) VALUES (1, '123 Main St', 'Vancouver')");
        $this->db->exec("INSERT INTO users (id, full_name, is_active) VALUES (10, 'Alice Crew', 1)");
        $this->db->exec("INSERT INTO users (id, full_name, is_active) VALUES (11, 'Bob Crew', 1)");
        $this->db->exec("INSERT INTO users (id, full_name, is_active) VALUES (12, 'Inactive Ivan', 0)");
        $this->db->exec("INSERT INTO calendar_stops (id, crew_id, property_id, stop_date) VALUES (100, NULL, 1, '2026-09-24')");
        $this->db->exec("INSERT INTO job_visits (id, stop_id, plan_id, status, scheduled_date, assigned_crew_id) VALUES (200, 100, 1, 'scheduled', '2026-09-24', NULL)");
    }

    private function crewIdsForStop(int $stopId): array
    {
        $stmt = $this->db->prepare("SELECT user_id FROM calendar_stop_crew WHERE stop_id = ? ORDER BY id");
        $stmt->execute([$stopId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testAssigningCrewSyncsJunctionTableLeadCrewAndVisit(): void
    {
        $svc = new CrewAssignmentService($this->db);
        $result = $svc->assignCrew(100, [11, 10]); // order matters: 11 is lead

        $this->assertTrue($result['success']);
        $this->assertSame(11, $result['crew_id']);
        $this->assertSame([11, 10], $result['crew_ids']);
        $this->assertSame(['Bob Crew', 'Alice Crew'], $result['crew_names']);
        $this->assertSame([11, 10], $this->crewIdsForStop(100));

        $stop = $this->db->query("SELECT crew_id FROM calendar_stops WHERE id = 100")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(11, (int)$stop['crew_id']);

        $visit = $this->db->query("SELECT assigned_crew_id FROM job_visits WHERE id = 200")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(11, (int)$visit['assigned_crew_id']);
    }

    public function testUnassigningClearsCrewAndJunctionRows(): void
    {
        $svc = new CrewAssignmentService($this->db);
        $svc->assignCrew(100, [10]);
        $this->assertSame([10], $this->crewIdsForStop(100));

        $result = $svc->assignCrew(100, []);

        $this->assertSame([], $result['crew_ids']);
        $this->assertSame('Unassigned', $result['crew_name']);
        $this->assertSame([], $this->crewIdsForStop(100));

        $stop = $this->db->query("SELECT crew_id FROM calendar_stops WHERE id = 100")->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($stop['crew_id']);
    }

    public function testInactiveOrMissingCrewMemberIsRejected(): void
    {
        $svc = new CrewAssignmentService($this->db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Crew member #12 not found or inactive');
        $svc->assignCrew(100, [12]);
    }

    public function testUnknownStopIsRejected(): void
    {
        $svc = new CrewAssignmentService($this->db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Stop not found');
        $svc->assignCrew(999, [10]);
    }

    public function testDuplicateStopSameCrewSamePropertySameDateIsRejected(): void
    {
        $this->db->exec("INSERT INTO calendar_stops (id, crew_id, property_id, stop_date) VALUES (101, 10, 1, '2026-09-24')");

        $svc = new CrewAssignmentService($this->db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('A stop already exists for this property, date, and crew');
        $svc->assignCrew(100, [10]);
    }

    public function testDuplicateCheckIsNullSafeForUnassignedStops(): void
    {
        // A second unassigned stop for the same property/date must not collide with
        // stop 100 (also unassigned) via a naive `crew_id = NULL` comparison.
        $this->db->exec("INSERT INTO calendar_stops (id, crew_id, property_id, stop_date) VALUES (101, NULL, 1, '2026-09-24')");

        $svc = new CrewAssignmentService($this->db);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('A stop already exists for this property, date, and crew');
        $svc->assignCrew(100, []); // still unassigned — collides with stop 101, also unassigned
    }

    public function testLegacySingleCrewIdIsAcceptedAsLead(): void
    {
        $svc = new CrewAssignmentService($this->db);
        $result = $svc->assignCrew(100, [10]);

        $this->assertSame(10, $result['crew_id']);
        $this->assertSame([10], $result['crew_ids']);
    }
}
