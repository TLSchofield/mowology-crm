<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Otto's property gaps: pin, arrival border, measurement — and his wording for them.
 */
class PropertyReadinessServiceTest extends TestCase
{
    private function sqlite(bool $withGeofences = true): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT, province TEXT, postal_code TEXT,
                   latitude REAL, longitude REAL, total_lawn_sqft REAL, lawn_size_sqft REAL)');
        if ($withGeofences) {
            $db->exec('CREATE TABLE job_geofences (id INTEGER PRIMARY KEY, property_id INTEGER, zone_type TEXT)');
        }
        return $db;
    }

    public function test_a_bare_property_misses_everything(): void
    {
        $db = $this->sqlite();
        $db->exec("INSERT INTO properties (id, address, city, province, postal_code) VALUES (7, '12 Oak St', 'Squamish', 'BC', 'V8B 0A1')");
        $g = (new PropertyReadinessService($db))->gaps(7);
        $this->assertSame(['id' => 7, 'address' => '12 Oak St', 'city' => 'Squamish', 'province' => 'BC', 'postal_code' => 'V8B 0A1'], $g['property']);
        $this->assertFalse($g['pin']);
        $this->assertFalse($g['border']);
        $this->assertFalse($g['measured']);
        $this->assertSame(['pin', 'border', 'measured'], $g['missing']);
    }

    public function test_a_ready_property_misses_nothing(): void
    {
        $db = $this->sqlite();
        $db->exec("INSERT INTO properties (id, address, latitude, longitude, total_lawn_sqft, lawn_size_sqft) VALUES (3, '1 Elm', 49.7, -123.1, 0, 4200)");
        $db->exec("INSERT INTO job_geofences (property_id, zone_type) VALUES (3, 'arrival_border')");
        $g = (new PropertyReadinessService($db))->gaps(3);
        $this->assertTrue($g['pin']);
        $this->assertTrue($g['border']);
        $this->assertTrue($g['measured']);
        $this->assertSame([], $g['missing']);
    }

    public function test_other_zone_types_are_not_an_arrival_border(): void
    {
        $db = $this->sqlite();
        $db->exec("INSERT INTO properties (id, address, latitude, longitude, total_lawn_sqft) VALUES (4, '2 Elm', 49.7, -123.1, 900)");
        $db->exec("INSERT INTO job_geofences (property_id, zone_type) VALUES (4, 'work_area')");
        $this->assertSame(['border'], (new PropertyReadinessService($db))->gaps(4)['missing']);
    }

    public function test_a_missing_geofence_table_is_unknown_not_a_gap(): void
    {
        $db = $this->sqlite(false);
        $db->exec("INSERT INTO properties (id, address) VALUES (5, '3 Elm')");
        $g = (new PropertyReadinessService($db))->gaps(5);
        $this->assertNull($g['border']);
        $this->assertSame(['pin', 'measured'], $g['missing']);
    }

    public function test_an_unknown_property_has_no_gaps(): void
    {
        $svc = new PropertyReadinessService($this->sqlite());
        $this->assertNull($svc->gaps(99)['property']);
        $this->assertSame([], $svc->gaps(99)['missing']);
        $this->assertSame([], $svc->gaps(0)['missing']);
    }

    public function test_pure_checks(): void
    {
        $this->assertFalse(PropertyReadinessService::hasPin(['latitude' => 49.7, 'longitude' => 0]));
        $this->assertFalse(PropertyReadinessService::hasPin(['latitude' => null, 'longitude' => null]));
        $this->assertTrue(PropertyReadinessService::hasPin(['latitude' => '49.7', 'longitude' => '-123.1']));
        $this->assertTrue(PropertyReadinessService::isMeasured(['total_lawn_sqft' => 1200, 'lawn_size_sqft' => 0]));
        $this->assertTrue(PropertyReadinessService::isMeasured(['total_lawn_sqft' => null, 'lawn_size_sqft' => 800]));
        $this->assertFalse(PropertyReadinessService::isMeasured([]));
        $this->assertSame(['pin', 'measured'], PropertyReadinessService::missing(false, null, false));
    }

    public function test_messages_are_plain_and_never_shout(): void
    {
        $m = [
            [[], 'All set — I can route here now.'],
            [['measured'], "One thing before I can plan this: it hasn't been measured."],
            [['border'], "I can't route here yet: no arrival border."],
            [['pin'], "I can't route here yet: no map pin."],
            [['border', 'pin'], "I can't route here yet: no map pin, no arrival border. Pin it first, then draw the border."],
            [['pin', 'border', 'measured'], "I can't route here yet: no map pin, no arrival border. Pin it first, then draw the border. It hasn't been measured either."],
            [['pin', 'measured'], "I can't route here yet: no map pin. It hasn't been measured either."],
        ];
        foreach ($m as [$missing, $want]) {
            $got = PropertyReadinessService::message($missing);
            $this->assertSame($want, $got);
            $this->assertStringNotContainsString('!', $got);
        }
    }

    public function test_geocode_address_matches_the_quote_form(): void
    {
        $this->assertSame('12 Oak St, Squamish, BC, V8B 0A1, Canada',
            PropertyReadinessService::geocodeAddress(['address' => '12 Oak St', 'city' => 'Squamish', 'province' => 'BC', 'postal_code' => 'V8B 0A1']));
        $this->assertSame('12 Oak St, Canada', PropertyReadinessService::geocodeAddress(['address' => '12 Oak St', 'city' => ' ']));
        $this->assertSame('', PropertyReadinessService::geocodeAddress([]));
    }

    // ── Otto's phone card: unpinned properties with a visit coming, and saving a pin ──

    private function withVisits(): PDO
    {
        $db = $this->sqlite();
        $db->sqliteCreateFunction('NOW', static fn() => '2026-10-07 09:00:00', 0);
        $db->exec('ALTER TABLE properties ADD COLUMN geocoded_at TEXT');
        $db->exec('CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INTEGER)');
        $db->exec('CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INTEGER, scheduled_date TEXT, status TEXT)');
        $db->exec("INSERT INTO properties (id, address, city, province, postal_code, latitude, longitude) VALUES
                   (1, '12 Oak St', 'Squamish', 'BC', 'V8B 0A1', NULL, NULL),
                   (2, '3 Elm', 'Burnaby', 'BC', '', 0, 0),
                   (3, '5 Ash', 'Vancouver', 'BC', '', 49.2, -123.1),
                   (4, '7 Fir', 'Vancouver', 'BC', '', NULL, NULL)");
        $db->exec('INSERT INTO job_plans VALUES (10, 1), (20, 2), (30, 3), (40, 4)');
        $db->exec("INSERT INTO job_visits VALUES
                   (1, 10, '2026-10-12', 'scheduled'), (2, 10, '2026-10-09', 'scheduled'),
                   (3, 20, '2026-10-08', 'scheduled'),
                   (4, 30, '2026-10-08', 'scheduled'),
                   (5, 40, '2026-10-08', 'completed'), (6, 40, '2026-11-30', 'scheduled')");
        return $db;
    }

    public function test_unpinned_upcoming_lists_soonest_first_and_skips_pinned_or_far_off(): void
    {
        $rows = (new PropertyReadinessService($this->withVisits()))->unpinnedUpcoming('2026-10-07', '2026-10-21', 3);
        $this->assertSame([2, 1], array_column($rows, 'id'));
        $this->assertSame('2026-10-09', $rows[1]['next_visit']);
        $this->assertSame('12 Oak St, Squamish, BC, V8B 0A1, Canada', $rows[1]['geocode_address']);
    }

    public function test_save_pin_writes_coordinates_like_the_web_geocode_save(): void
    {
        $db = $this->withVisits();
        $svc = new PropertyReadinessService($db);
        $r = $svc->savePin(1, 49.7016, -123.1558);
        $this->assertTrue($r['ok']);
        $row = $db->query('SELECT latitude, longitude, geocoded_at FROM properties WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertEqualsWithDelta(49.7016, (float)$row['latitude'], 1e-9);
        $this->assertEqualsWithDelta(-123.1558, (float)$row['longitude'], 1e-9);
        $this->assertSame('2026-10-07 09:00:00', $row['geocoded_at']);
        $this->assertSame([2], array_column($svc->unpinnedUpcoming('2026-10-07', '2026-10-21'), 'id'));

        $this->assertFalse($svc->savePin(1, 0, -123.1)['ok']);
        $this->assertFalse($svc->savePin(1, 95, -123.1)['ok']);
        $this->assertFalse($svc->savePin(0, 49.7, -123.1)['ok']);
        $this->assertFalse($svc->savePin(999, 49.7, -123.1)['ok']);
    }
}
