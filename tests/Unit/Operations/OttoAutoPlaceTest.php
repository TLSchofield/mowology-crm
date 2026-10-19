<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Otto places pins (exact address matches only) and lot borders from ParcelMap BC by himself —
 * never moving a pin, never touching a drawn, crew-GPS or kept border. All synthetic.
 */
class OttoAutoPlaceTest extends TestCase
{
    /** A ~20 × 30 m lot round (49.2296, -123.1549), as WFS GeoJSON ([lng, lat]). */
    private static function lotFeature(string $class = 'Subdivision', string $plan = 'VAP1234', float $dLat = 0.0): array
    {
        $ring = [[-123.15505, 49.22950 + $dLat], [-123.15475, 49.22950 + $dLat], [-123.15475, 49.22970 + $dLat],
                 [-123.15505, 49.22970 + $dLat], [-123.15505, 49.22950 + $dLat]];
        return ['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]],
                'properties' => ['PARCEL_CLASS' => $class, 'PLAN_NUMBER' => $plan, 'PID_FORMATTED' => '001-002-003', 'MUNICIPALITY' => 'Vancouver, City of']];
    }

    private static function fetcher(array $features, array &$urls = []): callable
    {
        return function (string $url) use ($features, &$urls): ?string {
            $urls[] = $url;
            return json_encode(['type' => 'FeatureCollection', 'features' => $features]);
        };
    }

    public function test_strata_units_with_one_outline_become_one_lot(): void
    {
        $f = self::lotFeature('Building Strata', 'BCS914');
        $parsed = ParcelLotService::parseFeatures([$f, $f, $f]);
        $this->assertCount(1, $parsed);
        $this->assertSame([49.2295, -123.15505], $parsed[0]['ring'][0], 'GeoJSON [lng, lat] becomes [lat, lng]');
        $this->assertCount(4, $parsed[0]['ring'], 'stored open');
    }

    public function test_the_pin_must_be_inside_and_roads_are_never_a_lot(): void
    {
        $parsed = ParcelLotService::parseFeatures([self::lotFeature('Road')]);
        $this->assertSame('no_lot', ParcelLotService::chooseLot($parsed, 49.2296, -123.1549)['reason']);
        $parsed = ParcelLotService::parseFeatures([self::lotFeature()]);
        $this->assertSame('no_lot', ParcelLotService::chooseLot($parsed, 49.2310, -123.1549)['reason'], 'pin outside the lot');
        $lot = ParcelLotService::chooseLot($parsed, 49.2296, -123.1549);
        $this->assertTrue($lot['ok']);
        $this->assertSame('VAP1234', $lot['plan']);
        $this->assertGreaterThan(400, $lot['area_sqm']);
    }

    public function test_a_lot_too_big_or_too_small_is_left_for_a_person(): void
    {
        $this->assertSame('too_big', ParcelLotService::checkArea(['ok' => true, 'area_sqm' => 30000])['reason']);
        $this->assertSame('too_small', ParcelLotService::checkArea(['ok' => true, 'area_sqm' => 20])['reason']);
        $this->assertTrue(ParcelLotService::checkArea(['ok' => true, 'area_sqm' => 1211])['ok']);
    }

    public function test_a_strata_plan_is_fetched_and_merged(): void
    {
        $urls = [];
        $unitA = self::lotFeature('Bare Land Strata', 'EPS42');
        $unitB = self::lotFeature('Bare Land Strata', 'EPS42', 0.0003);
        $svc = new ParcelLotService(self::fetcher([$unitA, $unitB], $urls));
        $lot = $svc->lotAt(49.2296, -123.1549);
        $this->assertTrue($lot['ok']);
        $this->assertCount(2, $urls, 'pin box, then the whole plan');
        $this->assertStringContainsString(rawurlencode("PLAN_NUMBER='EPS42'"), $urls[1]);
        $this->assertGreaterThan(1000, $lot['area_sqm'], 'both units in one hull (one unit alone is ~485 m²)');
    }

