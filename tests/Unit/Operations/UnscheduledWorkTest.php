<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../fixtures/UnscheduledDayFixture.php';

/**
 * Otto: work with nothing scheduled. The Larch St morning (2026-10-05) end to end on an in-memory
 * database, and the rules on their own: truck alone flags, crew corroborates, scheduled / home /
 * dump never flag, a stop between neighbours goes to the scheduled one.
 */
class UnscheduledWorkTest extends TestCase
{
    private const D = UnscheduledDayFixture::DATE;

    private static function t(string $hm): int
    {
        return strtotime(self::D . ' ' . $hm);
    }

    private static function stop(array $at, string $a, string $b, array $label): array
    {
        return ['type' => 'stop', 'start' => self::t($a), 'end' => self::t($b), 'minutes' => (self::t($b) - self::t($a)) / 60,
                'lat' => $at[0], 'lng' => $at[1], 'label' => $label];
    }

    private static function props(): array
    {
        return [
            ['id' => 441, 'latitude' => UnscheduledDayFixture::LARCH[0], 'longitude' => UnscheduledDayFixture::LARCH[1]],
            ['id' => 300, 'latitude' => UnscheduledDayFixture::OAK[0], 'longitude' => UnscheduledDayFixture::OAK[1]],
        ];
    }

    private static function fixes(array $at, string $a, string $b, int $every = 120, string $src = 'phone'): array
    {
        $out = [];
        for ($t = self::t($a); $t <= self::t($b); $t += $every) $out[] = ['lat' => $at[0], 'lng' => $at[1], 't' => $t, 'src' => $src];
        return $out;
    }

    // ── End to end: the real morning ────────────────────────────────────────

    public function test_larch_street_morning_is_flagged_from_the_truck_and_backed_by_nigels_phone(): void
    {
        $svc = new UnscheduledWorkService(UnscheduledDayFixture::pdo(), '2026-10-07');
        $r = $svc->review(self::D, self::D, false);
        $this->assertSame(1, $r['flagged']);
        $c = $r['days'][0]['candidates'][0];
        $this->assertTrue($c['flag']);
        $this->assertSame(441, $c['property_id']);
        $this->assertSame('08:10–11:40', $c['window']);
        $this->assertSame(210, $c['minutes']);
        $this->assertSame('truck+crew', $c['basis']);
        $this->assertSame('high', $c['confidence']);
        $this->assertSame('Marc Nelitz', $c['client']);
        $this->assertSame('Crew at 2448 Larch St, Mon 8:10–11:40 (3 h 30 min), nothing scheduled.', UnscheduledWorkRules::text($c, '2448 Larch St'));
        $this->assertStringContainsString('Truck 8:10–11:40', $c['evidence']);
        $this->assertStringContainsString('Nigel 8:14–11:32', $c['evidence']);
        // The hand-made invoice and the plan to add the visit to are offered.
        $this->assertSame('INV-2026-0438', $c['invoices'][0]['number']);
        $this->assertSame(82, $c['plans'][0]['id']);

        // Oak St had its visit: seen, not flagged. The dump, the yard and Nigel's home never appear.
        $others = array_slice($r['days'][0]['candidates'], 1);
        $this->assertSame([300], array_column($others, 'property_id'));
        $this->assertSame('scheduled', $others[0]['ignored']);

        $src = $r['days'][0]['sources'];
        $this->assertGreaterThan(200, $src['truck_pings']);
        $this->assertArrayHasKey(7, $src['phone_fixes']);
        $this->assertSame(2, $src['clock_fixes']);
    }

    public function test_otto_item_carries_times_plans_and_the_invoice_and_goes_once_the_visit_exists(): void
    {
        $db = UnscheduledDayFixture::pdo();
        $svc = new class($db, '2026-10-07') extends UnscheduledWorkService {
            public function cacheReady(): bool { return true; }
        };
        // No cache table in the fixture: items() computes the day (fill) and its store() fails quietly.
        $log = ini_set('error_log', '/dev/null');
        $items = array_values(array_filter($svc->items(true), fn($i) => $i['for_date'] === self::D));
        $this->assertCount(1, $items);
        $it = $items[0];
        $this->assertSame('otto:unsched:441:' . self::D, $it['key']);
        $this->assertSame('unscheduled', $it['kind']);
        $this->assertSame(['08:10', '11:40', 210, 7], [$it['propose']['start'], $it['propose']['end'], $it['propose']['minutes'], $it['propose']['crew_id']]);
        $this->assertSame(438, $it['propose']['invoices'][0]['id']);

        // The visit gets added → nothing to say about that day any more.
        $db->exec("INSERT INTO job_visits (id, plan_id, scheduled_date, status, completed_at) VALUES (2, 82, '" . self::D . "', 'completed', '" . self::D . " 11:40:00')");
        $this->assertSame([], array_values(array_filter($svc->items(true), fn($i) => $i['for_date'] === self::D)));
        ini_set('error_log', (string)$log);
    }

