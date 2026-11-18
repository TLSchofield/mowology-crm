<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Which job a receipt was for — evidence in order (live bug 2026-10-07, expense #412).
 *
 * #412: Lawnboy, 2026-10-07, $218.40, no printed time, photographed by Nigel at 11:22:29 standing
 * at Oakridge Gardens (49.2237050, -123.1165283). Penny suggested "FULL MAINTENANCE — 998 West 19th
 * Avenue" because the trail started at a made-up 05:00 and the truck was parked on West 19th then.
 */
class ReceiptTrailAttributionTest extends TestCase
{
    private const DATE = '2026-10-07';

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, latitude REAL, longitude REAL, address TEXT)");
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INT, title TEXT, service_type TEXT, status TEXT)");
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, scheduled_date TEXT, started_at TEXT)");
        $db->exec("CREATE TABLE vehicle_location_pings (lat REAL, lng REAL, recorded_at TEXT)");
        $db->exec("CREATE TABLE crew_location_history (crew_id INT, latitude REAL, longitude REAL, `timestamp` TEXT, is_office INT)");
        $db->exec("INSERT INTO properties VALUES (41, 49.2239, -123.1168, '650 W 41st Ave'), (42, 49.2557, -123.1290, '998 West 19th Avenue')");
        $db->exec("INSERT INTO job_plans VALUES (501, 41, 'Oakridge Gardens', 'Maintenance', 'active'), (502, 42, 'FULL MAINTENANCE', 'Maintenance', 'active')");
        $db->exec("INSERT INTO job_visits VALUES (1, 501, '2026-10-07', NULL)");
        $p = $db->prepare("INSERT INTO vehicle_location_pings VALUES (?, ?, ?)");
        foreach (['05:00:00', '05:10:00', '05:30:00'] as $t) $p->execute([49.2557, -123.1290, self::DATE . ' ' . $t]);   // parked overnight on W 19th
        foreach (['11:00:00', '11:15:00', '11:40:00'] as $t) $p->execute([49.2239, -123.1168, self::DATE . ' ' . $t]);   // at Oakridge
        return $db;
    }

    private function receipt412(array $over = []): array
    {
        return $over + ['id' => 412, 'created_by' => 6, 'created_at' => '2026-10-07 11:22:29',
                        'receipt_lat' => 49.2237050, 'receipt_lng' => -123.1165283, 'total' => 218.40];
    }

    public function test_412_photographed_at_oakridge_is_oakridge(): void
    {
        $c = (new ReceiptTrailService($this->db()))->forReceipt($this->receipt412(), self::DATE, null);
        $this->assertCount(1, $c);
        $this->assertSame(501, $c[0]['plan_id'], 'Oakridge Gardens, not 998 West 19th');
        $this->assertStringContainsString('photographed at this property', $c[0]['why']);
        $this->assertStringContainsString('11:22', $c[0]['why']);
        $this->assertStringNotContainsString('05:00', $c[0]['why']);
    }

    public function test_no_photo_gps_next_client_stop_within_3h_same_day(): void
    {
        $svc = new ReceiptTrailService($this->db());
        $c = $svc->forReceipt($this->receipt412(['receipt_lat' => null, 'receipt_lng' => null, 'created_at' => '2026-10-07 10:30:00']), self::DATE, null);
        $this->assertSame([501], array_column($c, 'plan_id'), 'the 05:00 West 19th stop is before the photo — never used');
        $this->assertStringContainsString('within 3 h', $c[0]['why']);

        $late = $svc->forReceipt($this->receipt412(['receipt_lat' => null, 'receipt_lng' => null, 'created_at' => '2026-10-07 06:30:00']), self::DATE, null);
        $this->assertSame([], $late, 'Oakridge at 11:00 is more than 3 h after a 06:30 photo');

        $nextDay = $svc->forReceipt($this->receipt412(['created_at' => '2026-10-08 09:00:00']), self::DATE, null);
        $this->assertSame([], $nextDay, 'photo taken another day, no printed time: no trail guess');
    }

    public function test_printed_time_wins(): void
    {
        $c = (new ReceiptTrailService($this->db()))->forReceipt($this->receipt412(['receipt_lat' => null, 'receipt_lng' => null]), self::DATE, '10:45');
        $this->assertSame(501, $c[0]['plan_id']);
        $this->assertStringStartsWith('Printed time 10:45: ', $c[0]['why']);
    }

    public function test_evidence_plan(): void
    {
        $this->assertSame('printed', ReceiptTrailService::evidencePlan(self::DATE, '08:12', '2026-10-07 16:00:00')['tier']);
        $p = ReceiptTrailService::evidencePlan(self::DATE, null, '2026-10-07 11:22:29');
        $this->assertSame(['photo', '2026-10-07 11:22:29', 3], [$p['tier'], $p['at'], $p['window']]);
        $this->assertSame('none', ReceiptTrailService::evidencePlan(self::DATE, null, '2026-10-08 07:00:00')['tier']);
        $this->assertSame('none', ReceiptTrailService::evidencePlan(null, '08:12', null)['tier']);
        $this->assertStringContainsString('outside 06:00–21:00', ReceiptTrailService::evidencePlan(self::DATE, '03:10', null)['note']);
        $this->assertStringContainsString('outside 06:00–21:00', ReceiptTrailService::evidencePlan(self::DATE, null, '2026-10-07 05:00:00')['note']);
    }
}
