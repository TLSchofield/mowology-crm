<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Exercises TrackingIngestService::ingest() end to end against in-memory SQLite,
 * with MySQL's time functions shimmed as identity-on-epoch. Schema probes fail on
 * SQLite (no SHOW COLUMNS), which conveniently exercises the pre-migration-1116
 * path — the one production runs until an admin applies that migration.
 */
class TrackingIngestDbTest extends TestCase
{
    private const NOW = 1790000000;
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('FROM_UNIXTIME', static fn ($t) => (int)$t, 1);
        $this->db->sqliteCreateFunction('UNIX_TIMESTAMP', static fn ($t = null) => $t === null ? null : (int)$t, 1);
        $this->db->sqliteCreateFunction('NOW', static fn () => self::NOW, 0);
        $this->db->exec("CREATE TABLE time_clock_entries (id INTEGER PRIMARY KEY, user_id INT, clock_in INT, clock_out INT)");
        $this->db->exec("CREATE TABLE crew_location_history (id INTEGER PRIMARY KEY, crew_id INT, latitude REAL, longitude REAL,
                         accuracy_meters INT, visit_id INT, is_office INT, timestamp INT)");
    }

    private function shift(int $in, ?int $out): void
    {
        $this->db->prepare("INSERT INTO time_clock_entries (user_id, clock_in, clock_out) VALUES (9, ?, ?)")->execute([$in, $out]);
    }

    private function ingestBody(array $body, ?callable $mayStamp = null, ?callable $isOffice = null): array
    {
        $svc = new TrackingIngestService($this->db);
        return $svc->ingest(
            9, TrackingIngestService::normalizePoints($body, self::NOW), self::NOW,
            $mayStamp ?? static fn (int $v): bool => true,
            $isOffice ?? static fn (float $a, float $b): bool => false
        );
    }

    private function rows(): array
    {
        return $this->db->query("SELECT * FROM crew_location_history ORDER BY timestamp")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function test_legacy_live_ping_is_stored_while_clocked_in(): void
    {
        $this->shift(self::NOW - 3600, null);
        $r = $this->ingestBody(['lat' => 49.25, 'lng' => -123.1, 'accuracy' => 18]);
        $this->assertSame(1, $r['stored']);
        $this->assertGreaterThan(0, $r['last_id']);
        $this->assertSame(self::NOW, (int)$this->rows()[0]['timestamp']);
    }

    public function test_nothing_is_stored_off_the_clock(): void
    {
        $r = $this->ingestBody(['lat' => 49.25, 'lng' => -123.1]);
        $this->assertSame(0, $r['stored']);
        $this->assertSame('outside_shift', $r['rejected'][0]['reason']);
        $this->assertTrue($r['rejected'][0]['retryable']);
        $this->assertSame([], $this->rows());
    }

    public function test_replayed_queue_keeps_its_device_times_and_is_not_throttled_away(): void
    {
        $this->shift(self::NOW - 7200, null);
        $points = [];
        for ($i = 0; $i < 20; $i++) {                       // 20 fixes, 30 s apart, from an hour ago
            $points[] = ['lat' => 49.25 + $i / 10000, 'lng' => -123.1, 'acc' => 10, 't' => (self::NOW - 3600 + $i * 30) * 1000];
        }
        $r = $this->ingestBody(['points' => $points]);
        $this->assertSame(20, $r['stored'], 'the old receipt-time rate limit kept exactly one');
        $stamps = array_map('intval', array_column($this->rows(), 'timestamp'));
        $this->assertSame(self::NOW - 3600, $stamps[0]);
        $this->assertSame(self::NOW - 3600 + 19 * 30, $stamps[19]);
    }

    public function test_replaying_the_same_batch_twice_stores_nothing_new(): void
    {
        $this->shift(self::NOW - 7200, null);
        $body = ['points' => [['lat' => 49.25, 'lng' => -123.1, 't' => (self::NOW - 600) * 1000],
                              ['lat' => 49.26, 'lng' => -123.1, 't' => (self::NOW - 570) * 1000]]];
        $this->assertSame(2, $this->ingestBody($body)['stored']);
        $again = $this->ingestBody($body);
        $this->assertSame(0, $again['stored']);
        $this->assertCount(2, $again['accepted'], 'duplicates are acknowledged so the client can drop them');
        $this->assertCount(2, $this->rows());
    }

    public function test_only_in_shift_points_of_a_mixed_batch_are_stored(): void
    {
        $this->shift(self::NOW - 7200, self::NOW - 3600);   // clocked out an hour ago
        $r = $this->ingestBody(['points' => [
            ['lat' => 49.25, 'lng' => -123.1, 't' => (self::NOW - 5400) * 1000],   // during the shift
            ['lat' => 49.30, 'lng' => -123.2, 't' => (self::NOW - 1800) * 1000],   // drive home, after clock-out
        ]]);
        $this->assertSame(1, $r['stored']);
        $this->assertSame(self::NOW - 5400, (int)$this->rows()[0]['timestamp']);
    }

    public function test_visit_id_the_caller_is_not_crew_on_is_stripped(): void
    {
        $this->shift(self::NOW - 3600, null);
        $this->ingestBody(['lat' => 49.25, 'lng' => -123.1, 'visit_id' => 1418], static fn (int $v): bool => false);
        $this->assertNull($this->rows()[0]['visit_id'], 'a forged visit_id would feed the customer route proof');
    }

    public function test_home_ping_without_a_job_is_flagged_office_but_a_job_ping_never_is(): void
    {
        $this->shift(self::NOW - 3600, null);
        $home = static fn (float $a, float $b): bool => true;
        $this->ingestBody(['points' => [
            ['lat' => 49.25, 'lng' => -123.1, 't' => (self::NOW - 100) * 1000],
            ['lat' => 49.25, 'lng' => -123.1, 't' => (self::NOW - 50) * 1000, 'visit_id' => 7],
        ]], null, $home);
        $rows = $this->rows();
        $this->assertSame(1, (int)$rows[0]['is_office']);
        $this->assertSame(0, (int)$rows[1]['is_office']);
    }
}
