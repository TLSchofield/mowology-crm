<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Otto's "a border for every client property": geometry (hull, buffer, cap, square, overlap), the
 * read-only audit, default borders that never overwrite a drawn one, his items and his one-click answers.
 *
 * Fixture (approximate coordinates, Kitsilano):
 *   843  2505 W 8th Ave   active plan, NO pin  — the property that hid a whole job from Otto
 *   10   1900 Oak St      pinned, DRAWN border
 *   11   1910 Oak St      pinned, Otto's default square (overlaps 10's border)
 *   12   2448 Larch St    pinned, no border; crews worked ~80 m north of the pin on 3 timed visits
 *   13   77 Elm St        pinned, no border, no GPS → a square
 *   14   5 Gone Rd        no active plan, last visit 2 years ago → not active
 */
class PropertyBorderTest extends TestCase
{
    private const TODAY = '2026-10-08';
    private const OAK = [49.2650, -123.1440];
    private const LARCH = [49.2637, -123.1620];
    private const ELM = [49.2500, -123.1000];

    private function db(): PDO
    {
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        $now = fn() => self::TODAY . ' 12:00:00';   // MySQL's NOW() for PropertyReadinessService::savePin
        if (method_exists(PDO::class, 'connect')) {
            $db = PDO::connect('sqlite::memory:', null, null, $opts);
            $db->createFunction('NOW', $now, 0);
        } else {
            $db = new PDO('sqlite::memory:', null, null, $opts);
            $db->sqliteCreateFunction('NOW', $now, 0);
        }
        foreach ([
            'CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT, province TEXT, postal_code TEXT, latitude REAL, longitude REAL, geocoded_at TEXT)',
            'CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INT, status TEXT, estimated_duration_minutes INT)',
            'CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, scheduled_date TEXT, status TEXT, stop_id INT, assigned_crew_id INT)',
            'CREATE TABLE job_time_entries (id INTEGER PRIMARY KEY, visit_id INT, user_id INT, start_time TEXT, end_time TEXT, start_lat REAL, start_lng REAL, end_lat REAL, end_lng REAL, status TEXT)',
            'CREATE TABLE crew_location_history (crew_id INT, latitude REAL, longitude REAL, accuracy_meters REAL, `timestamp` TEXT)',
            'CREATE TABLE vehicle_location_pings (lat REAL, lng REAL, speed_kph REAL, recorded_at TEXT)',
            'CREATE TABLE job_geofences (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INT, property_id INT, zone_type TEXT, border_source TEXT, polygon_json TEXT, vertex_count INT,
                bbox_lat_min REAL, bbox_lat_max REAL, bbox_lng_min REAL, bbox_lng_max REAL, area_sqm REAL, label TEXT, notes TEXT, drawn_by INT, drawn_at TEXT)',
            'CREATE TABLE otto_property_sites (property_id INTEGER PRIMARY KEY, pin_lat REAL, pin_lng REAL, work_lat REAL, work_lng REAL, fixes INT, visits INT, offset_m INT, overlaps_json TEXT, computed_at TEXT)',
            'CREATE TABLE otto_suggestions (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, subject_type TEXT, subject_id INT, for_date TEXT, status TEXT,
                suggestion_json TEXT, outcome_json TEXT, decided_by INT, decided_at TEXT)',
        ] as $ddl) $db->exec($ddl);

        $db->exec("INSERT INTO properties (id, address, latitude, longitude) VALUES (843, '2505 W 8th Ave, Vancouver BC', NULL, NULL)");
        $db->exec("INSERT INTO properties (id, address, latitude, longitude) VALUES (10, '1900 Oak St, Vancouver BC', " . self::OAK[0] . ', ' . self::OAK[1] . ')');
        $db->exec("INSERT INTO properties (id, address, latitude, longitude) VALUES (11, '1910 Oak St, Vancouver BC', " . (self::OAK[0] + 0.0003) . ', ' . self::OAK[1] . ')');
        $db->exec("INSERT INTO properties (id, address, latitude, longitude) VALUES (12, '2448 Larch St, Vancouver BC', " . self::LARCH[0] . ', ' . self::LARCH[1] . ')');
        $db->exec("INSERT INTO properties (id, address, latitude, longitude) VALUES (13, '77 Elm St, Vancouver BC', " . self::ELM[0] . ', ' . self::ELM[1] . ')');
        $db->exec("INSERT INTO properties (id, address, latitude, longitude) VALUES (14, '5 Gone Rd, Vancouver BC', 49.2, -123.0)");
        foreach ([[1, 843, 'active'], [2, 10, 'active'], [3, 11, 'active'], [4, 12, 'active'], [5, 13, 'active'], [6, 14, 'completed']] as [$id, $pid, $st]) {
            $db->exec("INSERT INTO job_plans (id, property_id, status, estimated_duration_minutes) VALUES ($id, $pid, '$st', 60)");
        }
        $db->exec("INSERT INTO job_visits (id, plan_id, scheduled_date, status) VALUES (90, 6, '2024-09-01', 'completed')");
        $db->exec("INSERT INTO job_visits (id, plan_id, scheduled_date, status) VALUES (91, 1, '2026-10-14', 'scheduled')");

        // 10: a drawn border round Oak St; 11: Otto's default square, overlapping it.
        $this->border($db, 10, PropertyBorderRules::square(self::OAK[0], self::OAK[1], 20), null);
        $this->border($db, 11, PropertyBorderRules::square(self::OAK[0] + 0.0003, self::OAK[1], 25), 'default_square');

        // 12: three timed visits; the crew's phone ~80 m north of the pin, wandering a 20 m patch.
        $work = [self::LARCH[0] + 80 / 111320, self::LARCH[1]];
        for ($v = 0; $v < 3; $v++) {
            $vid = 100 + $v;
            $day = date('Y-m-d', strtotime(self::TODAY . ' -' . (7 * ($v + 1)) . ' days'));
            $db->exec("INSERT INTO job_visits (id, plan_id, scheduled_date, status) VALUES ($vid, 4, '$day', 'completed')");
            $db->exec("INSERT INTO job_time_entries (visit_id, user_id, start_time, end_time, start_lat, start_lng, end_lat, end_lng, status)
                       VALUES ($vid, 7, '$day 09:00:00', '$day 10:00:00', {$work[0]}, {$work[1]}, {$work[0]}, {$work[1]}, 'completed')");
            for ($i = 0; $i < 10; $i++) {
                $lat = $work[0] + (($i % 5) - 2) * 4 / 111320;
                $lng = $work[1] + (intdiv($i, 5) * 2 - 1) * 6 / (111320 * cos(deg2rad($work[0])));
                $t = sprintf('%s 09:%02d:00', $day, 5 + $i * 5);
                $db->exec("INSERT INTO crew_location_history VALUES (7, $lat, $lng, 8, '$t')");
            }
            // A fix from the drive there (3 km away) — never part of the site.
            $db->exec("INSERT INTO crew_location_history VALUES (7, 49.29, -123.19, 8, '$day 09:01:00')");
        }
        return $db;
    }

    private function border(PDO $db, int $pid, array $ring, ?string $source): void
    {
        $closed = array_merge($ring, [$ring[0]]);
        [$a, $b, $c, $d] = PropertyBorderRules::bbox($ring);
        $s = $db->prepare("INSERT INTO job_geofences (plan_id, property_id, zone_type, border_source, polygon_json, vertex_count, bbox_lat_min, bbox_lat_max, bbox_lng_min, bbox_lng_max)
                           VALUES (NULL, ?, 'arrival_border', ?, ?, ?, ?, ?, ?, ?)");
        $s->execute([$pid, $source, json_encode($closed), count($ring), $a, $b, $c, $d]);
    }

    private function svc(PDO $db): PropertyBorderService
    {
        return new PropertyBorderService($db, self::TODAY);
    }

    // ── Geometry ────────────────────────────────────────────────────────────

    public function test_hull_drops_interior_points(): void
    {
        $sq = PropertyBorderRules::square(49.26, -123.16, 20);
        $hull = PropertyBorderRules::convexHull(array_merge($sq, [[49.26, -123.16], [49.26001, -123.16001]]));
        $this->assertCount(4, $hull);
        $this->assertEqualsWithDelta(1600, PropertyBorderRules::area($hull), 5);
    }

    public function test_hull_from_fixes_is_buffered_capped_and_centred_on_the_work(): void
    {
        $c = [49.2637, -123.1620];
        $fixes = [];
        for ($v = 1; $v <= 3; $v++) {
            for ($i = 0; $i < 6; $i++) {
                $fixes[] = ['lat' => $c[0] + ($i - 2.5) * 5 / 111320, 'lng' => $c[1] + ($v - 2) * 8 / (111320 * cos(deg2rad($c[0]))), 'visit_id' => $v];
            }
        }
        $site = PropertyBorderRules::siteFixes($fixes, $c);
        $this->assertTrue(PropertyBorderRules::enoughFixes($site));
        $plan = PropertyBorderRules::planBorder($c, $site, false);
        $this->assertSame('default_hull', $plan['source']);
        $this->assertSame(18, $plan['fixes']);
        $this->assertSame(3, $plan['visits']);
        // 25 m × 16 m of fixes + 10 m all round ≈ 45 × 36 m, a little less at the rounded corners.
        $this->assertGreaterThan(1300, $plan['area_sqm']);
        $this->assertLessThan(1700, $plan['area_sqm']);
        $this->assertTrue(PropertyBorderRules::inside($c, $plan['ring']));
        foreach ($plan['ring'] as $p) {
            $this->assertLessThanOrEqual(PropertyBorderRules::CAP_M + 0.5, PropertyBorderRules::meters($p[0], $p[1], $plan['center'][0], $plan['center'][1]));
        }
    }

    public function test_a_hull_is_capped_at_80_m(): void
    {
        $c = [49.2637, -123.1620];
        $ring = PropertyBorderRules::cap(PropertyBorderRules::square($c[0], $c[1], 200), $c, 80);
        foreach ($ring as $p) $this->assertEqualsWithDelta(80, PropertyBorderRules::meters($p[0], $p[1], $c[0], $c[1]), 0.5);
    }

    public function test_too_few_fixes_get_a_25_m_square_round_the_pin(): void
    {
        $pin = [49.25, -123.10];
        $site = PropertyBorderRules::siteFixes([['lat' => 49.25, 'lng' => -123.10, 'visit_id' => 1]], $pin);
        $plan = PropertyBorderRules::planBorder($pin, $site, false);
        $this->assertSame('default_square', $plan['source']);
        $this->assertCount(4, $plan['ring']);
        $this->assertEqualsWithDelta(2500, $plan['area_sqm'], 10);
        foreach ($plan['ring'] as $p) $this->assertEqualsWithDelta(25 * M_SQRT2, PropertyBorderRules::meters($p[0], $p[1], $pin[0], $pin[1]), 0.3);
    }

    public function test_no_border_is_planned_over_an_existing_one_or_without_a_pin(): void
    {
        $site = ['points' => [], 'visits' => 0, 'center' => null];
        $this->assertNull(PropertyBorderRules::planBorder([49.25, -123.1], $site, true));
        $this->assertNull(PropertyBorderRules::planBorder(null, $site, false));
    }

    public function test_overlap_disjoint_crossing_and_contained(): void
    {
        $a = PropertyBorderRules::square(49.25, -123.10, 20);
        $this->assertTrue(PropertyBorderRules::overlap($a, PropertyBorderRules::square(49.25 + 30 / 111320, -123.10, 20)));
        $this->assertFalse(PropertyBorderRules::overlap($a, PropertyBorderRules::square(49.25 + 60 / 111320, -123.10, 20)));
        $this->assertTrue(PropertyBorderRules::overlap($a, PropertyBorderRules::square(49.25, -123.10, 5)));
    }

    public function test_drive_fixes_and_stragglers_are_not_the_site(): void
    {
        $pin = [49.2637, -123.1620];
        $fixes = [['lat' => 49.29, 'lng' => -123.19, 'visit_id' => 1]];   // 3 km away
        for ($i = 0; $i < 12; $i++) $fixes[] = ['lat' => $pin[0] + $i / 1e6, 'lng' => $pin[1], 'visit_id' => 1 + $i % 2];
        $fixes[] = ['lat' => $pin[0] + 150 / 111320, 'lng' => $pin[1], 'visit_id' => 2];   // within 200 m of the pin, far from the work
        $site = PropertyBorderRules::siteFixes($fixes, $pin);
        $this->assertCount(12, $site['points']);
        $this->assertLessThan(2, PropertyBorderRules::pinOffset($pin, $site['center']));
    }

    // ── Audit (read-only) ───────────────────────────────────────────────────

    public function test_audit_counts_every_active_property(): void
    {
        $db = $this->db();
        $before = (int)$db->query('SELECT COUNT(*) FROM job_geofences')->fetchColumn();
        $a = $this->svc($db)->audit();
        $this->assertSame(5, $a['active']);   // 14 has no active plan and no visit this year
        $this->assertSame(4, $a['with_pin']);
        $this->assertSame(1, $a['without_pin']);
        $this->assertSame([['id' => 843, 'address' => '2505 W 8th Ave, Vancouver BC']], $a['no_pin']);
        $this->assertSame(1, $a['with_drawn_border']);
        $this->assertSame(1, $a['with_default_border']);
        $this->assertSame(3, $a['without_border']);   // 843 (no pin), 12, 13
        $this->assertSame(1, $a['pin_off']);
        $this->assertSame(12, $a['pin_off_list'][0]['id']);
        $this->assertEqualsWithDelta(80, $a['pin_off_list'][0]['distance_m'], 3);
        $this->assertSame(3, $a['pin_off_list'][0]['visits']);
        $this->assertSame(1, $a['overlaps']);
        $this->assertSame(10, $a['overlap_list'][0]['a']);
        $this->assertSame(11, $a['overlap_list'][0]['b']);
        $this->assertSame(['hull' => 1, 'square' => 1], $a['would_create']);
        $this->assertSame([12, 13], array_column($a['proposed'], 'property_id'));
        $this->assertNull($a['next_offset']);
        // Read-only: nothing written.
        $this->assertSame($before, (int)$db->query('SELECT COUNT(*) FROM job_geofences')->fetchColumn());
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM otto_property_sites')->fetchColumn());
    }

    public function test_a_spent_budget_hands_back_the_next_offset(): void
    {
        $a = $this->svc($this->db())->audit(0, -1.0);
        $this->assertSame(1, $a['walked']);
        $this->assertSame(1, $a['next_offset']);
        $this->assertSame(5, $a['active']);   // the cheap counts are always whole
    }

    // ── Apply ───────────────────────────────────────────────────────────────

    public function test_apply_creates_defaults_and_never_overwrites_a_drawn_border(): void
    {
        $db = $this->db();
        $drawn = $db->query("SELECT * FROM job_geofences WHERE property_id = 10")->fetch();
        $r = $this->svc($db)->apply(1);
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['created']);
        $rows = $db->query("SELECT property_id, border_source, zone_type, plan_id, vertex_count, label FROM job_geofences WHERE property_id IN (12, 13) ORDER BY property_id")->fetchAll();
        $this->assertSame('default_hull', $rows[0]['border_source']);
        $this->assertSame('arrival_border', $rows[0]['zone_type']);
        $this->assertNull($rows[0]['plan_id']);
        $this->assertSame('default_square', $rows[1]['border_source']);
        $this->assertSame('Default border — draw me', $rows[1]['label']);
        $this->assertSame($drawn, $db->query("SELECT * FROM job_geofences WHERE property_id = 10")->fetch());
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM job_geofences WHERE property_id = 11")->fetchColumn());
        // The hull is where the crews worked, not round the pin.
        $hull = PropertyBorderService::ring((string)$db->query("SELECT polygon_json FROM job_geofences WHERE property_id = 12")->fetchColumn());
        $this->assertTrue(PropertyBorderRules::inside([self::LARCH[0] + 80 / 111320, self::LARCH[1]], $hull));
        // Again: nothing new.
        $this->assertSame(0, $this->svc($db)->apply(1)['created']);
    }

    public function test_a_border_drawn_after_the_dry_run_is_not_doubled(): void
    {
        $db = $this->db();
        $plan = $this->svc($db)->plan();
        $this->assertContains(13, array_column($plan['proposed'], 'property_id'));
        $this->border($db, 13, PropertyBorderRules::square(self::ELM[0], self::ELM[1], 15), null);   // drawn meanwhile
        $r = $this->svc($db)->apply(1, [13]);
        $this->assertSame(0, $r['created']);
        $this->assertSame([null], array_column($db->query("SELECT border_source FROM job_geofences WHERE property_id = 13")->fetchAll(), 'border_source'));
    }

    // ── Otto's items and answers ────────────────────────────────────────────

    public function test_items_name_each_problem(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $svc->apply(1);
        $by = [];
        foreach ($svc->items() as $it) $by[$it['kind'] . ':' . $it['subject_id']] = $it;
        $this->assertSame("2505 W 8th Ave has no pin — crews can't be seen there (next visit Wed Oct 14).", $by['no_pin:843']['text']);
        $this->assertSame('/crm/map_appstack.php?geocode=843', $by['no_pin:843']['url']);
        $this->assertMatchesRegularExpression('/^Pin for 2448 Larch St is (7[89]|8[0-2]) m from where crews work → move it\.$/', $by['pin_off:12']['text']);
        $this->assertArrayHasKey('lat', $by['pin_off:12']['propose']);
        $this->assertSame('1910 Oak St has a default border (a square round the pin) — draw it.', $by['default_border:11']['text']);
        $this->assertSame('2448 Larch St has a default border (from where crews worked) — draw it.', $by['default_border:12']['text']);
        $this->assertArrayHasKey('default_border:13', $by);
        $this->assertSame('The borders of 1900 Oak St and 1910 Oak St overlap — a crew there counts at both.', $by['border_overlap:10']['text']);
        $this->assertArrayNotHasKey('default_border:10', $by);   // drawn
        foreach ($by as $it) {
            $this->assertSame('property', $it['subject_type']);
            $this->assertSame(self::TODAY, $it['for_date']);
            $this->assertContains($it['kind'], OpsDeskService::KINDS);
        }
    }

    public function test_an_answered_item_stays_quiet(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO otto_suggestions (kind, subject_type, subject_id, for_date, status) VALUES ('no_pin', 'property', 843, '2026-10-01', 'dismissed')");
        $kinds = array_map(fn($i) => $i['kind'] . ':' . $i['subject_id'], $this->svc($db)->items());
        $this->assertNotContains('no_pin:843', $kinds);
    }

    public function test_move_pin_goes_to_the_work_and_takes_a_default_square_with_it(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $svc->apply(1);
        $r = $svc->movePin(13, self::ELM[0] + 0.001, self::ELM[1]);
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['square_moved']);
        $ring = PropertyBorderService::ring((string)$db->query("SELECT polygon_json FROM job_geofences WHERE property_id = 13")->fetchColumn());
        $this->assertTrue(PropertyBorderRules::inside([self::ELM[0] + 0.001, self::ELM[1]], $ring));
        $this->assertFalse(PropertyBorderRules::inside(self::ELM, $ring));
        // 12's hull stays where it is; its pin item goes once the pin moves.
        $site = $db->query("SELECT work_lat, work_lng FROM otto_property_sites WHERE property_id = 12")->fetch();
        $before = $db->query("SELECT polygon_json FROM job_geofences WHERE property_id = 12")->fetchColumn();
        $svc->movePin(12, (float)$site['work_lat'], (float)$site['work_lng']);
        $this->assertSame($before, $db->query("SELECT polygon_json FROM job_geofences WHERE property_id = 12")->fetchColumn());
        $this->assertSame([(float)$site['work_lat'], (float)$site['work_lng']], array_map('floatval', array_values($db->query("SELECT latitude, longitude FROM properties WHERE id = 12")->fetch())));
        $kinds = array_map(fn($i) => $i['kind'] . ':' . $i['subject_id'], $svc->items());
        $this->assertNotContains('pin_off:12', $kinds);
    }

    public function test_keep_default_stops_asking_and_counts_as_kept(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $this->assertTrue($svc->keepDefault(11));
        $kinds = array_map(fn($i) => $i['kind'] . ':' . $i['subject_id'], $svc->items());
        $this->assertNotContains('default_border:11', $kinds);
        $a = $svc->summary();
        $this->assertSame(0, $a['with_default_border']);
        $this->assertSame(1, $a['with_kept_border']);
        // A kept default is still a border: nothing is planned over it.
        $this->assertNotContains(11, array_column($svc->plan()['proposed'], 'property_id'));
    }

    public function test_the_owners_click_moves_the_pin_or_keeps_the_default(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $svc->apply(1);
        $items = [];
        foreach ($svc->items() as $it) $items[$it['kind'] . ':' . $it['subject_id']] = $it;
        $ins = $db->prepare("INSERT INTO otto_suggestions (kind, subject_type, subject_id, for_date, status, suggestion_json) VALUES (?, 'property', ?, ?, 'open', ?)");
        foreach (['pin_off:12', 'default_border:11', 'no_pin:843'] as $k) {
            $ins->execute([$items[$k]['kind'], $items[$k]['subject_id'], self::TODAY, json_encode($items[$k]['propose'])]);
        }
        $act = new OttoActionService($db);
        $r = $act->decide(1, ['choice' => 'move_pin'], 5);
        $this->assertTrue($r['ok']);
        $this->assertEqualsWithDelta(self::LARCH[0] + 80 / 111320, (float)$db->query('SELECT latitude FROM properties WHERE id = 12')->fetchColumn(), 0.00003);
        $this->assertSame('accepted', $db->query('SELECT status FROM otto_suggestions WHERE id = 1')->fetchColumn());
        $this->assertTrue($act->decide(2, ['choice' => 'keep'], 5)['ok']);
        $this->assertSame('kept', $db->query("SELECT border_source FROM job_geofences WHERE property_id = 11")->fetchColumn());
        $this->assertFalse($act->decide(3, ['choice' => 'move_pin'], 5)['ok']);   // no pin to move — fixed on the map
        $this->assertTrue($act->decide(3, ['choice' => 'dismiss'], 5)['ok']);
    }

    public function test_without_migration_1285_there_are_no_items_and_apply_refuses(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE job_geofences (id INTEGER PRIMARY KEY, property_id INT, zone_type TEXT, polygon_json TEXT)');
        $svc = new PropertyBorderService($db, self::TODAY);
        $this->assertFalse($svc->ready());
        $this->assertSame([], $svc->items());
        $this->assertFalse($svc->apply(1)['ok']);
    }
}
