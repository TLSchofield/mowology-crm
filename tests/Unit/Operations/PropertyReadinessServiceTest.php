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
}
