<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Otto's supplier stops (migration 1359) on fake tracks shaped like the real days:
 * 2026-10-07 — Nigel's phone at Lawnboy 10:13–10:24 (with a 40 s walk out of the circle),
 * 11:27–11:34 and 12:25–12:41, slips #412 (photographed 11:22, after the first stop), #413
 * (11:32, at the second) and #417 (No 006, photographed the next morning); passes of 1–2 points
 * don't count. 2026-09-25 — the truck idles 238 m away at the transfer station 08:29–08:46, then
 * stops at Lawnboy 08:51–08:57.
 */
class SupplierStopServiceTest extends TestCase
{
    private const LAWNBOY = [49.2070861, -123.1178102];
    private string $tz;

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('America/Vancouver');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    private function locations(): array
    {
        return [
            ['key' => 'vl:17', 'vendor_id' => 44, 'name' => 'LAWNBOY', 'kind' => 'supplier', 'lat' => self::LAWNBOY[0], 'lng' => self::LAWNBOY[1]],
            ['key' => 'op:6', 'vendor_id' => 50, 'name' => 'CHEVRON', 'kind' => 'fuel', 'lat' => 49.2575420, 'lng' => -123.1677400],
        ];
    }

    /** A point $m metres north of Lawnboy at a local time. */
    private function at(string $date, string $hms, float $m, ?float $acc = 5.0): array
    {
        return ['lat' => self::LAWNBOY[0] + $m / 111320, 'lng' => self::LAWNBOY[1], 't' => strtotime("$date $hms"), 'acc' => $acc];
    }

    /** Points every $step s from $from to $to at $m metres. */
    private function dwell(string $date, string $from, string $to, float $m, int $step = 6): array
    {
        $out = [];
        for ($t = strtotime("$date $from"); $t <= strtotime("$date $to"); $t += $step) $out[] = $this->at($date, date('H:i:s', $t), $m);
        return $out;
    }

    private function oct7Phone(): array
    {
        $d = '2026-10-07';
        return array_merge(
            [$this->at($d, '09:00:00', 3000)],
            [$this->at($d, '09:44:00', 60), $this->at($d, '09:44:10', 70)],                 // drive-by: 2 points, 10 s
            [$this->at($d, '10:02:09', 185), $this->at($d, '10:02:19', 190)],               // pass 185 m away
            $this->dwell($d, '10:13:14', '10:15:55', 15),
            $this->dwell($d, '10:16:03', '10:16:40', 105),                                   // 40 s to the far bins
            $this->dwell($d, '10:16:45', '10:24:26', 40),
            [$this->at($d, '10:25:07', 250), $this->at($d, '11:00:00', 2500)],
            $this->dwell($d, '11:27:22', '11:34:11', 10),
            [$this->at($d, '11:40:00', 2500)],
            $this->dwell($d, '12:25:39', '12:41:10', 5),
            [$this->at($d, '12:50:00', 2500)]
        );
    }

    public function testFindsTheThreeLawnboyStopsAndSkipsThePasses(): void
    {
        $stops = SupplierStopService::findStops($this->oct7Phone(), $this->locations());
        $this->assertSame(['10:13', '11:27', '12:25'], array_map(fn($s) => date('H:i', $s['start']), $stops));
        $this->assertSame(['10:24', '11:34', '12:41'], array_map(fn($s) => date('H:i', $s['end']), $stops));
        $this->assertSame('vl:17', $stops[0]['key']);
    }

    public function testALongWalkOutOfTheCircleSplitsTheStop(): void
    {
        $d = '2026-10-07';
        $pts = array_merge($this->dwell($d, '10:00:00', '10:04:00', 10), $this->dwell($d, '10:04:06', '10:08:00', 500),
                           $this->dwell($d, '10:08:06', '10:12:00', 10));
        $this->assertCount(2, SupplierStopService::findStops($pts, $this->locations()));
    }

