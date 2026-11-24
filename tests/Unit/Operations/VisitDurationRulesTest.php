<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class VisitDurationRulesTest extends TestCase
{
    private function timer(int $crew, int $person = 0, int $people = 1, ?string $excluded = null): array
    {
        return ['crew_min' => $crew, 'person_min' => $person ?: $crew, 'people' => $people,
            'excluded' => $excluded, 'truck_only' => false];
    }

    public function test_onsite_minutes_handles_empty_and_one_fix(): void
    {
        $this->assertSame(['minutes' => 0, 'points' => 0, 'sessions' => 0, 'first' => null, 'last' => null],
            VisitDurationRules::onsiteMinutes([]));
        $this->assertSame(['minutes' => 0, 'points' => 1, 'sessions' => 0, 'first' => 100, 'last' => 100],
            VisitDurationRules::onsiteMinutes([100]));
    }

    public function test_onsite_minutes_sorts_and_counts_one_session(): void
    {
        $m = VisitDurationRules::onsiteMinutes([1600, 1000, 1300, 1300]);
        $this->assertSame(10, $m['minutes']);
        $this->assertSame(4, $m['points']);
        $this->assertSame(1, $m['sessions']);
        $this->assertSame(1000, $m['first']);
        $this->assertSame(1600, $m['last']);
    }

    public function test_onsite_minutes_removes_long_gap_and_ignores_one_fix_session(): void
    {
        $m = VisitDurationRules::onsiteMinutes([0, 300, 1800, 2100, 4000]);
        $this->assertSame(10, $m['minutes']);
        $this->assertSame(2, $m['sessions']);
        $this->assertSame(5, $m['points']);
    }

    public function test_choose_covers_timer_and_gps_relationships(): void
    {
        $agree = VisitDurationRules::choose($this->timer(31, 62, 2), ['minutes' => 30, 'points' => 5, 'source' => 'phone']);
        $this->assertSame(['timer_agrees', 31, 62], [$agree['basis'], $agree['crew_min'], $agree['person_min']]);

        $ranOn = VisitDurationRules::choose($this->timer(60, 120, 2), ['minutes' => 30, 'points' => 8, 'source' => 'phone']);
        $this->assertSame(['timer_ran_on', 30, 60], [$ranOn['basis'], $ranOn['crew_min'], $ranOn['person_min']]);

        $short = VisitDurationRules::choose($this->timer(10, 20, 2), ['minutes' => 30, 'points' => 8, 'source' => 'phone']);
        $this->assertSame(['timer_short', 30, 60], [$short['basis'], $short['crew_min'], $short['person_min']]);

        $timer = VisitDurationRules::choose($this->timer(20), ['minutes' => 3, 'points' => 8, 'source' => 'phone']);
        $this->assertSame('timer_only', $timer['basis']);
        $none = VisitDurationRules::choose($this->timer(0, 0, 0, 'untimed'), null);
        $this->assertSame(['none', 'untimed', null], [$none['basis'], $none['excluded'], $none['gps_min']]);
    }

    public function test_choose_uses_gps_for_unusable_timer_and_keeps_truck_source(): void
    {
        $gps = ['minutes' => 25, 'points' => 6, 'source' => 'truck'];
        foreach (['untimed', 'auto_stopped', 'under_5'] as $why) {
            $r = VisitDurationRules::choose($this->timer($why === 'under_5' ? 4 : 30, 60, 2, $why), $gps);
            $this->assertSame(['gps_only', 25, 25, 2, null, 25, 'truck'],
                [$r['basis'], $r['crew_min'], $r['person_min'], $r['people'], $r['excluded'], $r['gps_min'], $r['gps_source']]);
        }
        $underFive = VisitDurationRules::choose($this->timer(4, 4, 1), $gps);
        $this->assertSame('gps_only', $underFive['basis']);
    }

    public function test_summarise_still_drops_gps_based_over_three_x(): void
    {
        $rows = [];
        foreach ([10, 10, 10, 40] as $i => $minutes) {
            $rows[] = ['visit_id' => $i + 1, 'date' => '2026-11-0' . ($i + 1), 'crew_min' => $minutes,
                'person_min' => $minutes, 'people' => 1, 'excluded' => null, 'basis' => 'gps_only', 'gps_source' => 'phone'];
        }
        $s = VisitDurationRules::summarise($rows, 20);
        $this->assertCount(3, $s['used']);
        $this->assertSame('over_3x', $s['dropped'][0]['why']);
    }

    public function test_detail_explains_each_gps_basis_and_truck_count(): void
    {
        $used = [
            ['basis' => 'timer_ran_on', 'gps_source' => 'phone'],
            ['basis' => 'timer_ran_on', 'gps_source' => 'truck'],
            ['basis' => 'gps_only', 'gps_source' => 'truck'],
            ['basis' => 'timer_short', 'gps_source' => 'phone'],
        ];
        $detail = VisitDurationRules::detail(['crew_size' => null, 'median_person' => 20, 'spread' => null,
            'stable' => true, 'dropped' => [], 'used' => $used]);
        $this->assertStringContainsString('2 from GPS (timer ran on)', $detail);
        $this->assertStringContainsString('1 GPS only (no timer)', $detail);
        $this->assertStringContainsString('1 from GPS (timer short)', $detail);
        $this->assertStringContainsString("2 from the truck's GPS", $detail);
    }

    public function test_inside_border_keeps_the_lot_not_the_neighbours(): void
    {
        $lot = [[49.0, -123.0], [49.0, -122.9999], [49.0001, -122.9999], [49.0001, -123.0]];
        $fixes = [
            ['latitude' => 49.00005, 'longitude' => -122.99995, 'timestamp' => 't1'],   // on the lot
            ['latitude' => 49.0003, 'longitude' => -122.99995, 'timestamp' => 't2'],    // ~30 m away, next door
        ];
        $this->assertSame(['t1'], array_column(VisitDurationRules::insideBorder($fixes, $lot), 'timestamp'));
        $this->assertCount(2, VisitDurationRules::insideBorder($fixes, null), 'no border: the proof radius decides');
    }

    public function test_busiest_day_keeps_one_day_of_fixes(): void
    {
        $fixes = [['timestamp' => '2026-10-01 08:30:00'], ['timestamp' => '2026-10-02 09:00:00'],
                  ['timestamp' => '2026-10-02 09:30:00'], ['timestamp' => '2026-10-01 08:40:00'], ['timestamp' => '2026-10-02 09:40:00']];
        $this->assertSame(['2026-10-02 09:00:00', '2026-10-02 09:30:00', '2026-10-02 09:40:00'],
            array_column(VisitDurationRules::busiestDay($fixes), 'timestamp'));
        $this->assertSame([], VisitDurationRules::busiestDay([]));
    }
}
