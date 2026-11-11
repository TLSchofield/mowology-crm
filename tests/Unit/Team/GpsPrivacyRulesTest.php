<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** 2026-10-12 review: mock fixes, compliance-event coordinates, withdrawn consent. */
final class GpsPrivacyRulesTest extends TestCase
{
    private const NOW = 1790000000;

    public function testEventCoordinatesAreKeptOnlyInsideAShiftWhileCollectionIsAllowed(): void
    {
        $events = [
            ['id' => 'a', 'type' => 'x', 'ts' => self::NOW - 600,  'lat' => 49.2, 'lng' => -123.1, 'acc' => 9],   // in the shift
            ['id' => 'b', 'type' => 'x', 'ts' => self::NOW - 9000, 'lat' => 49.2, 'lng' => -123.1, 'acc' => 9],   // before it
        ];
        $shifts = [[self::NOW - 3600, null]];

        $allowed = TrackingIngestService::withoutLocationUnlessAllowed($events, true, $shifts, self::NOW);
        $this->assertSame(49.2, $allowed[0]['lat']);
        $this->assertNull($allowed[1]['lat']);
        $this->assertNull($allowed[1]['acc']);

        $notAllowed = TrackingIngestService::withoutLocationUnlessAllowed($events, false, $shifts, self::NOW);
        $this->assertNull($notAllowed[0]['lat']);
        $this->assertNull($notAllowed[0]['lng']);
        $this->assertSame('a', $notAllowed[0]['id'], 'the event itself is kept');
    }

    public function testWithdrawnConsentSilencesTheSilentPhoneAlert(): void
    {
        $this->assertTrue(TrackingHealthService::consentSilences(['withdrawn_at' => '2026-10-01 09:00:00'], false, false));
        $this->assertTrue(TrackingHealthService::consentSilences(null, true, false), 'required and missing');
        $this->assertFalse(TrackingHealthService::consentSilences(null, false, false), 'not required, never asked');
        $this->assertFalse(TrackingHealthService::consentSilences(['withdrawn_at' => null], true, true));
    }

    public function testAMockFixIsStoredButNeverTheNewestRealFix(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction('FROM_UNIXTIME', static fn ($t) => (int)$t, 1);
        $db->sqliteCreateFunction('UNIX_TIMESTAMP', static fn ($t = null) => $t === null ? null : (int)$t, 1);
        $db->sqliteCreateFunction('NOW', static fn () => self::NOW, 0);
        $db->exec("CREATE TABLE time_clock_entries (id INTEGER PRIMARY KEY, user_id INT, clock_in INT, clock_out INT)");
        $db->exec("CREATE TABLE crew_location_history (id INTEGER PRIMARY KEY, crew_id INT, latitude REAL, longitude REAL,
                   accuracy_meters INT, visit_id INT, is_office INT, timestamp INT)");
        $db->exec("INSERT INTO time_clock_entries (user_id, clock_in, clock_out) VALUES (9, " . (self::NOW - 3600) . ", NULL)");

        $svc = new TrackingIngestService($db);
        $r = $svc->ingest(9, TrackingIngestService::normalizePoints(['points' => [
            ['t' => (self::NOW - 60) * 1000, 'lat' => 49.20, 'lng' => -123.10, 'acc' => 8],
            ['t' => (self::NOW - 10) * 1000, 'lat' => 49.30, 'lng' => -123.20, 'acc' => 5, 'mock' => true],
        ]], self::NOW), self::NOW, static fn (int $v): bool => true, static fn (float $a, float $b): bool => false);

        $this->assertSame(2, $r['stored']);
        $this->assertSame(49.20, $r['newest']['lat'], 'auto-arrival reads the newest REAL fix');

        $onlyMock = $svc->ingest(9, TrackingIngestService::normalizePoints(['points' => [
            ['t' => (self::NOW - 5) * 1000, 'lat' => 49.30, 'lng' => -123.20, 'mock' => true],
        ]], self::NOW), self::NOW, static fn (int $v): bool => true, static fn (float $a, float $b): bool => false);
        $this->assertNull($onlyMock['newest']);
    }
}
