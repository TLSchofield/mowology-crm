<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Tracking/Services/RouteProofService.php';

/** A client sees only the fixes ON the property — never the fuel run, lunch or a mock fix. */
final class RouteProofServiceTest extends TestCase
{
    private const SITE = ['lat' => 49.2500, 'lng' => -123.1000, 'polygon' => null];

    private static function fix(float $lat, float $lng, int $mock = 0): array
    {
        return ['latitude' => $lat, 'longitude' => $lng, 'timestamp' => '2026-10-12 09:00:00', 'is_mock' => $mock];
    }

    public function testFixesAwayFromThePropertyAreDropped(): void
    {
        $kept = RouteProofService::onSite([
            self::fix(49.2501, -123.1001),        // ~13 m — on site
            self::fix(49.2600, -123.1000),        // ~1.1 km — the gas station
        ], self::SITE, 150);
        $this->assertCount(1, $kept);
        $this->assertSame(49.2501, $kept[0]['latitude']);
        $this->assertArrayNotHasKey('is_mock', $kept[0]);
    }

    public function testMockFixesAreNeverProof(): void
    {
        $this->assertSame([], RouteProofService::onSite([self::fix(49.2500, -123.1000, 1)], self::SITE, 150));
    }

    public function testDrawnBorderCountsBeyondTheRadius(): void
    {
        $site = ['lat' => 49.25, 'lng' => -123.10, 'polygon' => [[49.249, -123.104], [49.249, -123.096], [49.254, -123.096], [49.254, -123.104]]];
        $kept = RouteProofService::onSite([self::fix(49.2535, -123.1000)], $site, 150);   // ~390 m north, inside the border
        $this->assertCount(1, $kept);
    }

    public function testNoPinShowsNothing(): void
    {
        $this->assertSame([], RouteProofService::onSite([self::fix(49.25, -123.10)], ['lat' => null, 'lng' => null], 150));
    }

    public function testProofOfWorkPointsKeepTheirOwnShape(): void
    {
        $pts = [
            ['lat' => 49.2600, 'lng' => -123.1000, 'accuracy_m' => 5, 'ts' => '09:00'],   // the drive in
            ['lat' => 49.2501, 'lng' => -123.1001, 'accuracy_m' => 5, 'ts' => '09:05'],   // on site
            ['lat' => 49.2500, 'lng' => -123.1000, 'accuracy_m' => 8, 'ts' => '09:10'],   // on site
            ['lat' => 49.2400, 'lng' => -123.1000, 'accuracy_m' => 5, 'ts' => '09:20'],   // on to the next client
        ];
        $kept = RouteProofService::onSiteLatLng($pts, self::SITE, 150);
        $this->assertSame([$pts[1], $pts[2]], $kept);
        $this->assertSame([], RouteProofService::onSiteLatLng($pts, [], 150), 'no property pin → no map');
    }

    // ── Time window (2026-11-19: back-to-back salt stops drew the whole day's trail) ──

    public function testWindowIsTheTimerPaddedAMinute(): void
    {
        $w = RouteProofService::windows([['start' => '2026-11-19 14:19:12', 'end' => '2026-11-19 14:21:31']], null, null, 0);
        $this->assertSame([[strtotime('2026-11-19 14:18:12'), strtotime('2026-11-19 14:22:31')]], $w);
    }

    public function testOverlappingEntriesMergeAndOpenEntryRunsToCompletion(): void
    {
        $w = RouteProofService::windows([
            ['start' => '2026-11-19 12:00:00', 'end' => '2026-11-19 12:05:00'],
            ['start' => '2026-11-19 12:03:00', 'end' => null],
        ], null, '2026-11-19 12:10:00', 0, 0);
        $this->assertSame([[strtotime('2026-11-19 12:00:00'), strtotime('2026-11-19 12:10:00')]], $w);
    }

    public function testNoEntriesFallsBackToVisitTimesOnlyWhenSane(): void
    {
        $this->assertCount(1, RouteProofService::windows([], '2026-11-19 12:00:00', '2026-11-19 12:10:00', 0));
        $this->assertSame([], RouteProofService::windows([], '2026-11-19 06:00:00', '2026-11-19 18:00:00', 0));
        $this->assertSame([], RouteProofService::windows([], null, null, 0));
    }

    public function testFixesOutsideTheWindowAreDroppedAndNoWindowHidesEverything(): void
    {
        $rows = [['timestamp' => '2026-11-19 12:00:00'], ['timestamp' => '2026-11-19 12:40:00']];
        $w = RouteProofService::windows([['start' => '2026-11-19 11:59:00', 'end' => '2026-11-19 12:01:00']], null, null, 0);
        $this->assertSame([$rows[0]], RouteProofService::inWindows($rows, $w));
        $this->assertSame([], RouteProofService::inWindows($rows, []));
    }
}