    public function testCoarseFixesAreIgnored(): void
    {
        $d = '2026-10-07';
        $pts = array_map(fn($p) => ['acc' => 200.0] + $p, $this->dwell($d, '10:00:00', '10:10:00', 10));
        $this->assertSame([], SupplierStopService::findStops($pts, $this->locations()));
    }

    public function testTruckIdlingAtTheTransferStationIsNotALawnboyStop(): void
    {
        $d = '2026-09-25';
        $pts = array_merge($this->dwell($d, '08:29:02', '08:46:02', 238, 60), $this->dwell($d, '08:51:01', '08:57:01', 12, 60),
                           [$this->at($d, '08:58:02', 138), $this->at($d, '08:59:01', 250)]);
        $pts = array_map(fn($p) => ['acc' => null] + $p, $pts);
        $stops = SupplierStopService::findStops($pts, $this->locations());
        $this->assertCount(1, $stops);
        $this->assertSame('08:51', date('H:i', $stops[0]['start']));
        $this->assertSame(6, (int)round(($stops[0]['end'] - $stops[0]['start']) / 60));
    }

    public function testPhoneAndTruckAtTheSamePlaceAreOneStop(): void
    {
        $t = strtotime('2026-10-07 11:27:00');
        $merged = SupplierStopService::merge([
            ['key' => 'vl:17', 'start' => $t, 'end' => $t + 400, 'nearest_m' => 8, 'points' => 60, 'source' => ['user', 6]],
            ['key' => 'vl:17', 'start' => $t + 60, 'end' => $t + 420, 'nearest_m' => 6, 'points' => 7, 'source' => ['device', '372356216']],
            ['key' => 'vl:17', 'start' => $t + 3600, 'end' => $t + 4000, 'nearest_m' => 5, 'points' => 9, 'source' => ['user', 6]],
        ]);
        $this->assertCount(2, $merged);
        $this->assertSame([6], $merged[0]['user_ids']);
        $this->assertSame(['372356216'], $merged[0]['device_ids']);
        $this->assertSame($t + 420, $merged[0]['end']);
        $this->assertSame('both', SupplierStopService::seenBy($merged[0], [7]));
        $this->assertSame('truck', SupplierStopService::seenBy(['user_ids' => [7], 'device_ids' => []], [7]));
        $this->assertSame('phone', SupplierStopService::seenBy($merged[1], [7]));
    }

    public function testEachSlipGoesToItsStop(): void
    {
        $d = '2026-10-07';
        $stops = [];
        foreach ([['10:13:14', '10:24:26'], ['11:27:22', '11:34:11'], ['12:25:39', '12:41:10']] as [$a, $b]) {
            $stops[] = ['location_key' => 'vl:17', 'start' => strtotime("$d $a"), 'end' => strtotime("$d $b")];
        }
        $locs = ['vl:17' => $this->locations()[0]];
        $rc = fn(int $id, ?string $photo, array $times = [], int $vendor = 44, string $name = 'LAWNBOY') =>
            ['id' => $id, 'vendor_id' => $vendor, 'name' => $name, 'times' => $times, 'in' => null, 'out' => null,
             'created_at' => $photo ? strtotime($photo) : null];
        $map = SupplierStopService::assignReceipts($stops, $locs, [
            $rc(412, "$d 11:22:29"),                    // photographed at the job after the first stop
            $rc(413, "$d 11:32:22"),                    // photographed at the counter
            $rc(417, '2026-10-08 08:05:34'),            // No 006, photographed next morning
            $rc(406, "$d 08:09:00", [], 50, 'CHEVRON'), // another supplier: not Lawnboy's
        ], $d);
        $this->assertSame([[412, 'photo']], array_map(fn($r) => [$r['receipt_id'], $r['basis']], $map[0]));
        $this->assertSame([[413, 'photo']], array_map(fn($r) => [$r['receipt_id'], $r['basis']], $map[1]));
        $this->assertSame([[417, 'date']], array_map(fn($r) => [$r['receipt_id'], $r['basis']], $map[2]));

        // A printed till time beats the photo time.
        $map = SupplierStopService::assignReceipts($stops, $locs, [$rc(500, "$d 12:50:00", [strtotime("$d 11:30:00")])], $d);
        $this->assertSame(500, $map[1][0]['receipt_id']);
        $this->assertSame('printed', $map[1][0]['basis']);
    }

