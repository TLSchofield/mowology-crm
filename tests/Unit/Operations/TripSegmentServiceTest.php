<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../fixtures/TripTrailFixture.php';

/**
 * Otto's trip splitter on a trail shaped like 2026-10-07 (Oakridge → dump → Lawnboy → Oakridge).
 */
class TripSegmentServiceTest extends TestCase
{
    private function stops(array $segments): array
    {
        return array_values(array_filter($segments, fn($s) => $s['type'] === 'stop'));
    }

    public function test_the_day_splits_into_six_stops_with_drives_between(): void
    {
        $seg = TripSegmentService::segments(TripTrailFixture::pings(), TripTrailFixture::properties(), TripTrailFixture::places());
        $stops = $this->stops($seg);
        $this->assertCount(6, $stops);
        $this->assertSame(['place', 'property', 'place', 'unnamed', 'unnamed', 'property'], array_map(fn($s) => $s['label']['type'], $stops));
        $this->assertSame('Yard', $stops[0]['label']['name']);
        $this->assertSame('Oakridge strata', $stops[1]['label']['name']);
        $this->assertSame('Vancouver Transfer Station', $stops[2]['label']['name']);
        $this->assertSame('09:48', date('H:i', $stops[2]['start']));
        $this->assertSame(12.0, $stops[2]['minutes']);
        $this->assertSame(4.0, $stops[3]['minutes']);
        // stop, drive, stop, drive …
        $this->assertSame('drive', $seg[1]['type']);
        $this->assertSame(26.0, $seg[1]['minutes']);
        $this->assertGreaterThan(2.0, $seg[1]['km']);
    }

    public function test_a_moving_truck_is_never_a_stop_and_a_three_minute_halt_is_too_short(): void
    {
        $t = strtotime('2026-10-07 09:00');
        $pings = [];
        for ($i = 0; $i < 6; $i++) $pings[] = ['lat' => 49.2 + $i * 0.004, 'lng' => -123.1, 'speed_kph' => 40.0, 't' => $t + $i * 120];
        $this->assertSame([], TripSegmentService::detectStops($pings));
        $short = [['lat' => 49.2, 'lng' => -123.1, 'speed_kph' => 0.0, 't' => $t], ['lat' => 49.2, 'lng' => -123.1, 'speed_kph' => 1.0, 't' => $t + 180]];
        $this->assertSame([], TripSegmentService::detectStops($short));
    }

    public function test_one_run_from_oakridge_back_to_oakridge_with_shared_legs(): void
    {
        $seg = TripSegmentService::segments(TripTrailFixture::pings(), TripTrailFixture::properties(), TripTrailFixture::places());
        $runs = TripSegmentService::runs($seg, '2026-10-07');
        $this->assertCount(1, $runs);
        $r = $runs[0];
        $this->assertSame('2026-10-07@09:36', $r['trip_key']);
        $this->assertSame(41, $r['from']['property_id']);
        $this->assertSame(41, $r['to']['property_id']);
        $this->assertSame('11:00', date('H:i', $r['returned_at']));
        $this->assertSame(84.0, $r['minutes']);
        // The 4-min hold-up is folded into the drive; Lawnboy (unnamed, 20 min) is its own end.
        $this->assertCount(2, $r['legs']);
        [$dump, $lawn] = $r['legs'];
        $this->assertSame(1, $dump['place_id']);
        $this->assertSame('dump', $dump['kind']);
        $this->assertSame(12.0, $dump['onsite_min']);
        $this->assertSame(24.0, $dump['drive_min']);   // 12 out + half of the 24 to Lawnboy
        $this->assertNull($lawn['place_id']);
        $this->assertSame('unnamed', $lawn['kind']);
        $this->assertSame(20.0, $lawn['onsite_min']);
        $this->assertSame(28.0, $lawn['drive_min']);   // half of 24 + 16 back
        $this->assertSame(52.0, $r['drive_min']);
        $this->assertEqualsWithDelta($r['km'], $dump['km'] + $lawn['km'], 0.02);
    }

    public function test_naming_lawnboy_makes_it_a_supplier_leg_and_it_leaves_the_unnamed_list(): void
    {
        $before = TripSegmentService::segments(TripTrailFixture::pings(), TripTrailFixture::properties(), TripTrailFixture::places());
        $un = TripSegmentService::unnamedStops($before);
        $this->assertCount(1, $un, 'the 4-minute hold-up is too short to ask about');
        $this->assertSame('10:24', date('H:i', $un[0]['start']));

        $after = TripSegmentService::segments(TripTrailFixture::pings(), TripTrailFixture::properties(), TripTrailFixture::places(true));
        $this->assertSame([], TripSegmentService::unnamedStops($after));
        $legs = TripSegmentService::runs($after, '2026-10-07')[0]['legs'];
        $this->assertSame([1, 3], array_column($legs, 'place_id'));
        $this->assertSame(['dump', 'supplier'], array_column($legs, 'kind'));
    }

