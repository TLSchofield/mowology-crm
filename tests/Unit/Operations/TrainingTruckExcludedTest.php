<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A truck login (users.device_type = 'truck') is a vehicle: Otto never trains or certifies it. */
class TrainingTruckExcludedTest extends TestCase
{
    public function test_people_marks_trucks_and_crew_skips_them(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, role TEXT, device_type TEXT)");
        $db->exec("INSERT INTO users VALUES (1, 'Nigel Casey', 'staff', 'personal'), (2, 'DODGE RAM', 'staff', 'truck'), (3, 'Tim Schofield', 'admin', 'personal')");

        $svc = new TrainingService($db);
        $people = new ReflectionMethod($svc, 'people');
        $people->setAccessible(true);
        $crew = new ReflectionMethod($svc, 'crew');
        $crew->setAccessible(true);

        $p = $people->invoke($svc);
        $this->assertTrue($crew->invoke($svc, $p, 1));
        $this->assertFalse($crew->invoke($svc, $p, 2), 'truck is not crew');
        $this->assertFalse($crew->invoke($svc, $p, 3), 'office role is not crew');
    }
}