    public function testReceiptMatchesByVendorOrName(): void
    {
        $loc = ['vendor_id' => null, 'name' => 'Cloverdale Topsoil'];
        $this->assertTrue(SupplierStopService::receiptMatches($loc, ['vendor_id' => 9, 'name' => 'CLOVERDALE TOPSOIL LTD']));
        $this->assertFalse(SupplierStopService::receiptMatches($loc, ['vendor_id' => 9, 'name' => 'Home Depot']));
        $this->assertTrue(SupplierStopService::receiptMatches(['vendor_id' => 44, 'name' => 'x'], ['vendor_id' => 44, 'name' => 'y']));
    }

    public function testParkingIsNotAPurchase(): void
    {
        $t = strtotime('2026-09-12 11:22:00');
        $this->assertFalse(SupplierStopService::plausible('fuel', $t, $t + 523 * 60, 'truck', false), '9 h at the Chevron = parked');
        $n = strtotime('2026-09-12 22:15:00');
        $this->assertFalse(SupplierStopService::plausible('fuel', $n, $n + 600, 'truck', false), 'night drift around a parked truck');
        $this->assertTrue(SupplierStopService::plausible('fuel', $n, $n + 600, 'phone', true));
        $m = strtotime('2026-10-09 08:19:00');
        $this->assertTrue(SupplierStopService::plausible('fuel', $m, $m + 360, 'truck', false), 'morning fuel before clocking in');
        $this->assertTrue(SupplierStopService::plausible('supplier', $m, $m + 19 * 60, 'both', true));
    }

    public function testMissingReceiptWaitsUntilSixTheNextEvening(): void
    {
        $this->assertFalse(SupplierStopService::missingDue('2026-10-07', strtotime('2026-10-08 17:59:00')));
        $this->assertTrue(SupplierStopService::missingDue('2026-10-07', strtotime('2026-10-08 18:00:00')));
        $this->assertFalse(SupplierStopService::missingDue('2026-10-07', strtotime('2026-10-15 09:00:00')), 'older than a week');
    }

    public function testGeocodeQueryAndStreetKey(): void
    {
        $q = SupplierStopService::geocodeQuery(['address' => '8655 Cambie St., Van. BC V6P 3J9', 'city' => null]);
        $this->assertSame('8655 Cambie St., Vancouver BC V6P 3J9', $q);
        $this->assertSame('6525 OAK ST, VANCOUVER, BC', SupplierStopService::geocodeQuery(['address' => '6525 OAK ST', 'city' => 'VANCOUVER']));
        $this->assertNull(SupplierStopService::geocodeQuery(['address' => null, 'city' => null]));
        $this->assertNull(SupplierStopService::geocodeQuery(['address' => 'Main', 'city' => null]));
        $this->assertSame(SupplierStopService::streetKey('5190 Ladner Trunk Rd., Ladner, BC V4K 1W3'),
                          SupplierStopService::streetKey('5190 Ladner Trunk Rd, Delta, BC V4K 1W3'));
        $this->assertSame('7430 hopcott', SupplierStopService::streetKey('7430 HOPCOTT RD'));
        $this->assertSame('4663 byrne', SupplierStopService::streetKey('111-4663 Byrne Rd, Burnaby'));
        $this->assertSame('820 marine', SupplierStopService::streetKey('820 SW Marine Dr.'));
    }

