<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Otto's strip on the schedule: which of his desk items belong to the day in view, the day checks
 * worked out here (overbooked crew, empty stop, no border), and the 5-minute cache.
 *
 * Tue 2026-10-06: Nigel has 3 stops with 600 min of plan lengths (over 540); property 22 has no pin,
 * 23 has a pin and no border, 24 has a drawn border; an empty stop sits at 25. Otto's desk carries
 * items for this day, the day before, and property / plan items in and out of the day.
 */
class OttoScheduleStripTest extends TestCase
{
    private const DAY = '2026-10-06';

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        foreach ([
            'CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, latitude REAL, longitude REAL)',
            'CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INT, estimated_duration_minutes INT, status TEXT)',
            'CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, scheduled_date TEXT, status TEXT, stop_id INT, assigned_crew_id INT)',
            'CREATE TABLE calendar_stops (id INTEGER PRIMARY KEY, property_id INT, stop_date TEXT, status TEXT, crew_id INT, created_at TEXT)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)',
            'CREATE TABLE job_geofences (id INTEGER PRIMARY KEY, property_id INT, zone_type TEXT)',
            'CREATE TABLE otto_day_strip (day TEXT PRIMARY KEY, payload_json TEXT, computed_at TEXT)',
        ] as $ddl) $db->exec($ddl);
        $db->exec("INSERT INTO users VALUES (7, 'Nigel Brown')");
        $db->exec("INSERT INTO properties VALUES (22, '2505 W 8th Ave, Vancouver', NULL, NULL)");
        $db->exec("INSERT INTO properties VALUES (23, '1900 Oak St, Vancouver', 49.265, -123.144)");
        $db->exec("INSERT INTO properties VALUES (24, '2448 Larch St, Vancouver', 49.2637, -123.162)");
        $db->exec("INSERT INTO properties VALUES (25, '77 Elm St, Vancouver', 49.25, -123.10)");
        $db->exec("INSERT INTO job_geofences VALUES (1, 24, 'arrival_border')");
        $db->exec("INSERT INTO job_geofences VALUES (2, 23, 'work_zone')");
        foreach ([[1, 22, 240], [2, 23, 180], [3, 24, 180], [4, 25, 60]] as [$id, $pid, $min]) {
            $db->exec("INSERT INTO job_plans VALUES ($id, $pid, $min, 'active')");
        }
        foreach ([[11, 22], [12, 23], [13, 24], [14, 25]] as [$sid, $pid]) {
            $db->exec("INSERT INTO calendar_stops VALUES ($sid, $pid, '" . self::DAY . "', 'scheduled', 7, '2026-10-01')");
        }
        $db->exec("INSERT INTO job_visits VALUES (101, 1, '" . self::DAY . "', 'scheduled', 11, 7)");
        $db->exec("INSERT INTO job_visits VALUES (102, 2, '" . self::DAY . "', 'completed', 12, 7)");
        $db->exec("INSERT INTO job_visits VALUES (103, 3, '" . self::DAY . "', 'scheduled', 13, 7)");
        $db->exec("INSERT INTO job_visits VALUES (104, 4, '" . self::DAY . "', 'cancelled', 14, 7)");   // stop 14 is now empty
        return $db;
    }

    private function desk(): array
    {
        $it = fn($sid, $kind, $id, $date, $p = 3, $propose = []) => ['sid' => $sid, 'key' => "otto:$kind:$id", 'kind' => $kind, 'subject_type' => 'x',
            'subject_id' => $id, 'for_date' => $date, 'priority' => $p, 'text' => "$kind $id", 'detail' => '', 'url' => '/x', 'propose' => $propose];
        return [
            $it(1, 'unscheduled', 441, self::DAY, 2),
            $it(2, 'unscheduled', 441, '2026-10-05', 2),                          // another day
            $it(3, 'visit_date', 2160, '2026-10-05', 3, ['from' => self::DAY]),   // booked this day, done the day before
            $it(4, 'no_time', 102, self::DAY, 3),
            $it(5, 'duration', 3, '2026-09-29', 3),                               // plan 3 has a visit today
            $it(6, 'duration', 99, self::DAY, 3),                                 // not on today's schedule
            $it(7, 'no_pin', 22, '2026-10-08', 2, ['property_id' => 22]),
            $it(8, 'no_pin', 500, '2026-10-08', 2, ['property_id' => 500]),       // not on today's schedule
            $it(9, 'weather', 101, self::DAY, 1),
            $it(10, 'silent', 7, self::DAY, 1),                                   // never on the strip
        ];
    }

    private function svc(PDO $db): OttoScheduleService
    {
        $desk = $this->desk();
        return new class($db, $desk) extends OttoScheduleService {
            public int $deskCalls = 0;
            private array $d;
            public function __construct(PDO $db, array $d) { parent::__construct($db, '2026-10-08'); $this->d = $d; }
            protected function deskItems(): array { $this->deskCalls++; return $this->d; }
        };
    }

    public function test_the_day_gets_its_own_items_most_urgent_first(): void
    {
        $r = $this->svc($this->db())->day(self::DAY);
        $keys = array_column($r['items'], 'key');
        $this->assertSame('otto:weather:101', $keys[0]);
        foreach (['otto:unscheduled:441', 'otto:visit_date:2160', 'otto:no_time:102', 'otto:duration:3', 'otto:no_pin:22'] as $k) $this->assertContains($k, $keys);
        foreach (['otto:duration:99', 'otto:no_pin:500', 'otto:silent:7'] as $k) $this->assertNotContains($k, $keys);
        $this->assertSame(1, count(array_filter($r['items'], fn($i) => $i['key'] === 'otto:unscheduled:441')));
        $this->assertSame(1, $r['items'][array_search('otto:unscheduled:441', $keys, true)]['id']);   // the suggestion id the buttons act on
        $this->assertSame($r['count'], count($r['items']));
        $this->assertFalse($r['cached']);
    }

    public function test_overbooked_empty_stop_and_no_border_are_worked_out_for_the_day(): void
    {
        $r = $this->svc($this->db())->day(self::DAY);
        $by = [];
        foreach ($r['items'] as $i) $by[$i['kind']][] = $i;
        $this->assertSame("Nigel's day is 3 stops and 10 h of plan lengths — 9 h fits.", $by['overbooked'][0]['text']);
        $this->assertNull($by['overbooked'][0]['id']);
        $this->assertSame('/crm/jobs/schedule.php?view=day&date=' . self::DAY, $by['overbooked'][0]['url']);
        $this->assertSame('Empty stop at 77 Elm St — nothing is on it any more.', $by['empty_stop'][0]['text']);
        // 23: pinned, only a work zone → no border. 24 has one; 22 has no pin (its no_pin item covers it); 25 no border either.
        $nb = array_map(fn($i) => $i['propose']['property_id'], $by['no_border']);
        sort($nb);
        $this->assertSame([23, 25], $nb);
        $this->assertSame([['crew_id' => 7, 'name' => 'Nigel', 'minutes' => 600, 'stops' => 3, 'capacity' => 540]], $r['crews']);
    }

    public function test_the_day_is_cached_and_forget_clears_it(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $svc->day(self::DAY);
        $again = $svc->day(self::DAY);
        $this->assertTrue($again['cached']);
        $this->assertSame(1, $svc->deskCalls);
        $svc->forget(self::DAY);
        $this->assertFalse($this->svc($db)->day(self::DAY)['cached']);
        $this->assertFalse($svc->day(self::DAY, true)['cached']);   // ?fresh=1
    }

    public function test_range_counts_each_day_and_reads_the_desk_once(): void
    {
        $svc = $this->svc($this->db());
        $r = $svc->range('2026-10-05', '2026-10-07', self::DAY);
        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-07'], array_column($r['days'], 'date'));
        $this->assertSame(self::DAY, $r['focus']['date']);
        $this->assertSame($r['focus']['count'], $r['days'][1]['count']);
        $this->assertSame(['otto:unscheduled:441', 'otto:visit_date:2160'], array_column($this->svc($this->db())->day('2026-10-05')['items'], 'key'));
        $this->assertSame(1, $svc->deskCalls);
    }

    public function test_overbooked_is_per_lead_crew_and_skips_unassigned(): void
    {
        [$over, $all] = OttoScheduleService::overbooked([
            ['crew_id' => 7, 'crew_name' => 'Nigel Brown', 'stop_id' => 1, 'estimated_duration_minutes' => 300],
            ['crew_id' => 7, 'crew_name' => 'Nigel Brown', 'stop_id' => 1, 'estimated_duration_minutes' => 100],
            ['crew_id' => 8, 'crew_name' => 'Tim S', 'stop_id' => 2, 'estimated_duration_minutes' => 560],
            ['crew_id' => null, 'crew_name' => null, 'stop_id' => 3, 'estimated_duration_minutes' => 999],
        ], 540);
        $this->assertSame([8], array_column($over, 'crew_id'));
        $this->assertSame([['crew_id' => 7, 'name' => 'Nigel', 'minutes' => 400, 'stops' => 1, 'capacity' => 540],
                           ['crew_id' => 8, 'name' => 'Tim', 'minutes' => 560, 'stops' => 1, 'capacity' => 540]], $all);
    }

    public function test_capacity_is_the_drag_and_drop_warning_limit(): void
    {
        $this->assertSame(StopRescheduleService::DAY_CAPACITY_MINUTES, OttoScheduleService::capacity());
    }
}
