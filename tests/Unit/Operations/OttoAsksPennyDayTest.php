<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../fixtures/TripTrailFixture.php';

/**
 * The whole loop on SQLite: the 2026-10-07 truck trail + Penny's receipts / bank lines →
 * TripCostService::pricedDay() names what Penny can prove and prices with the scale ticket.
 * (The 1217 columns are probed with SHOW COLUMNS, which SQLite doesn't have, so this runs the
 * pre-1217 insert path — the place is created without source / vendor_id.)
 */
class OttoAsksPennyDayTest extends TestCase
{
    private string $log = '';

    protected function setUp(): void
    {
        $this->log = (string)ini_get('error_log');
        ini_set('error_log', '/dev/null');   // SHOW COLUMNS / time clock probes log on SQLite — expected
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->log);
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE vehicle_location_pings (id INTEGER PRIMARY KEY, lat REAL, lng REAL, speed_kph REAL, recorded_at TEXT)');
        $db->exec('CREATE TABLE ops_places (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, kind TEXT, lat REAL, lng REAL, radius_m INTEGER DEFAULT 150,
                   address TEXT, vendor_match TEXT, active INTEGER DEFAULT 1, created_by INTEGER, created_at TEXT)');
        $db->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY, latitude REAL, longitude REAL, address TEXT, property_name TEXT)');
        $db->exec('CREATE TABLE expenses (id INTEGER PRIMARY KEY, expense_date TEXT, vendor_id INTEGER, vendor_name_raw TEXT, accounting_category TEXT,
                   total REAL, created_at TEXT, created_by INTEGER, receipt_lat REAL, receipt_lng REAL, raw_ocr_json TEXT, status TEXT DEFAULT \'approved\')');
        $db->exec('CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT, aliases TEXT, default_accounting_category TEXT, default_gbp_category TEXT, is_active INTEGER DEFAULT 1)');
        $db->exec('CREATE TABLE accounting_transactions (id INTEGER PRIMARY KEY, transaction_date TEXT, type TEXT, amount REAL, description TEXT, reference_type TEXT, vendor_id INTEGER)');
        $db->exec('CREATE TABLE ops_place_rejections (id INTEGER PRIMARY KEY, stop_date TEXT, lat REAL, lng REAL, name TEXT, created_by INTEGER, created_at TEXT)');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, hourly_rate REAL)');
        $db->exec("INSERT INTO users VALUES (6, 'Nigel', 30)");

        $ins = $db->prepare('INSERT INTO vehicle_location_pings (lat, lng, speed_kph, recorded_at) VALUES (?, ?, ?, ?)');
        foreach (TripTrailFixture::real1007() as $p) $ins->execute([$p['lat'], $p['lng'], $p['speed_kph'], date('Y-m-d H:i:s', $p['t'])]);
        $ins = $db->prepare('INSERT INTO ops_places (id, name, kind, lat, lng, radius_m, vendor_match) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (TripTrailFixture::places() as $p) $ins->execute([$p['id'], $p['name'], $p['kind'], $p['lat'], $p['lng'], $p['radius_m'], $p['vendor_match']]);
        $ins = $db->prepare('INSERT INTO properties (id, latitude, longitude, address, property_name) VALUES (?, ?, ?, ?, ?)');
        foreach (TripTrailFixture::properties() as $p) $ins->execute([$p['id'], $p['latitude'], $p['longitude'], $p['address'], $p['property_name']]);
        $db->exec("INSERT INTO vendors VALUES (12, 'City of Vancouver Vancouver Landfill', 'Vancouver Landfill', 'Disposal/Dump', NULL, 1),
                                              (31, 'Lawn Boy', 'Lawnboy', 'Materials', 'Garden center/nursery', 1)");
        $this->expense($db, TripTrailFixture::ticket411());
        return $db;
    }

    private function expense(PDO $db, array $r): void
    {
        $db->prepare('INSERT INTO expenses (id, expense_date, vendor_id, vendor_name_raw, accounting_category, total, created_at, created_by, receipt_lat, receipt_lng, raw_ocr_json)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$r['id'], $r['expense_date'], $r['vendor_id'], $r['vendor_name_raw'], $r['accounting_category'], $r['total'], $r['created_at'],
                      $r['created_by'], $r['receipt_lat'], $r['receipt_lng'], $r['raw_ocr_json']]);
    }

    private function legs(array $day): array
    {
        $this->assertNotEmpty($day['runs']);
        return $day['runs'][0]['legs'];
    }

    public function test_ticket_411_prices_the_dump_leg_and_the_1015_stop_stays_open(): void
    {
        $db = $this->db();
        $day = (new TripCostService($db))->pricedDay('2026-10-07');
        $dump = $this->legs($day)[0];
        $this->assertSame('Vancouver Transfer Station', $dump['name']);
        $this->assertSame(12.0, $dump['onsite_min']);
        $this->assertSame('ticket', $dump['onsite_basis']);
        $this->assertSame([411], $dump['receipt_ids']);
        $this->assertSame(27.0, $dump['cost']['receipts']);
        $this->assertSame('scale ticket #411 9:49–10:01', $dump['evidence_line']);
        $this->assertSame(['10:15', '11:29'], array_map(fn($u) => date('H:i', $u['start']), $day['unnamed']));
        $this->assertSame([], $day['penny_named']);
        $this->assertSame(2, (int)$db->query('SELECT COUNT(*) FROM ops_places')->fetchColumn(), 'nothing invented');
    }

    public function test_a_lawn_boy_slip_names_the_stop_and_both_visits_become_lawn_boy(): void
    {
        $db = $this->db();
        $this->expense($db, TripTrailFixture::lawnBoySlip());
        $day = (new TripCostService($db))->pricedDay('2026-10-07');
        $this->assertSame(['Lawn Boy'], array_column($day['penny_named'], 'name'));
        $this->assertSame([], $day['unnamed']);
        $place = $db->query("SELECT name, kind, vendor_match FROM ops_places WHERE name = 'Lawn Boy'")->fetch();
        $this->assertSame('supplier', $place['kind']);
        $this->assertSame('lawn boy|lawnboy', $place['vendor_match']);
        $legs = $this->legs($day);
        $this->assertSame(['Vancouver Transfer Station', 'Lawn Boy'], array_column($legs, 'name'));
        $this->assertSame([412], $legs[1]['receipt_ids'], 'the time-matched slip, not every Lawn Boy receipt');
        $this->assertSame('gps', $legs[1]['onsite_basis']);
    }

    public function test_a_supplier_card_charge_is_offered_and_a_no_is_not_offered_again(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO accounting_transactions VALUES (901, '2026-10-07', 'expense', 128.10, 'Point of sale LAWN BOY LANDSCAPE SUP', 'bank_import', NULL)");
        $svc = new TripCostService($db);
        $day = $svc->pricedDay('2026-10-07');
        $first = $day['unnamed'][0];
        $g = $day['proposals'][$first['start']];
        $this->assertSame('Lawn Boy', $g['name']);
        $this->assertSame('weak', $g['strength']);
        $this->assertSame(2, (int)$db->query('SELECT COUNT(*) FROM ops_places')->fetchColumn(), 'a guess creates nothing');

        $r = $svc->confirmStop((float)$first['lat'], (float)$first['lng'], 'Lawn Boy', 'supplier', 31, false, 1, '2026-10-07');
        $this->assertTrue($r['ok']);
        $again = (new TripCostService($db))->pricedDay('2026-10-07');
        $this->assertArrayNotHasKey($first['start'], $again['proposals']);
    }
}