    public function testLine(): void
    {
        $this->assertSame('LAWNBOY Oct 7 11:27–11:34 · Nigel, truck', SupplierStopService::line([
            'place_name' => 'LAWNBOY', 'arrived_at' => '2026-10-07 11:27:22', 'left_at' => '2026-10-07 11:34:11', 'people' => 'Nigel', 'seen_by' => 'both']));
    }

    // ── Database: geocoding once, "nothing was bought" ──────────────────────────

    private function sqlite(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) $this->markTestSkipped('pdo_sqlite not available');
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("CREATE TABLE vendor_locations (id INTEGER PRIMARY KEY, vendor_id INT, label TEXT, address TEXT, city TEXT, lat REAL, lng REAL,
                   source TEXT, receipts_seen INT DEFAULT 0, geo_source TEXT, geo_exact INT, geocoded_at TEXT, geo_note TEXT)");
        $db->exec("CREATE TABLE supplier_stops (id INTEGER PRIMARY KEY, stop_date TEXT, receipt_status TEXT, decided_by INT, decided_at TEXT)");
        $db->exec("INSERT INTO vendors (id, name) VALUES (44, 'LAWNBOY'), (52, 'Dunbar Lumber'), (1, 'Home Depot'), (67, 'AMOUNT'), (6, 'Esso')");
        $db->exec("INSERT INTO vendor_locations (id, vendor_id, address, city, lat, lng) VALUES
                   (3, 52, '5190 Ladner Trunk Rd, Delta, BC V4K 1W3', 'Delta', 49.0884, -123.0822),
                   (6, 67, NULL, NULL, NULL, NULL),
                   (9, 6, '6525 OAK ST', 'VANCOUVER', NULL, NULL),
                   (10, 1, '5190 Ladner Trunk Rd., Ladner, BC V4K 1W3', NULL, NULL, NULL),
                   (17, 44, '8655 Cambie St., Van. BC V6P 3J9', NULL, NULL, NULL)");
        return $db;
    }

    public function testGeocodeSavesBuildingsOnlyAndNeverAsksTwice(): void
    {
        $db = $this->sqlite();
        $asked = [];
        $fake = function (string $q) use (&$asked) {
            $asked[] = $q;
            if (strpos($q, 'Cambie') !== false) return ['lat' => 49.2070861, 'lng' => -123.1178102, 'by' => 'osm', 'exact' => true];
            return ['lat' => 49.2263, 'lng' => -123.1288, 'by' => 'osm', 'exact' => false];   // only the street
        };
        $res = (new SupplierStopService($db))->geocodePending($fake);
        $by = array_column($res, 'result', 'id');
        $this->assertSame('no address', $by[6]);
        $this->assertSame('street only', $by[9]);
        $this->assertStringStartsWith('same address as Dunbar Lumber', $by[10]);
        $this->assertSame('saved', $by[17]);
        $this->assertCount(2, $asked, 'the Home Depot row at Dunbar\'s address is never sent');
        $l = $db->query("SELECT lat, lng, geo_source, geo_exact FROM vendor_locations WHERE id = 17")->fetch();
        $this->assertEqualsWithDelta(49.2070861, (float)$l['lat'], 1e-6);
        $this->assertSame('nominatim', $l['geo_source']);
        $this->assertNull($db->query("SELECT lat FROM vendor_locations WHERE id = 9")->fetchColumn() ?: null);
        $this->assertSame([], (new SupplierStopService($db))->geocodePending($fake), 'tried once; a person sets the rest');
    }

    public function testNothingWasBoughtClosesTheStopOnce(): void
    {
        $db = $this->sqlite();
        $db->exec("INSERT INTO supplier_stops (id, stop_date) VALUES (5, '2026-10-09')");
        $svc = new SupplierStopService($db);
        $this->assertTrue($svc->markNone(5, 1));
        $this->assertFalse($svc->markNone(5, 1));
        $this->assertSame('none', $db->query("SELECT receipt_status FROM supplier_stops WHERE id = 5")->fetchColumn());
    }
}
