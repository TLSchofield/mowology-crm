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
}