    public function test_not_work_twice_stops_the_flag(): void
    {
        $db = UnscheduledDayFixture::pdo();
        $db->exec("INSERT INTO otto_lessons VALUES ('unscheduled', 'property:441', '{\"not_work\":2}')");
        $r = (new UnscheduledWorkService($db, '2026-10-07'))->review(self::D, self::D, false);
        $this->assertSame(0, $r['flagged']);
        $this->assertSame('not_work', $r['days'][0]['candidates'][0]['ignored']);
    }

    // ── Rules ───────────────────────────────────────────────────────────────

    public function test_a_truck_stop_alone_flags_and_crew_fills_in_when_the_truck_was_elsewhere(): void
    {
        $truck = UnscheduledWorkRules::truckDwells([self::stop(UnscheduledDayFixture::LARCH, '08:10', '11:40', ['type' => 'property', 'id' => 441, 'name' => '2448 Larch'])], self::props(), [], []);
        $c = UnscheduledWorkRules::candidates(self::D, $truck, [], []);
        $this->assertCount(1, $c);
        $this->assertTrue($c[0]['flag']);
        $this->assertSame(['truck', 'medium', 210], [$c[0]['basis'], $c[0]['confidence'], $c[0]['minutes']]);
        $this->assertStringStartsWith('Truck at 2448 Larch', UnscheduledWorkRules::text($c[0], '2448 Larch'));

        // No truck: two people's phones for 50 min flag on their own (medium); one phone alone is low.
        $crew = [
            7 => UnscheduledWorkRules::crewDwells(self::fixes(UnscheduledDayFixture::OAK, '09:00', '09:50'), self::props(), [], []),
            9 => UnscheduledWorkRules::crewDwells(self::fixes(UnscheduledDayFixture::OAK, '09:02', '09:48'), self::props(), [], []),
        ];
        $c = UnscheduledWorkRules::candidates(self::D, [], $crew, []);
        $this->assertSame([300, true, 'crew', 'medium', 50], [$c[0]['property_id'], $c[0]['flag'], $c[0]['basis'], $c[0]['confidence'], $c[0]['minutes']]);
        $this->assertSame('low', UnscheduledWorkRules::candidates(self::D, [], [7 => $crew[7]], [])[0]['confidence']);
    }

    public function test_short_stays_are_kept_as_evidence_but_not_flagged(): void
    {
        $truck = UnscheduledWorkRules::truckDwells([self::stop(UnscheduledDayFixture::LARCH, '08:10', '08:30', ['type' => 'property', 'id' => 441, 'name' => ''])], self::props(), [], []);
        $crew = [7 => UnscheduledWorkRules::crewDwells(self::fixes(UnscheduledDayFixture::LARCH, '08:12', '08:28'), self::props(), [], [])];
        $c = UnscheduledWorkRules::candidates(self::D, $truck, $crew, []);
        $this->assertFalse($c[0]['flag']);
        $this->assertSame('too_short', $c[0]['ignored']);
        // A 4-minute truck stop isn't even evidence.
        $this->assertSame([], UnscheduledWorkRules::truckDwells([self::stop(UnscheduledDayFixture::LARCH, '08:10', '08:14', ['type' => 'property', 'id' => 441, 'name' => ''])], self::props(), [], []));
    }

    public function test_scheduled_dump_and_home_never_flag(): void
    {
        $segs = [
            self::stop(UnscheduledDayFixture::DUMP, '12:04', '12:50', ['type' => 'place', 'id' => 2, 'name' => 'Dump', 'kind' => 'dump']),
            self::stop(UnscheduledDayFixture::OAK, '13:00', '14:00', ['type' => 'property', 'id' => 300, 'name' => ''])
        ];
        $props = array_merge(self::props(), [['id' => 500, 'latitude' => UnscheduledDayFixture::HOME[0], 'longitude' => UnscheduledDayFixture::HOME[1]]]);
        $home = [['lat' => UnscheduledDayFixture::HOME[0], 'lng' => UnscheduledDayFixture::HOME[1], 'radius_m' => 150, 'name' => 'home (Nigel)', 'kind' => 'home']];
        $truck = UnscheduledWorkRules::truckDwells($segs, $props, [], $home);
        $this->assertSame([[300]], array_column($truck, 'props'), 'the dump stop is a place, not client work');
        $crew = [7 => UnscheduledWorkRules::crewDwells(self::fixes(UnscheduledDayFixture::HOME, '06:00', '07:30'), $props, [], $home)];
        $this->assertSame([], $crew[7], 'an hour at home is not a dwell');
        $c = UnscheduledWorkRules::candidates(self::D, $truck, $crew, [300 => true]);
        $this->assertCount(1, $c);
        $this->assertSame('scheduled', $c[0]['ignored']);
    }

