<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class OttoNewPropertyTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT, province TEXT, postal_code TEXT,
                   latitude REAL, longitude REAL, geocoded_at TEXT, created_at TEXT)');
        $db->exec('CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INTEGER, status TEXT)');
        $db->exec('CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INTEGER, scheduled_date TEXT, status TEXT)');
        $db->exec('CREATE TABLE quotes (id INTEGER PRIMARY KEY, property_id INTEGER, status TEXT, created_at TEXT)');
        $db->exec('CREATE TABLE job_geofences (id INTEGER PRIMARY KEY, plan_id INTEGER, property_id INTEGER, zone_type TEXT, border_source TEXT,
                   polygon_json TEXT, vertex_count INTEGER, bbox_lat_min REAL, bbox_lat_max REAL, bbox_lng_min REAL, bbox_lng_max REAL,
                   area_sqm REAL, label TEXT, notes TEXT, drawn_by INTEGER)');
        $db->exec('CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)');
        return $db;
    }

    private function service(PDO $db, ?callable $geocode = null, ?array &$urls = null): OttoAutoPlaceService
    {
        $urls = [];
        $ring = [[-123.15505, 49.22950], [-123.15475, 49.22950], [-123.15475, 49.22970], [-123.15505, 49.22970], [-123.15505, 49.22950]];
        $feature = ['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]],
                    'properties' => ['PARCEL_CLASS' => 'Subdivision', 'PLAN_NUMBER' => 'VAP1', 'PID_FORMATTED' => '1']];
        $fetch = function (string $url) use (&$urls, $feature): string {
            $urls[] = $url;
            return json_encode(['type' => 'FeatureCollection', 'features' => [$feature]]);
        };
        return new OttoAutoPlaceService($db, $geocode ?? fn() => ['lat' => 49.2296, 'lng' => -123.1549, 'exact' => true],
            new ParcelLotService($fetch), '2026-11-03');
    }

    public function test_fresh_rows_are_pinned_and_bordered_and_watermark_is_zero(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties VALUES (1, 'A St', 'Vancouver', 'BC', '', NULL, NULL, NULL, '2026-11-03 12:00:00')");
        $r = $this->service($db)->run();
        $this->assertSame(1, $r['new']);
        $this->assertSame(1, $r['pinned']);
        $this->assertSame(1, $r['bordered']);
        $this->assertSame('0', $db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'otto_new_after_id'")->fetchColumn());
    }

    public function test_old_rows_are_frozen_out_but_later_ids_are_new_even_when_backdated(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties VALUES (5, 'Old', 'Vancouver', 'BC', '', NULL, NULL, NULL, '2024-01-01')");
        $svc = $this->service($db);
        $this->assertSame(0, $svc->run()['new']);
        $db->exec("INSERT INTO properties VALUES (6, 'Imported', 'Vancouver', 'BC', '', NULL, NULL, NULL, '2024-01-01')");
        $this->assertSame(1, $svc->run()['new']);
        $this->assertNotFalse($db->query('SELECT latitude FROM properties WHERE id = 6')->fetchColumn());
        $this->assertNull($db->query('SELECT latitude FROM properties WHERE id = 5')->fetchColumn());
    }

    /** @dataProvider propertyCreationShapes */
    public function test_creation_insert_shapes_are_found_by_the_frozen_id_watermark(string $source, string $insert): void
    {
        $db = $this->db();
        $db->exec('ALTER TABLE properties ADD COLUMN property_name TEXT');
        $db->exec('ALTER TABLE properties ADD COLUMN property_type TEXT');
        $db->exec('ALTER TABLE properties ADD COLUMN site_contact_id INTEGER');
        $db->exec('ALTER TABLE properties ADD COLUMN status TEXT');
        $db->exec("INSERT INTO properties (id, address, created_at) VALUES (1, 'Old', '2024-01-01')");
        $svc = $this->service($db);
        $svc->run();
        $db->exec($insert);
        $this->assertArrayHasKey(2, $svc->newCandidates(), $source);
    }

    public static function propertyCreationShapes(): array
    {
        return [
            'QuickBooks onboarding / ContactService' => ['QuickBooks onboarding / ContactService',
                "INSERT INTO properties (id, property_name, address, city, province, postal_code, latitude, longitude, site_contact_id, status)
                 VALUES (2, 'QB Property', '2 Main', 'Vancouver', 'BC', '', NULL, NULL, 4, 'active')"],
            'FieldJobService and AppStack pages' => ['FieldJobService and AppStack pages',
                "INSERT INTO properties (id, property_name, address, city, province, postal_code, site_contact_id, status)
                 VALUES (2, 'Job Property', '2 Main', 'Vancouver', 'BC', '', 4, 'active')"],
            'EmailLeadService and quote-request API' => ['EmailLeadService and quote-request API',
                "INSERT INTO properties (id, property_name, property_type, address, city, province, postal_code, status)
                 VALUES (2, 'Lead Property', 'commercial', '2 Main', 'Vancouver', 'BC', '', 'lead')"],
            'JobberImportService / reconsent import' => ['JobberImportService / reconsent import',
                "INSERT INTO properties (id, address, city, province, postal_code, site_contact_id, status, created_at)
                 VALUES (2, '2 Main', 'Vancouver', 'BC', '', 4, 'active', '2024-01-01')"],
            'jobFlow confirmation page' => ['jobFlow confirmation page',
                "INSERT INTO properties (id, address, city, province, postal_code, latitude, longitude)
                 VALUES (2, '2 Main', 'Vancouver', 'BC', '', NULL, NULL)"],
        ];
    }

    public function test_regions_switch_and_lock_names(): void
    {
        foreach (['BC', 'b.c.', 'B.C', 'British Columbia (BC)', 'BRITISH COLUMBIA', 'BC - British Columbia'] as $value) {
            $this->assertSame('BC', OttoAutoPlaceService::regionOf(['province' => $value]));
        }
        $this->assertSame('AB', OttoAutoPlaceService::regionOf(['province' => 'Alberta']));
        $this->assertSame('ON', OttoAutoPlaceService::regionOf(['province' => 'ON']));
        $this->assertSame('BC', OttoAutoPlaceService::regionOf(['province' => '', 'latitude' => 49.2, 'longitude' => -123.1]));
        $this->assertNull(OttoAutoPlaceService::regionOf(['province' => '', 'latitude' => 51.05, 'longitude' => -114.07]));
        $this->assertTrue(OttoAutoPlaceService::switchOn(null));
        $this->assertTrue(OttoAutoPlaceService::switchOn(false));
        $this->assertFalse(OttoAutoPlaceService::switchOn('0'));
        $this->assertLessThanOrEqual(64, strlen(OttoAutoPlaceService::lockName(str_repeat('x', 100))));
    }

    public function test_enabled_defaults_on_and_only_explicit_zero_turns_it_off(): void
    {
        $db = $this->db();
        $svc = $this->service($db);
        $this->assertTrue($svc->enabled());
        $db->exec("INSERT INTO ops_settings VALUES ('otto_auto_place', '0', '')");
        $this->assertFalse($svc->enabled());
        $db->exec("UPDATE ops_settings SET setting_value = '1' WHERE setting_key = 'otto_auto_place'");
        $this->assertTrue($svc->enabled());
    }

    public function test_more_than_new_limit_are_drained_on_later_runs_instead_of_starving(): void
    {
        $db = $this->db();
        for ($id = 1; $id <= 102; $id++) {
            $db->prepare('INSERT INTO properties VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL, ?)')
                ->execute([$id, $id . ' A St', 'Vancouver', 'BC', '', '2026-11-03']);
        }
        $svc = $this->service($db);
        $this->assertSame(100, $svc->run(15, false, 100)['new']);
        $this->assertSame(2, $svc->run(15, false, 100)['new']);
        $this->assertSame(102, (int)$db->query('SELECT COUNT(*) FROM properties WHERE latitude IS NOT NULL')->fetchColumn());
    }

    public function test_old_limit_counts_work_not_completed_candidates(): void
    {
        $db = $this->db();
        for ($id = 1; $id <= 16; $id++) {
            $db->prepare('INSERT INTO properties VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)')
                ->execute([$id, $id . ' A St', 'Vancouver', 'BC', '', $id <= 15 ? 49.2 : null, $id <= 15 ? -123.1 : null, '2024-01-01']);
            $db->prepare("INSERT INTO quotes VALUES (?, ?, 'accepted', '2026-01-01')")->execute([$id, $id]);
            if ($id <= 15) {
                $db->prepare("INSERT INTO job_geofences (id, property_id, zone_type, border_source) VALUES (?, ?, 'arrival_border', 'parcel')")
                    ->execute([$id, $id]);
            }
        }
        $svc = $this->service($db);
        $svc->run(); // Freeze the old/new watermark; no old row needs work yet except 16.
        $this->assertNotFalse($db->query('SELECT latitude FROM properties WHERE id = 16')->fetchColumn());
    }

    public function test_manual_pin_clears_a_previous_approximate_reason(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties VALUES (1, 'A St', 'Vancouver', 'BC', '', NULL, NULL, NULL, '2026-11-03')");
        $svc = $this->service($db, fn() => ['lat' => 49.2, 'lng' => -123.1, 'exact' => false]);
        $svc->run();
        $this->assertSame('approximate', $svc->status(1)['pin_reason']);
        $db->exec('UPDATE properties SET latitude = 49.2296, longitude = -123.1549 WHERE id = 1');
        $svc->run();
        $this->assertNull($svc->status(1)['pin_reason']);
    }

    public function test_alberta_stops_before_fetch_and_blank_address_has_a_reason(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties VALUES (1, 'A St', 'Calgary', 'Alberta', '', 51.05, -114.07, NULL, '2026-11-03')");
        $urls = [];
        $svc = $this->service($db, null, $urls);
        $svc->run();
        $this->assertSame([], $urls);
        $this->assertSame('no_source:AB', $svc->status(1)['border_reason']);
        $this->assertStringContainsString('Alberta', $svc->status(1)['border_text']);

        $db->exec("INSERT INTO properties VALUES (2, '', '', '', '', NULL, NULL, NULL, '2026-11-03')");
        $svc->run();
        $this->assertSame('no_address', $svc->status(2)['pin_reason']);
    }

    /** The real ContactService insert (QuickBooks sub-customers, web and phone "add property") is picked up as new. */
    public function test_a_property_added_through_contact_service_is_new(): void
    {
        $db = $this->db();
        foreach (['property_name TEXT', 'site_contact_id INTEGER', "status TEXT"] as $col) $db->exec("ALTER TABLE properties ADD COLUMN $col");
        $db->exec("INSERT INTO properties (id, address, created_at) VALUES (1, 'Old Rd', '2024-01-01')");
        $svc = $this->service($db);
        $svc->run();   // freezes the starting line at id 1
        $made = (new ContactService($db))->addPropertyToContact(7, ['address' => '348 Worthington St', 'city' => 'Vancouver', 'province' => 'BC']);
        $this->assertTrue($made['created']);
        $this->assertSame([$made['property_id']], array_keys($svc->newCandidates()));
        $this->assertSame(1, $svc->run()['bordered']);
    }

    /** Old candidates with no address never take a slot: the rest of the old set still gets its turn. */
    public function test_old_candidates_without_an_address_do_not_starve_the_rest(): void
    {
        $db = $this->db();
        for ($id = 1; $id <= 3; $id++) {
            $db->prepare("INSERT INTO properties VALUES (?, '', 'Vancouver', 'BC', '', NULL, NULL, NULL, '2024-01-01')")->execute([$id]);
            $db->prepare("INSERT INTO quotes VALUES (?, ?, 'accepted', '2026-10-01')")->execute([$id, $id]);
        }
        $db->exec("INSERT INTO properties VALUES (4, 'A St', 'Vancouver', 'BC', '', NULL, NULL, NULL, '2024-01-01')");
        $db->exec("INSERT INTO quotes VALUES (4, 4, 'accepted', '2026-10-01')");
        $r = $this->service($db)->run(2);
        $this->assertSame(1, $r['pinned'], 'the one with an address is reached despite a limit of 2');
    }

    /** An Ittai business starts at 0: imports that kept old created_at dates still count as new. */
    public function test_a_business_counts_every_property_whatever_its_created_at(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties VALUES (1, 'A St', 'Vancouver', 'BC', '', NULL, NULL, NULL, '2019-05-01')");
        $ring = [[-123.15505, 49.22950], [-123.15475, 49.22950], [-123.15475, 49.22970], [-123.15505, 49.22970], [-123.15505, 49.22950]];
        $fetch = fn() => json_encode(['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]],
                    'properties' => ['PARCEL_CLASS' => 'Subdivision', 'PLAN_NUMBER' => 'VAP1', 'PID_FORMATTED' => '1']]]]);
        $svc = new OttoAutoPlaceService($db, fn() => ['lat' => 49.2296, 'lng' => -123.1549, 'exact' => true], new ParcelLotService($fetch), '2026-11-03', null, false);
        $this->assertSame(1, $svc->run()['new']);
        $this->assertSame(0, $svc->watermark());
    }

    /** A web "Remove it" or "Let Otto draw it" during a long run is kept; this run's own results win for what it looked at. */
    public function test_the_end_of_run_save_keeps_what_the_web_wrote_meanwhile(): void
    {
        $fresh = ['pins' => [], 'pin_tried' => [], 'pin_reason' => [], 'border_tried' => [], 'border_reason' => [9 => 'no_lot'],
                  'border_refused' => [5 => '2026-11-03'], 'city_tried' => []];
        $ours = ['pins' => [], 'pin_tried' => [], 'pin_reason' => [], 'border_tried' => [1 => '2026-11-03'], 'border_reason' => [1 => 'no_lot', 7 => 'too_big'],
                 'border_refused' => [], 'city_tried' => []];
        $m = OttoAutoPlaceService::mergeState($fresh, $ours, [1]);
        $this->assertSame([5 => '2026-11-03'], $m['border_refused'], 'a refusal made during the run survives');
        $this->assertSame([9 => 'no_lot', 1 => 'no_lot'], $m['border_reason'], "untouched 7 keeps the database's (absent) value; 9 written by the web stays");
    }

    /** "Let Otto draw it" that fails is remembered, so the card can say why after a reload. */
    public function test_a_failed_manual_draw_is_remembered(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties VALUES (1, 'A St', 'Calgary', 'Alberta', '', 51.05, -114.07, NULL, '2026-11-03')");
        $svc = $this->service($db);
        $this->assertFalse($svc->borderOne(1)['ok']);
        $this->assertSame('no_source:AB', $svc->status(1)['border_reason']);
    }
}
