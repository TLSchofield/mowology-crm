<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The City of Vancouver lot map, by address (CityParcelService). Fixtures are the City's real answers
 * (2026-10-19) for the four Vancouver Management buildings, trimmed to their lots plus a few lots with
 * the same civic number on other streets. Three of the four have no lot in the province's map.
 */
class CityParcelServiceTest extends TestCase
{
    /** Geocoder parcel points (BC Address Geocoder, 2026-10-19). */
    private const PINS = [
        '1535' => ['1535 West 16th Avenue', [49.2573302, -123.1397768]],
        '2025' => ['2025 West 42nd Avenue', [49.2338203, -123.1527026]],
        '825'  => ['825 Southwest Marine Drive', [49.206992, -123.1254372]],
        '8860' => ['8860 Montcalm Street', [49.2050082, -123.1358502]],
    ];

    private static function fixture(string $number): string
    {
        return (string)file_get_contents(__DIR__ . '/../../fixtures/city-parcels/' . $number . '.json');
    }

    private static function service(?string $body, array &$urls = []): CityParcelService
    {
        return new CityParcelService(function (string $url) use ($body, &$urls): ?string { $urls[] = $url; return $body; });
    }

    public function test_addresses_are_read_the_way_the_city_writes_them(): void
    {
        $this->assertSame(['number' => '1535', 'core' => 'W 16', 'type' => 'AV'], CityParcelService::parseAddress('1535 West 16th Avenue'));
        $this->assertSame(['number' => '1535', 'core' => 'W 16', 'type' => 'AV'], CityParcelService::parseAddress('#305 - 1535 W. 16th Ave., Vancouver'));
        $this->assertSame(['number' => '1535', 'core' => 'W 16', 'type' => 'AV'], CityParcelService::parseAddress('305-1535 W 16th Ave'));
        $this->assertSame(['number' => '825', 'core' => 'SW MARINE', 'type' => 'DRIVE'], CityParcelService::parseAddress('825 Southwest Marine Drive'));
        $this->assertSame(['number' => '8860', 'core' => 'MONTCALM', 'type' => 'ST'], CityParcelService::parseAddress('8860 Montcalm St'));
        $this->assertSame(['W 16', 'AV'], CityParcelService::streetKey('W 16TH AV'));
        $this->assertSame(['SW MARINE', 'DRIVE'], CityParcelService::streetKey('SW MARINE DRIVE'));
        $this->assertNull(CityParcelService::parseAddress('Main Street'), 'no civic number, no lookup');
    }

    public function test_only_the_city_itself_is_covered(): void
    {
        $this->assertSame('vancouver', CityParcelService::sourceFor(['city' => ' Vancouver ']));
        $this->assertNull(CityParcelService::sourceFor(['city' => 'North Vancouver']));
        $this->assertNull(CityParcelService::sourceFor(['city' => 'West Vancouver']));
        $this->assertNull(CityParcelService::sourceFor(['city' => 'Burnaby']));
        $this->assertNull(self::service('{}')->lotFor(['address' => '1 A St', 'city' => 'Burnaby'], [49.25, -123.0]), 'not covered: the province map as before');
    }

    public function test_all_four_vancouver_management_buildings_get_their_lot(): void
    {
        foreach (self::PINS as $num => [$address, $pin]) {
            $num = (string)$num;   // PHP turns numeric array keys into ints
            $urls = [];
            $lot = self::service(self::fixture($num), $urls)->lotFor(['address' => $address, 'city' => 'Vancouver'], $pin);
            $this->assertTrue($lot['ok'], $address . ': ' . ($lot['reason'] ?? ''));
            $this->assertSame('vancouver', $lot['source']);
            $this->assertStringContainsString('Open Government Licence – Vancouver', $lot['attribution']);
            $this->assertGreaterThan(250, $lot['area_sqm'], $address);
            $this->assertLessThan(4000, $lot['area_sqm'], $address);
            $this->assertStringContainsString('civic_number%3D%22' . $num . '%22', $urls[0]);
        }
    }

    public function test_a_lot_far_from_the_pin_is_never_drawn(): void
    {
        $lot = self::service(self::fixture('1535'))->lotFor(['address' => '1535 West 16th Avenue', 'city' => 'Vancouver'], [49.2600, -123.1397768]);
        $this->assertFalse($lot['ok']);
        $this->assertSame('pin_far', $lot['reason'], '300 m away: the address or the pin is wrong');
    }

    public function test_no_match_or_no_answer_falls_back(): void
    {
        $this->assertSame('no_address_match', self::service(self::fixture('1535'))->lotFor(['address' => '1535 East 16th Avenue', 'city' => 'Vancouver'], self::PINS['1535'][1])['reason']);
        $this->assertSame('unavailable', self::service(null)->lotFor(['address' => '1535 West 16th Avenue', 'city' => 'Vancouver'], self::PINS['1535'][1])['reason']);
    }

    public function test_two_streets_with_the_same_name_are_told_apart_by_type_or_left_alone(): void
    {
        $sq = fn(float $lat) => ['type' => 'Polygon', 'coordinates' => [[[-123.1, $lat], [-123.0997, $lat], [-123.0997, $lat + 0.0002], [-123.1, $lat + 0.0002], [-123.1, $lat]]]];
        $results = [
            ['civic_number' => '100', 'streetname' => 'OAK ST', 'site_id' => 'a', 'geom' => ['geometry' => $sq(49.25)]],
            ['civic_number' => '100', 'streetname' => 'OAK AV', 'site_id' => 'b', 'geom' => ['geometry' => $sq(49.26)]],
        ];
        $st = CityParcelService::matchStreet($results, CityParcelService::parseAddress('100 Oak Street'));
        $this->assertCount(1, $st);
        $this->assertSame('a', $st[0]['site']);
        $this->assertSame([], CityParcelService::matchStreet($results, ['number' => '100', 'core' => 'OAK', 'type' => '']), 'no type given: ambiguous, nothing');
    }

    public function test_lots_that_do_not_touch_use_the_one_under_the_pin_or_nothing(): void
    {
        $a = [[49.25, -123.1], [49.25, -123.0997], [49.2502, -123.0997], [49.2502, -123.1]];
        $b = [[49.2506, -123.1], [49.2506, -123.0997], [49.2508, -123.0997], [49.2508, -123.1]];   // ~45 m north, not touching
        $lots = [['ring' => $a, 'site' => 'a'], ['ring' => $b, 'site' => 'b']];
        $this->assertSame('a', CityParcelService::joinLots($lots, [49.2501, -123.09985])['pid']);
        $this->assertSame('ambiguous', CityParcelService::joinLots($lots, [49.2504, -123.09985])['reason']);
        $touching = [['ring' => $a, 'site' => 'a'], ['ring' => [[49.2502, -123.1], [49.2502, -123.0997], [49.2504, -123.0997], [49.2504, -123.1]], 'site' => 'c']];
        $joined = CityParcelService::joinLots($touching, [49.2501, -123.09985]);
        $this->assertTrue($joined['ok']);
        $this->assertSame('a+c', $joined['pid'], 'one building on two lots: one outline');
    }
}