    public function test_pin_and_border_decisions(): void
    {
        $this->assertSame('save', OttoAutoPlaceService::pinDecision(['lat' => 49.2, 'lng' => -123.1, 'exact' => true]));
        $this->assertSame('approximate', OttoAutoPlaceService::pinDecision(['lat' => 49.2, 'lng' => -123.1, 'exact' => false]));
        $this->assertSame('none', OttoAutoPlaceService::pinDecision(null));
        $this->assertSame('insert', OttoAutoPlaceService::borderMode(null));
        $this->assertSame('replace', OttoAutoPlaceService::borderMode(['default_square']));
        $this->assertNull(OttoAutoPlaceService::borderMode([null]), 'drawn');
        $this->assertNull(OttoAutoPlaceService::borderMode(['default_hull']), 'crew GPS');
        $this->assertNull(OttoAutoPlaceService::borderMode(['kept']));
        $this->assertNull(OttoAutoPlaceService::borderMode(['parcel']));
        $this->assertTrue(OttoAutoPlaceService::recentlyTried('2026-10-10', '2026-10-18'));
        $this->assertFalse(OttoAutoPlaceService::recentlyTried('2026-08-01', '2026-10-18'));
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT, province TEXT, postal_code TEXT,
                   latitude REAL, longitude REAL, geocoded_at TEXT)');
        $db->exec('CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INTEGER, status TEXT)');
        $db->exec('CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INTEGER, scheduled_date TEXT, status TEXT)');
        $db->exec('CREATE TABLE quotes (id INTEGER PRIMARY KEY, property_id INTEGER, status TEXT, created_at TEXT)');
        $db->exec('CREATE TABLE job_geofences (id INTEGER PRIMARY KEY, plan_id INTEGER, property_id INTEGER, zone_type TEXT, border_source TEXT,
                   polygon_json TEXT, vertex_count INTEGER, bbox_lat_min REAL, bbox_lat_max REAL, bbox_lng_min REAL, bbox_lng_max REAL,
                   area_sqm REAL, label TEXT, notes TEXT, drawn_by INTEGER)');
        $db->exec('CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)');
        $db->exec("INSERT INTO ops_settings VALUES ('otto_auto_place', '1', '')");
        return $db;
    }

    public function test_switched_off_does_nothing(): void
    {
        $db = $this->db();
        $db->exec("UPDATE ops_settings SET setting_value = '0'");
        $db->exec("INSERT INTO properties (id, address, city, province) VALUES (1, '2096 W 46th Ave', 'Vancouver', 'BC')");
        $db->exec("INSERT INTO job_plans VALUES (1, 1, 'active')");
        $svc = new OttoAutoPlaceService($db, fn() => ['lat' => 49.2296, 'lng' => -123.1549, 'exact' => true, 'by' => 'osm'],
            new ParcelLotService(self::fetcher([self::lotFeature()])), '2026-10-18');
        $this->assertFalse($svc->run()['ran']);
        $this->assertNull($db->query('SELECT latitude FROM properties WHERE id = 1')->fetchColumn());
    }

    public function test_exact_match_pins_then_gets_the_lot_border(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties (id, address, city, province) VALUES (1, '2096 W 46th Ave', 'Vancouver', 'BC')");
        $db->exec("INSERT INTO quotes VALUES (1, 1, 'sent', '2026-10-04')");   // a quote, no plan yet
        $svc = new OttoAutoPlaceService($db, fn() => ['lat' => 49.2296, 'lng' => -123.1549, 'exact' => true, 'by' => 'osm'],
            new ParcelLotService(self::fetcher([self::lotFeature()])), '2026-10-18');
        $r = $svc->run();
        $this->assertSame(1, $r['pinned']);
        $this->assertSame(1, $r['bordered']);
        $g = $db->query("SELECT border_source, polygon_json, notes FROM job_geofences WHERE property_id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('parcel', $g['border_source']);
        $ring = json_decode($g['polygon_json'], true);
        $this->assertSame($ring[0], $ring[count($ring) - 1], 'stored closed');
        $this->assertStringContainsString('Open Government Licence', $g['notes']);
        $this->assertSame(['by' => 'osm', 'at' => '2026-10-18'], $svc->placedPin(1));
    }

    public function test_approximate_match_is_never_saved_and_not_retried_nightly(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties (id, address, city, province) VALUES (1, 'Somewhere Rd', 'Vancouver', 'BC')");
        $db->exec("INSERT INTO job_plans VALUES (1, 1, 'active')");
        $calls = 0;
        $geo = function () use (&$calls) { $calls++; return ['lat' => 49.2, 'lng' => -123.1, 'exact' => false, 'by' => 'osm']; };
        $svc = new OttoAutoPlaceService($db, $geo, new ParcelLotService(self::fetcher([])), '2026-10-18');
        $this->assertSame(1, $svc->run()['approximate']);
        $this->assertNull($db->query('SELECT latitude FROM properties WHERE id = 1')->fetchColumn());
        $svc->run();
        $this->assertSame(1, $calls, 'tried once; retried after RETRY_DAYS');
    }

    public function test_existing_pins_and_drawn_borders_are_never_touched(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties (id, address, city, province, latitude, longitude) VALUES (1, 'A St', 'Vancouver', 'BC', 49.2296, -123.1549)");
        $db->exec("INSERT INTO properties (id, address, city, province, latitude, longitude) VALUES (2, 'B St', 'Vancouver', 'BC', 49.2296, -123.1549)");
        $db->exec("INSERT INTO job_plans VALUES (1, 1, 'active'), (2, 2, 'active')");
        $db->exec("INSERT INTO job_geofences (property_id, zone_type, border_source, polygon_json) VALUES (1, 'arrival_border', NULL, '[[1,1],[1,2],[2,2],[1,1]]')");
        $db->exec("INSERT INTO job_geofences (property_id, zone_type, border_source, polygon_json) VALUES (2, 'arrival_border', 'default_square', '[[1,1],[1,2],[2,2],[1,1]]')");
        $geoCalls = 0;
        $svc = new OttoAutoPlaceService($db, function () use (&$geoCalls) { $geoCalls++; return null; },
            new ParcelLotService(self::fetcher([self::lotFeature()])), '2026-10-18');
        $r = $svc->run();
        $this->assertSame(0, $geoCalls, 'both already pinned');
        $this->assertSame(1, $r['bordered'], 'only the placeholder square is replaced');
        $this->assertSame([null, 'parcel'], array_column($db->query("SELECT border_source FROM job_geofences ORDER BY property_id")->fetchAll(PDO::FETCH_ASSOC), 'border_source'));
        $this->assertSame('[[1,1],[1,2],[2,2],[1,1]]', $db->query("SELECT polygon_json FROM job_geofences WHERE property_id = 1")->fetchColumn());
    }

    public function test_a_pin_on_the_boundary_is_not_trusted(): void
    {
        $parsed = ParcelLotService::parseFeatures([self::lotFeature()]);
        // 1 m inside the south edge (49.22950): could be the neighbour's lot.
        $this->assertSame('near_edge', ParcelLotService::chooseLot($parsed, 49.229509, -123.1549)['reason']);
    }

    public function test_an_l_shaped_lot_is_stored_exactly_not_hulled(): void
    {
        $l = [[49.0, -123.0], [49.0, -122.9997], [49.0001, -122.9997], [49.0001, -122.99985], [49.0003, -122.99985], [49.0003, -123.0]];
        $this->assertFalse(OttoAutoPlaceService::nearConvex($l));
        $ring = OttoAutoPlaceService::borderRing($l);
        $this->assertSame(array_merge($l, [$l[0]]), $ring, 'concave lot kept as surveyed');
        $square = [[49.0, -123.0], [49.0, -122.9997], [49.0002, -122.9997], [49.0002, -123.0]];
        $this->assertTrue(OttoAutoPlaceService::nearConvex($square));
        $this->assertGreaterThan(PropertyBorderRules::area($square), PropertyBorderRules::area(array_slice(OttoAutoPlaceService::borderRing($square), 0, -1)), 'convex lot grown for the kerb');
    }

    public function test_far_away_pieces_of_the_same_plan_are_not_merged(): void
    {
        $parsed = ParcelLotService::parseFeatures([self::lotFeature('Bare Land Strata', 'EPS42')]);
        $lot = ParcelLotService::chooseLot($parsed, 49.2296, -123.1549);
        $far = ParcelLotService::parseFeatures([self::lotFeature('Bare Land Strata', 'EPS42'), self::lotFeature('Bare Land Strata', 'EPS42', 0.01)]);
        $this->assertSame($lot['area_sqm'], ParcelLotService::mergePlan($lot, $far)['area_sqm'], 'a piece 1 km away is another place');
    }

    public function test_a_pin_on_the_street_uses_the_lot_under_the_building(): void
    {
        $this->assertSame([49.2296, -123.1549], OttoAutoPlaceService::buildingPoint(['lat' => 49.2296, 'lng' => -123.1549, 'exact' => true], [49.2299, -123.1549]));
        $this->assertNull(OttoAutoPlaceService::buildingPoint(['lat' => 49.2296, 'lng' => -123.1549, 'exact' => false], [49.2299, -123.1549]), 'approximate');
        $this->assertNull(OttoAutoPlaceService::buildingPoint(['lat' => 49.2396, 'lng' => -123.1549, 'exact' => true], [49.2299, -123.1549]), '1 km away: another place');

        $db = $this->db();
        $db->exec("INSERT INTO properties (id, address, city, province, latitude, longitude) VALUES (1, 'A St', 'Vancouver', 'BC', 49.2299, -123.1549)");
        $db->exec("INSERT INTO job_plans VALUES (1, 1, 'active')");
        $svc = new OttoAutoPlaceService($db, fn() => ['lat' => 49.2296, 'lng' => -123.1549, 'exact' => true, 'by' => 'osm'],
            new ParcelLotService(self::fetcher([self::lotFeature()])), '2026-10-18');
        $this->assertSame(1, $svc->run()['bordered']);
        $this->assertEqualsWithDelta(49.2299, (float)$db->query('SELECT latitude FROM properties WHERE id = 1')->fetchColumn(), 1e-9, 'pin not moved');
    }

    public function test_a_street_pin_takes_the_clearly_nearest_lot_only(): void
    {
        $lot = ParcelLotService::parseFeatures([self::lotFeature()]);           // lot 49.22950–49.22970
        $pinSouth = 49.22950 - 6 / 111320;                                        // 6 m south of it, in the street
        $near = ParcelLotService::nearestLot($lot, $pinSouth, -123.1549);
        $this->assertTrue($near['ok']);
        $this->assertEqualsWithDelta(6.0, $near['nearest_m'], 0.3);
        $this->assertSame('no_lot', ParcelLotService::nearestLot($lot, 49.22950 - 40 / 111320, -123.1549)['reason'], '40 m away: too far');
        // A lot across the street whose edge is 12 m from the pin: 6 m vs 12 m is not clearly nearer.
        $acrossTop = $pinSouth - 12 / 111320;
        $across = ParcelLotService::parseFeatures([self::lotFeature(), self::lotFeature('Subdivision', 'VAP9', $acrossTop - 49.22970)]);
        $this->assertSame('ambiguous', ParcelLotService::nearestLot($across, $pinSouth, -123.1549)['reason']);
    }

    public function test_the_lot_map_being_down_is_retried_the_next_night(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties (id, address, city, province, latitude, longitude) VALUES (1, 'A St', 'Vancouver', 'BC', 49.2296, -123.1549)");
        $db->exec("INSERT INTO job_plans VALUES (1, 1, 'active')");
        $calls = 0;
        $down = new ParcelLotService(function () use (&$calls) { $calls++; return null; });
        $svc = new OttoAutoPlaceService($db, fn() => null, $down, '2026-10-18');
        $svc->run();
        $first = $calls;
        $svc->run();
        $this->assertGreaterThan($first, $calls, 'asked again, not parked for 30 days');
    }

    public function test_let_otto_draw_one_property_on_request(): void
    {
        $db = $this->db();
        $db->exec("UPDATE ops_settings SET setting_value = '0'");   // a person asked: the switch does not matter
        $db->exec("INSERT INTO properties (id, address, city, province, latitude, longitude) VALUES (1, 'A St', 'Vancouver', 'BC', 49.2296, -123.1549)");
        $db->exec("INSERT INTO properties (id, address, city, province) VALUES (2, 'B St', 'Vancouver', 'BC')");
        $svc = new OttoAutoPlaceService($db, fn() => null, new ParcelLotService(self::fetcher([self::lotFeature()])), '2026-10-18');
        $r = $svc->borderOne(1);
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('city lot map', $r['message']);
        $this->assertFalse($svc->borderOne(1)['ok'], 'already has one now');
        $this->assertSame('It needs a map pin first.', $svc->borderOne(2)['message']);
    }
}