    public function test_a_truck_parked_between_neighbours_goes_to_the_one_that_was_scheduled(): void
    {
        // Two pins 60 m apart; the truck stopped 30 m from 441 and 40 m from 442. 442 had the visit.
        $p441 = [49.26370, -123.16200];
        $p442 = [49.26370, -123.16117];
        $props = [['id' => 441, 'latitude' => $p441[0], 'longitude' => $p441[1]], ['id' => 442, 'latitude' => $p442[0], 'longitude' => $p442[1]]];
        $truck = UnscheduledWorkRules::truckDwells([self::stop([49.26370, -123.16159], '09:00', '10:30', ['type' => 'property', 'id' => 441, 'name' => ''])], $props, [], []);
        $this->assertSame([441, 442], $truck[0]['props']);
        $c = UnscheduledWorkRules::candidates(self::D, $truck, [], [442 => true]);
        $this->assertSame(442, $c[0]['property_id']);
        $this->assertFalse($c[0]['flag']);
        // Nobody scheduled → the nearest, flagged.
        $this->assertSame([441, true], [UnscheduledWorkRules::candidates(self::D, $truck, [], [])[0]['property_id'], UnscheduledWorkRules::candidates(self::D, $truck, [], [])[0]['flag']]);
    }

    public function test_a_geofence_polygon_catches_a_big_lot_beyond_the_pin_radius(): void
    {
        $pin = [49.2600, -123.1500];
        $far = [49.2600, -123.1478]; // ~160 m east of the pin
        $ring = [[49.2595, -123.1505], [49.2595, -123.1470], [49.2605, -123.1470], [49.2605, -123.1505]];
        $fence = ['property_id' => 77, 'ring' => $ring, 'lat_min' => 49.2595, 'lat_max' => 49.2605, 'lng_min' => -123.1505, 'lng_max' => -123.1470];
        $props = [['id' => 77, 'latitude' => $pin[0], 'longitude' => $pin[1]]];
        $this->assertSame([], UnscheduledWorkRules::propertiesAt($far[0], $far[1], $props, []));
        $this->assertSame([77], UnscheduledWorkRules::propertiesAt($far[0], $far[1], $props, [$fence]));
        $this->assertSame($ring, UnscheduledWorkRules::ring(json_encode($ring)));
        $this->assertSame([], UnscheduledWorkRules::ring('nonsense'));
        // A pin at 0,0 (failed geocode) never matches.
        $this->assertSame([], UnscheduledWorkRules::propertiesAt(0.0001, 0.0001, [['id' => 22, 'latitude' => 0, 'longitude' => 0]], []));
    }

    public function test_crew_dwell_splits_on_a_long_gap_and_counts_its_sources(): void
    {
        $fx = array_merge(
            self::fixes(UnscheduledDayFixture::LARCH, '08:00', '08:40'),
            [['lat' => UnscheduledDayFixture::LARCH[0], 'lng' => UnscheduledDayFixture::LARCH[1], 't' => self::t('08:41'), 'src' => 'clock']],
            self::fixes(UnscheduledDayFixture::LARCH, '10:00', '10:30')
        );
        $d = UnscheduledWorkRules::crewDwells($fx, self::props(), [], []);
        $this->assertCount(2, $d);
        $this->assertSame(['phone' => 21, 'clock' => 1], $d[0]['sources']);
        $this->assertSame(41.0, $d[0]['minutes']);
    }

    // ── Extra work beyond the scheduled visit (prod 2026-10-05: Tim timed the 30-min lawn cut) ──

    private function larchWithLawnCut(string $status = 'completed', ?int $planned = 30): PDO
    {
        $db = UnscheduledDayFixture::pdo();
        $db->exec("INSERT INTO job_plans VALUES (83, 441, 'PLN-2026-0070', 'Weekly lawn', 'Lawn Cut', 1, " . ($planned === null ? 'NULL' : $planned) . ", 'active')");
        $db->exec("INSERT INTO job_visits (id, plan_id, scheduled_date, status, completed_at, visit_number) VALUES (5, 83, '" . self::D . "', '{$status}', NULL, 'PLN-2026-0070-V021')");
        // Tim's timer ran on the lawn cut the whole morning.
        $db->exec("INSERT INTO job_time_entries (user_id, visit_id, start_time, end_time, status) VALUES (7, 5, '" . self::D . " 08:20:00', '" . self::D . " 11:30:00', 'completed')");
        return $db;
    }

