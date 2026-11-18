<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class DriveMinutesAddedTest extends TestCase
{
    // A street in Squamish, BC, running roughly north; ~0.0009° lat ≈ 100 m.
    private const DEPOT = ['lat' => 49.7016, 'lng' => -123.1558];

    private function day(array $pts, string $date = '2026-10-07'): array
    {
        return ['date' => $date, 'crew_id' => 3, 'stops' => array_map(function ($p) {
            return ['lat' => $p[0], 'lng' => $p[1], 'property_id' => 0];
        }, $pts)];
    }

    public function test_haversine(): void
    {
        $this->assertEqualsWithDelta(1.112, DriveMinutesAdded::haversineKm(49.0, -123.0, 49.01, -123.0), 0.002);
    }

    public function test_minutes_at_the_might_e_speed(): void
    {
        // 10 km straight × 1.4 = 14 km at 40 km/h = 21 min.
        $this->assertEqualsWithDelta(21.0, DriveMinutesAdded::minutes(10), 0.001);
    }

    public function test_next_door_to_three_clients_adds_almost_nothing(): void
    {
        $street = [[49.7300, -123.1500], [49.7303, -123.1500], [49.7306, -123.1500]];
        $r = DriveMinutesAdded::added(49.73045, -123.1500, [$this->day($street)], self::DEPOT);
        $this->assertSame('route', $r['basis']);
        $this->assertLessThan(0.5, $r['minutes']);
        $this->assertSame(3, $r['neighbours']);
        $this->assertSame('2026-10-07', $r['date']);
    }

    public function test_a_lone_lot_across_town_adds_the_detour(): void
    {
        $street = [[49.7300, -123.1500], [49.7303, -123.1500]];
        $far = DriveMinutesAdded::added(49.7800, -123.1200, [$this->day($street)], self::DEPOT);
        $near = DriveMinutesAdded::added(49.7302, -123.1501, [$this->day($street)], self::DEPOT);
        $this->assertGreaterThan(10, $far['minutes']);
        $this->assertGreaterThan($near['minutes'] * 20, $far['minutes']);
    }

    public function test_the_cheapest_day_wins(): void
    {
        $farDay = $this->day([[49.8000, -123.1000]], '2026-10-06');
        $nearDay = $this->day([[49.7300, -123.1500], [49.7306, -123.1500]], '2026-10-09');
        $r = DriveMinutesAdded::added(49.7303, -123.1500, [$farDay, $nearDay], self::DEPOT);
        $this->assertSame('2026-10-09', $r['date']);
    }

    public function test_no_route_day_is_a_round_trip_from_the_depot(): void
    {
        $r = DriveMinutesAdded::added(49.7106, -123.1558, [], self::DEPOT);
        $this->assertSame('round_trip', $r['basis']);
        // 1 km away → 2 km × 1.4 at 40 km/h ≈ 4.2 min.
        $this->assertEqualsWithDelta(4.2, $r['minutes'], 0.1);
    }

    public function test_no_route_and_no_depot_is_unknown(): void
    {
        $this->assertNull(DriveMinutesAdded::added(49.7, -123.1, [], null));
    }

    public function test_without_a_depot_the_new_stop_can_go_first_or_last(): void
    {
        $street = [[49.7300, -123.1500], [49.7310, -123.1500]];
        $r = DriveMinutesAdded::added(49.7319, -123.1500, [$this->day($street)], null);
        // ~100 m past the last stop, not a detour back through the middle.
        $this->assertLessThan(0.4, $r['minutes']);
    }
}
