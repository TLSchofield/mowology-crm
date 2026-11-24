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

    public function test_a_pin_on_no_lot_is_never_given_the_nearest_lot(): void
    {
        // 8655 Laurel St (2026-10-18): the pin sat in a gap of the fabric; the nearest lot 3 m away was the house next door.
        $svc = new ParcelLotService(self::fetcher([self::lotFeature()]));
        $this->assertSame('no_lot', $svc->lotAt(49.22950 - 3 / 111320, -123.1549)['reason']);
    }

    public function test_a_removed_border_is_never_redrawn(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties (id, address, city, province, latitude, longitude) VALUES (1, 'A St', 'Vancouver', 'BC', 49.2296, -123.1549)");
        $db->exec("INSERT INTO job_plans VALUES (1, 1, 'active')");
        $svc = new OttoAutoPlaceService($db, fn() => null, new ParcelLotService(self::fetcher([self::lotFeature()])), '2026-10-18');
        $this->assertSame(1, $svc->run()['bordered']);
        $this->assertTrue($svc->removeLotBorder(1));
        $this->assertSame(0, $svc->run()['bordered'], 'the owner said no');
        $this->assertSame('0', (string)$db->query("SELECT COUNT(*) FROM job_geofences")->fetchColumn());
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

    public function test_looks_right_keeps_otto_border_and_only_otto_lot_borders(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties (id, address, city, province, latitude, longitude) VALUES (1, 'A St', 'Vancouver', 'BC', 49.2296, -123.1549)");
        $svc = new OttoAutoPlaceService($db, fn() => null, new ParcelLotService(self::fetcher([self::lotFeature()])), '2026-10-18');
        $this->assertTrue($svc->borderOne(1)['ok']);
        $this->assertTrue($svc->approveLotBorder(1));
        $this->assertSame('kept', $db->query("SELECT border_source FROM job_geofences WHERE property_id = 1")->fetchColumn());
        $this->assertFalse($svc->approveLotBorder(1), 'nothing left to approve');
        $this->assertFalse($svc->removeLotBorder(1), 'an approved border is no longer Otto\'s to remove');
        $this->assertFalse($svc->borderOne(1)['ok'], 'still counts as having a border');
        $this->assertFalse($svc->approveLotBorder(99), 'no such property');
    }

    /** A City of Vancouver record for 2188 W 14th Ave: a ~20 x 30 m lot, shifted north by $dLat. */
    private static function cityRecord(string $site, float $dLat = 0.0): array
    {
        $ring = [[-123.15505, 49.22950 + $dLat], [-123.15475, 49.22950 + $dLat], [-123.15475, 49.22970 + $dLat],
                 [-123.15505, 49.22970 + $dLat], [-123.15505, 49.22950 + $dLat]];
        return ['civic_number' => '2188', 'streetname' => 'W 14TH AV', 'site_id' => $site,
                'geom' => ['geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]]]];
    }

    private function cityService(array $records, int &$calls = null): CityParcelService
    {
        return new CityParcelService(function (string $url) use ($records, &$calls): ?string {
            $calls++;
            return json_encode(['results' => $records]);
        });
    }

    private function pinlessSvc(PDO $db, array $records, ?array $hit, int &$calls = null): OttoAutoPlaceService
    {
        return new OttoAutoPlaceService($db, fn() => $hit, new ParcelLotService(self::fetcher([])), '2026-11-19', $this->cityService($records, $calls));
    }

    private function pinlessDb(): PDO
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties (id, address, city, province) VALUES (1, '2188 W 14th Ave', 'Vancouver', 'BC')");
        $db->exec("INSERT INTO job_plans VALUES (1, 1, 'active')");
        return $db;
    }

    public function test_approximate_geocode_and_one_city_lot_gives_a_pin_inside_the_lot_and_a_border(): void
    {
        $db = $this->pinlessDb();
        $svc = $this->pinlessSvc($db, [self::cityRecord('S1')], ['lat' => 49.2297, 'lng' => -123.1550, 'exact' => false, 'by' => 'osm']);
        $r = $svc->run();
        $this->assertSame(1, $r['pinned']);
        $this->assertSame(1, $r['bordered']);
        $row = $db->query('SELECT latitude, longitude FROM properties WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $lot = CityParcelService::ringOf(self::cityRecord('S1')['geom']['geometry']);
        $this->assertTrue(PropertyBorderRules::inside([(float)$row['latitude'], (float)$row['longitude']], $lot));
        $this->assertSame('city_lot', $svc->placedPin(1)['by']);
        $this->assertSame('1', (string)$db->query("SELECT COUNT(*) FROM job_geofences WHERE property_id = 1")->fetchColumn());
    }

    public function test_an_approximate_hit_far_from_the_city_lot_draws_nothing(): void
    {
        $db = $this->pinlessDb();
        $svc = $this->pinlessSvc($db, [self::cityRecord('S1')], ['lat' => 49.2296 + 0.018, 'lng' => -123.1549, 'exact' => false, 'by' => 'osm']);   // ~2 km
        $r = $svc->run();
        $this->assertSame(0, $r['pinned']);
        $this->assertNull($db->query('SELECT latitude FROM properties WHERE id = 1')->fetchColumn());
        $this->assertSame('0', (string)$db->query("SELECT COUNT(*) FROM job_geofences")->fetchColumn());
        $this->assertSame('pin_far', $svc->status(1)['border_reason']);
    }

    public function test_two_separate_city_lots_at_one_address_are_ambiguous(): void
    {
        $db = $this->pinlessDb();
        $svc = $this->pinlessSvc($db, [self::cityRecord('S1'), self::cityRecord('S2', 0.0005)], ['lat' => 49.2297, 'lng' => -123.1550, 'exact' => false, 'by' => 'osm']);
        $svc->run();
        $this->assertNull($db->query('SELECT latitude FROM properties WHERE id = 1')->fetchColumn());
        $this->assertSame('ambiguous', $svc->status(1)['border_reason']);
        $city = $this->cityService([self::cityRecord('S1'), self::cityRecord('S2', 0.0005)]);
        $this->assertSame('ambiguous', $city->lotFor(['address' => '2188 W 14th Ave', 'city' => 'Vancouver'], null)['reason']);
    }

    public function test_the_city_lookup_without_a_pin_is_tried_once(): void
    {
        $db = $this->pinlessDb();
        $calls = 0;
        $svc = $this->pinlessSvc($db, [self::cityRecord('S1'), self::cityRecord('S2', 0.0005)], ['lat' => 49.2297, 'lng' => -123.1550, 'exact' => false, 'by' => 'osm'], $calls);
        $svc->run();
        $this->assertSame(1, $calls);
        $svc->run();
        $this->assertSame(1, $calls, 'city_tried stops repeats');
    }

    public function test_an_exact_geocode_still_pins_at_the_geocode_not_the_lot_centre(): void
    {
        $db = $this->pinlessDb();
        $svc = $this->pinlessSvc($db, [self::cityRecord('S1')], ['lat' => 49.22952, 'lng' => -123.1549, 'exact' => true, 'by' => 'osm']);
        $svc->run();
        $this->assertEqualsWithDelta(49.22952, (float)$db->query('SELECT latitude FROM properties WHERE id = 1')->fetchColumn(), 1e-9);
        $this->assertSame('osm', $svc->placedPin(1)['by']);
    }

    public function test_let_otto_draw_a_pinless_property_from_the_city_map(): void
    {
        $db = $this->pinlessDb();
        $svc = $this->pinlessSvc($db, [self::cityRecord('S1')], null);
        $r = $svc->borderOne(1);
        $this->assertTrue($r['ok']);
        $this->assertNotNull($db->query('SELECT latitude FROM properties WHERE id = 1')->fetchColumn());
        $db->exec("INSERT INTO properties (id, address, city, province) VALUES (2, '5 Elm St', 'Burnaby', 'BC')");
        $this->assertSame('It needs a map pin first.', $svc->borderOne(2)['message']);
    }

    public function test_interior_point_is_inside_even_for_a_concave_lot(): void
    {
        $l = [[49.0, -123.0], [49.0, -122.9997], [49.0001, -122.9997], [49.0001, -122.99985], [49.0003, -122.99985], [49.0003, -123.0]];
        $this->assertTrue(PropertyBorderRules::inside(CityParcelService::interiorPoint($l), $l));
        $u = [[49.0, -123.0], [49.0, -122.9990], [49.0010, -122.9990], [49.0010, -122.9993], [49.0002, -122.9993], [49.0002, -122.9997], [49.0010, -122.9997], [49.0010, -123.0]];
        $this->assertTrue(PropertyBorderRules::inside(CityParcelService::interiorPoint($u), $u));
    }

    public function test_a_vancouver_named_property_outside_bc_is_never_pinned_from_the_city_map(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties (id, address, city, province) VALUES (1, '2188 W 14th Ave', 'Vancouver', 'WA')");
        $db->exec("INSERT INTO job_plans VALUES (1, 1, 'active')");
        $calls = 0;
        $svc = $this->pinlessSvc($db, [self::cityRecord('S1')], null, $calls);
        $svc->run();
        $this->assertSame(0, $calls);
        $this->assertNull($db->query('SELECT latitude FROM properties WHERE id = 1')->fetchColumn());
        $this->assertSame('It needs a map pin first.', $svc->borderOne(1)['message']);
    }

    public function test_a_city_map_outage_is_retried_tomorrow_not_every_run(): void
    {
        $db = $this->pinlessDb();
        $calls = 0;
        $down = new CityParcelService(function (string $url) use (&$calls): ?string { $calls++; return null; });
        $svc = new OttoAutoPlaceService($db, fn() => null, new ParcelLotService(self::fetcher([])), '2026-11-19', $down);
        $svc->run();
        $this->assertSame(1, $calls);
        $svc->run();
        $this->assertSame(1, $calls, 'not again the same day');
        $next = new OttoAutoPlaceService($db, fn() => null, new ParcelLotService(self::fetcher([])), '2026-11-20', $down);
        $next->run();
        $this->assertSame(2, $calls, 'tried again the next day');
    }
}
