<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Who may record what was done at a snow & salt stop (it sets what the run is billed).
 * Codex review 2026-10-15: the web endpoint let any login change any stop; the app endpoint
 * refused a second crew member on the same stop.
 */
class SnowRouteAccessTest extends TestCase
{
    private function svc(): SnowContractService
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, assigned_crew_id INT, stop_id INT, plan_id INT)");
        $db->exec("CREATE TABLE calendar_stops (id INTEGER PRIMARY KEY, crew_id INT)");
        $db->exec("CREATE TABLE calendar_stop_crew (stop_id INT, user_id INT)");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, device_type TEXT)");
        $db->exec("INSERT INTO calendar_stops VALUES (10, 3)");
        $db->exec("INSERT INTO calendar_stop_crew VALUES (10, 4)");
        $db->exec("INSERT INTO job_visits VALUES (1, 2, 10, 7), (2, NULL, NULL, 7)");
        $db->exec("INSERT INTO users VALUES (2, NULL), (3, NULL), (4, NULL), (5, NULL), (9, 'truck')");
        return new SnowContractService($db);
    }

    public function test_the_crew_on_the_stop_may_record_it(): void
    {
        $s = $this->svc();
        $this->assertTrue($s->mayRecord(1, 2), 'the visit crew');
        $this->assertTrue($s->mayRecord(1, 3), "the stop's lead");
        $this->assertTrue($s->mayRecord(1, 4), 'a second crew member on the stop (the app used to refuse them)');
        $this->assertTrue($s->mayRecord(2, 9), 'the truck login, any stop');
    }

    public function test_anyone_else_may_not(): void
    {
        $s = $this->svc();
        $this->assertFalse($s->mayRecord(1, 5), 'another crew');
        $this->assertFalse($s->mayRecord(2, 2), 'a stop with no crew');
        $this->assertFalse($s->mayRecord(99, 2), 'no such visit');
        $this->assertFalse($s->mayRecord(1, 0));
    }
}