    public function test_a_run_still_away_is_open_and_its_last_stop_carries_the_drive_so_far(): void
    {
        $seg = TripSegmentService::segments(TripTrailFixture::pings('2026-10-07', '10:14'), TripTrailFixture::properties(), TripTrailFixture::places());
        $runs = TripSegmentService::runs($seg, '2026-10-07');
        $this->assertCount(1, $runs);
        $this->assertNull($runs[0]['returned_at']);
        $this->assertNull($runs[0]['to']);
        $this->assertSame(['Vancouver Transfer Station'], array_column($runs[0]['legs'], 'name'));
        $this->assertSame(26.0, $runs[0]['legs'][0]['drive_min']);   // 12 out + 10:00 → 10:14 so far
    }

    public function test_a_day_with_no_dump_or_supplier_has_no_runs(): void
    {
        $seg = TripSegmentService::segments(TripTrailFixture::pings('2026-10-07', '09:40'), TripTrailFixture::properties(), TripTrailFixture::places());
        $this->assertSame([], TripSegmentService::runs($seg));
    }

    public function test_the_pings_from_the_database_are_read_as_local_time(): void
    {
        $p = TripSegmentService::normalisePings([
            ['lat' => '49.2', 'lng' => '-123.1', 'speed_kph' => null, 'recorded_at' => '2026-10-07 09:50:00'],
            ['lat' => '49.2', 'lng' => '-123.1', 'speed_kph' => '0.0', 'recorded_at' => '2026-10-07 09:48:00'],
            ['lat' => null, 'lng' => '-123.1', 'speed_kph' => '0', 'recorded_at' => '2026-10-07 09:49:00'],
        ]);
        $this->assertCount(2, $p);
        $this->assertSame('09:48', date('H:i', $p[0]['t']));
        $this->assertNull($p[1]['speed_kph']);
    }

    // ── Who was in the truck ────────────────────────────────────────────────

    public function test_one_person_clocked_in_is_the_driver(): void
    {
        $v = TripSegmentService::crewVerdict([7], [], ['lat' => 49.2305, 'lng' => -123.1229], [['lat' => 49.2073, 'lng' => -123.1130]]);
        $this->assertTrue($v['one_man']);
        $this->assertSame(7, $v['driver_id']);
        $this->assertSame('clock', $v['basis']);
    }

    public function test_two_clocked_in_the_phone_that_stayed_at_oakridge_is_not_in_the_truck(): void
    {
        $home = ['lat' => TripTrailFixture::OAKRIDGE[0], 'lng' => TripTrailFixture::OAKRIDGE[1]];
        $away = [['lat' => TripTrailFixture::DUMP[0], 'lng' => TripTrailFixture::DUMP[1]]];
        $phones = [
            1 => [['lat' => 49.2306, 'lng' => -123.1228, 't' => 1], ['lat' => 49.2304, 'lng' => -123.1230, 't' => 2]],   // Tim on site
            7 => [['lat' => 49.2072, 'lng' => -123.1131, 't' => 1]],                                                    // Nigel at the dump
        ];
        $v = TripSegmentService::crewVerdict([1, 7], $phones, $home, $away);
        $this->assertTrue($v['one_man']);
        $this->assertSame(7, $v['driver_id']);
        $this->assertSame([1], $v['stayed']);
        $this->assertSame('phone', $v['basis']);

        // Nigel's phone silent: Tim stayed, so the one we can't see drove.
        $v = TripSegmentService::crewVerdict([1, 7], [1 => $phones[1]], $home, $away);
        $this->assertTrue($v['one_man']);
        $this->assertSame(7, $v['driver_id']);
    }

    public function test_no_phones_defaults_to_one_man_from_a_client_property_and_the_toggle_wins(): void
    {
        $home = ['lat' => 49.2305, 'lng' => -123.1229];
        $v = TripSegmentService::crewVerdict([1, 7], [], $home, [['lat' => 49.2073, 'lng' => -123.1130]]);
        $this->assertTrue($v['one_man']);
        $this->assertNull($v['driver_id']);
        $this->assertSame('default', $v['basis']);

        $v = TripSegmentService::crewVerdict([1, 7], [], $home, [['lat' => 49.2073, 'lng' => -123.1130]], 0);
        $this->assertFalse($v['one_man']);
        $this->assertSame('manual', $v['basis']);
        $this->assertSame(2, $v['crew_count']);
    }

    public function test_both_phones_at_the_dump_is_a_two_man_run(): void
    {
        $at = [['lat' => 49.2073, 'lng' => -123.1130, 't' => 1]];
        $v = TripSegmentService::crewVerdict([1, 7], [1 => $at, 7 => $at], ['lat' => 49.2305, 'lng' => -123.1229], [['lat' => 49.2073, 'lng' => -123.1130]]);
        $this->assertFalse($v['one_man']);
        $this->assertSame(2, $v['crew_count']);
    }

    public function test_nobody_clocked_in_is_unknown(): void
    {
        $v = TripSegmentService::crewVerdict([], [], ['lat' => 49.2305, 'lng' => -123.1229], []);
        $this->assertNull($v['one_man']);
        $this->assertSame('none', $v['basis']);
    }
}
