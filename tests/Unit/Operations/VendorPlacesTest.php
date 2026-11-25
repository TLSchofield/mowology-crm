<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VendorPlacesTest extends TestCase
{
    private function sqlite(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) $this->markTestSkipped('pdo_sqlite not available');
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->sqliteCreateFunction('NOW', static function () { return '2026-11-04 12:00:00'; }, 0);
        $db->sqliteCreateFunction('FIELD', static function ($value, ...$values) {
            $i = array_search($value, $values, true);
            return $i === false ? 0 : $i + 1;
        }, -1);
        return $db;
    }

    public function testPointInPolygonAndDistanceToEdge(): void
    {
        $ring = PropertyBorderRules::square(49.2, -123.1, 50);
        $this->assertTrue(PropertyBorderRules::inside([49.2, -123.1], $ring));
        $this->assertFalse(PropertyBorderRules::inside([49.201, -123.1], $ring));
        $this->assertEqualsWithDelta(20, PropertyBorderRules::distanceToEdge([49.2 + 70 / 111320, -123.1], $ring), 1.0);
    }

    public function testParcelStoreMaximumDoesNotChangePropertyDefault(): void
    {
        $lot = ['ok' => true, 'area_sqm' => 30000];
        $this->assertSame('too_big', ParcelLotService::checkArea($lot)['reason']);
        $this->assertTrue(ParcelLotService::checkArea($lot, VendorLotService::STORE_MAX_AREA_SQM)['ok']);
    }

    public function testReceiptPointsClusterOnMedian(): void
    {
        $points = [[49.2, -123.1], [49.2001, -123.1], [49.2002, -123.1], [50.0, -124.0]];
        $clusters = VendorPlacementRules::cluster($points, 120);
        $this->assertCount(1, $clusters);
        $this->assertSame(3, $clusters[0]['count']);
        $this->assertEqualsWithDelta(49.2001, $clusters[0]['center'][0], 0.0000001);
        $this->assertSame([49.2263, -123.1288], VendorLotService::candidatePoint([
            'lat' => null, 'lng' => null, 'geo_note' => 'Only the street was found (nominatim: 49.22630, -123.12880) — not saved',
        ]));
    }

    public function testReceiptPlacementAgesGpsByCaptureTimeNotPurchaseDate(): void
    {
        $db = $this->sqlite();
        $db->exec('CREATE TABLE expenses (id INTEGER PRIMARY KEY, vendor_id INT, status TEXT, expense_date TEXT, created_at TEXT,
                   receipt_lat REAL, receipt_lng REAL)');
        $db->exec('CREATE TABLE vendor_locations (id INTEGER PRIMARY KEY, vendor_id INT, lat REAL, lng REAL, geo_note TEXT,
                   geo_source TEXT, geo_exact INT)');
        $db->exec("INSERT INTO vendor_locations VALUES (1, 1, NULL, NULL, '(candidate: 49.2000, -123.1000)', NULL, 0)");
        $insert = $db->prepare('INSERT INTO expenses VALUES (?, 1, \'approved\', ?, ?, ?, ?)');
        $recent = date('Y-m-d H:i:s', strtotime('-10 days'));
        $old = date('Y-m-d H:i:s', strtotime('-400 days'));
        $insert->execute([1, '2020-01-01', $recent, 49.2000, -123.1000]);
        $insert->execute([2, '2020-01-01', $recent, 49.2001, -123.1000]);
        $insert->execute([3, date('Y-m-d'), $old, 50.0000, -124.0000]);
        $insert->execute([4, date('Y-m-d'), $old, 50.0001, -124.0000]);

        $result = (new VendorLotService($db))->placeFromReceipts();

        $this->assertSame(1, $result['placed']);
        $point = $db->query('SELECT lat, lng FROM vendor_locations WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertEqualsWithDelta(49.20005, (float)$point['lat'], 0.000001);
        $this->assertEqualsWithDelta(-123.1, (float)$point['lng'], 0.000001);
    }

    public function testWeekdayTextParser(): void
    {
        $hours = VendorHoursService::parseWeekdayText([
            'Monday: 8:00 AM – 5:00 PM', 'Tuesday: 8:00 AM – 5:00 PM', 'Wednesday: 8:00 AM – 5:00 PM',
            'Thursday: 8:00 AM – 5:00 PM', 'Friday: 8:00 AM – 5:00 PM', 'Saturday: 9:00 AM – 3:00 PM', 'Sunday: Closed',
        ]);
        $this->assertSame('8:00 AM – 5:00 PM', $hours['weekday']);
        $this->assertSame('9:00 AM – 3:00 PM', $hours['saturday']);
        $this->assertSame('Closed', $hours['sunday']);
    }

    public function testHoursApiFailurePreservesHoursAndLeavesLocationDue(): void
    {
        if (!defined('GOOGLE_PLACES_KEY')) define('GOOGLE_PLACES_KEY', 'test-key');
        $db = $this->sqlite();
        $db->exec('CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT)');
        $db->exec("INSERT INTO ops_settings VALUES ('vendor_hours_lookup', '1')");
        $db->exec('CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec("INSERT INTO vendors VALUES (1, 'Vendor One')");
        $db->exec('CREATE TABLE vendor_locations (id INTEGER PRIMARY KEY, vendor_id INT, address TEXT, city TEXT, lat REAL, lng REAL,
                   hours_weekday TEXT, hours_saturday TEXT, hours_sunday TEXT, hours_source TEXT, hours_checked_at TEXT)');
        $db->exec("INSERT INTO vendor_locations VALUES (1, 1, '1 Main St', 'Vancouver', 49.2, -123.1,
                   '8–5', '9–3', 'Closed', 'google_places', NULL)");
        $service = new VendorHoursService($db, static function () { return false; });

        $result = $service->run();

        $row = $db->query('SELECT hours_weekday, hours_saturday, hours_sunday, hours_source, hours_checked_at FROM vendor_locations')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['saved']);
        $this->assertSame(['hours_weekday' => '8–5', 'hours_saturday' => '9–3', 'hours_sunday' => 'Closed',
                           'hours_source' => 'google_places', 'hours_checked_at' => null], $row);
        $this->assertSame(1, (new VendorHoursService($db, static function () { return false; }))->run()['checked']);
    }

    public function testCommonsNeverMatchesOnGstAlone(): void
    {
        $shared = ['display_name' => 'Esso', 'gst_number' => '123', 'phone_digits' => null, 'domain' => null];
        $this->assertFalse(VendorLotService::commonsMatch(['name' => "McDonald's", 'gst_number' => '123'], $shared));
        $this->assertTrue(VendorLotService::commonsMatch(['name' => 'Esso', 'gst_number' => '123'], $shared));
        $shared['phone_digits'] = '6045551212';
        $this->assertTrue(VendorLotService::commonsMatch(['name' => 'Different', 'phone_digits' => '6045551212'], $shared));
    }

    public function testCommonsLinkUsesPhoneNormalizerAndAliases(): void
    {
        $db = $this->sqlite();
        $db->exec('CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT, gst_number TEXT, phone TEXT, website TEXT, domain TEXT, kb_vendor_id INT)');
        $db->exec("INSERT INTO vendors VALUES (1, 'Phone Shop', NULL, '+1 604-555-1212', NULL, NULL, NULL)");
        $db->exec("INSERT INTO vendors VALUES (2, 'Known Alias', '123456789', NULL, NULL, NULL, NULL)");
        $db->exec('CREATE TABLE kb_vendors (id INTEGER PRIMARY KEY, display_name TEXT, name_key TEXT, gst_number TEXT, phone_digits TEXT,
                   domain TEXT, country TEXT, region TEXT, kind TEXT, status TEXT, tenant_count INT)');
        $db->exec("INSERT INTO kb_vendors VALUES (10, 'Canonical Phone', 'canonical phone', NULL, '6045551212', NULL, NULL, NULL, NULL, 'shared', 2)");
        $db->exec("INSERT INTO kb_vendors VALUES (11, 'Canonical Alias', 'canonical alias', '123456789', NULL, NULL, NULL, NULL, NULL, 'shared', 2)");
        $db->exec('CREATE TABLE kb_vendor_aliases (kb_vendor_id INT, alias_key TEXT)');
        $db->exec("INSERT INTO kb_vendor_aliases VALUES (11, 'known alias')");
        $db->exec('CREATE TABLE kb_vendor_facts (id INTEGER PRIMARY KEY, kb_vendor_id INT, fact TEXT, value_json TEXT, status TEXT, tenants INT, confirmations INT)');

        $result = (new VendorLotService($db))->linkCommons();

        $this->assertSame(2, $result['linked']);
        $this->assertSame(['10', '11'], array_map('strval', $db->query('SELECT kb_vendor_id FROM vendors ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
    }
}