    public function test_larch_with_a_timed_30_min_lawn_cut_is_flagged_as_extra_work(): void
    {
        $svc = new UnscheduledWorkService($this->larchWithLawnCut(), '2026-10-07');
        $r = $svc->review(self::D, self::D, false);
        $c = $r['days'][0]['candidates'][0];
        $this->assertSame([441, true, 'extra', 30, 'plan', 180], [$c['property_id'], $c['flag'], $c['kind'], $c['planned_min'], $c['planned_basis'], $c['extra_min']]);
        // Which visit made it "scheduled" — the dry run shows it.
        $v = $c['scheduled_visits'][0];
        $this->assertSame([5, 'PLN-2026-0070', 'Lawn Cut', 'completed', 30, 190], [$v['visit_id'], $v['plan_number'], $v['service_type'], $v['status'], $v['planned_min'], $v['timer_min']]);
        $this->assertSame('Crew at 2448 Larch St, Mon 8:10–11:40 (3 h 30 min) — the lawn cut is planned at 30 min → 3 h extra.',
            UnscheduledWorkRules::text($c, '2448 Larch St'));

        $svc2 = new class($this->larchWithLawnCut(), '2026-10-07') extends UnscheduledWorkService {
            public function cacheReady(): bool { return true; }
        };
        $log = ini_set('error_log', '/dev/null');
        $items = array_values(array_filter($svc2->items(true), fn($i) => $i['for_date'] === self::D));
        ini_set('error_log', (string)$log);
        $this->assertSame(['otto:extra:441:' . self::D], array_column($items, 'key'));
        $p = $items[0]['propose'];
        $this->assertSame('extra_work', $items[0]['kind']);
        $this->assertSame(['08:40', '11:40', 180, true, 'lawn cut'], [$p['start'], $p['end'], $p['minutes'], $p['extra'], $p['scheduled_label']], 'the extra visit starts after the planned lawn cut');
        $this->assertSame('INV-2026-0438', $p['invoices'][0]['number']);
    }

    public function test_extra_work_needs_an_hour_more_and_twice_the_plan(): void
    {
        $this->assertTrue(UnscheduledWorkRules::isExtra(225, 30));
        $this->assertFalse(UnscheduledWorkRules::isExtra(80, 45), 'Oak St: 35 min over');
        $this->assertFalse(UnscheduledWorkRules::isExtra(200, 120), '80 min over but not 2x');
        $this->assertTrue(UnscheduledWorkRules::isExtra(120, 60));
        $this->assertFalse(UnscheduledWorkRules::isExtra(119, 60));
        // Plan length first; the timer only when no plan length is set.
        $this->assertSame(['minutes' => 75, 'basis' => 'plan'], UnscheduledWorkRules::reference([['planned_min' => 30, 'timer_min' => 190], ['planned_min' => 45, 'timer_min' => null]]));
        $this->assertSame(['minutes' => 190, 'basis' => 'timer'], UnscheduledWorkRules::reference([['planned_min' => null, 'timer_min' => 190]]));
        $this->assertNull(UnscheduledWorkRules::reference([['planned_min' => null, 'timer_min' => null]]));

        // No plan length: the 190-min timer is the reference → 210 min is not extra.
        $r = (new UnscheduledWorkService($this->larchWithLawnCut('completed', null), '2026-10-07'))->review(self::D, self::D, false);
        $this->assertSame('scheduled', $r['days'][0]['candidates'][0]['ignored']);
    }

    public function test_a_skipped_visit_does_not_make_the_property_scheduled(): void
    {
        $r = (new UnscheduledWorkService($this->larchWithLawnCut('skipped'), '2026-10-07'))->review(self::D, self::D, false);
        $c = $r['days'][0]['candidates'][0];
        $this->assertSame([441, true, 'unscheduled', []], [$c['property_id'], $c['flag'], $c['kind'], $c['scheduled_visits']]);
    }

    public function test_small_helpers(): void
    {
        $this->assertSame('08:05', UnscheduledWorkService::hm('8:05'));
        $this->assertSame('11:40', UnscheduledWorkService::hm('11:40:00'));
        $this->assertNull(UnscheduledWorkService::hm('25:00'));
        $this->assertSame(210, UnscheduledWorkService::minutes('08:10', '11:40'));
        $this->assertSame('3 h 30 min', UnscheduledWorkRules::hours(210));
    }
}
