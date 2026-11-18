<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SiteMinutesModelTest extends TestCase
{
    public function test_units_per_service(): void
    {
        $lot = ['lawn_sqft' => 4500, 'edge_ft' => 180, 'hedge_ft' => 0];
        $this->assertSame(4.5, SiteMinutesModel::units('mow', $lot));
        $this->assertSame(1.8, SiteMinutesModel::units('edge', $lot));
        $this->assertNull(SiteMinutesModel::units('hedge', $lot));
    }

    public function test_predict(): void
    {
        $m = ['fixed_minutes' => 8, 'per_unit_minutes' => 5, 'per_obstacle_minutes' => 2];
        $this->assertSame(34.5, SiteMinutesModel::predict($m, 'mow', ['lawn_sqft' => 4500, 'obstacles' => 2]));
        $this->assertNull(SiteMinutesModel::predict($m, 'mow', ['lawn_sqft' => 0]));
        $this->assertNull(SiteMinutesModel::predict(null, 'mow', ['lawn_sqft' => 4500]));
    }

    public function test_choose_needs_the_threshold_before_trusting_a_fit(): void
    {
        $fit = ['per_unit_minutes' => 6, 'n' => 14];
        $manual = ['fixed_minutes' => 10, 'per_unit_minutes' => 4];
        $implied = ['per_unit_minutes' => 9, 'n' => 0];
        $this->assertSame('manual', SiteMinutesModel::choose($fit, $manual, $implied)['source']);
        $fit['n'] = 15;
        $this->assertSame('fit', SiteMinutesModel::choose($fit, $manual, $implied)['source']);
        $this->assertSame('implied', SiteMinutesModel::choose(null, ['fixed_minutes' => 0, 'per_unit_minutes' => 0], $implied)['source']);
        $this->assertNull(SiteMinutesModel::choose(null, null, null));
    }

    public function test_implied_minutes_from_the_current_price_rule(): void
    {
        // A $55 rule at 35% margin pays $35.75 of cost; at $60/h that's 35.75 min over 4.5 units.
        $m = SiteMinutesModel::impliedFromPrice(55, 4.5, 0, 60, 0.35);
        $this->assertEqualsWithDelta(7.944, $m['per_unit_minutes'], 0.001);
        $this->assertNull(SiteMinutesModel::impliedFromPrice(0, 4.5, 0, 60, 0.35));
    }

    public function test_visit_minutes_drop_drive_and_purchase_time(): void
    {
        $m = SiteMinutesModel::visitMinutes([
            ['user_id' => 1, 'start_time' => '2026-06-01 09:00:00', 'end_time' => '2026-06-01 09:40:00', 'time_type' => 'job'],
            ['user_id' => 1, 'start_time' => '2026-06-01 08:40:00', 'end_time' => '2026-06-01 09:00:00', 'time_type' => 'drive'],
            ['user_id' => 1, 'start_time' => '2026-06-01 10:00:00', 'end_time' => '2026-06-01 10:30:00', 'time_type' => 'purchase'],
        ]);
        $this->assertSame(40.0, $m['person_minutes']);
    }

    public function test_two_people_count_twice_for_cost_once_for_the_clock(): void
    {
        $m = SiteMinutesModel::visitMinutes([
            ['user_id' => 1, 'start_time' => '2026-06-01 09:00:00', 'end_time' => '2026-06-01 09:30:00'],
            ['user_id' => 2, 'start_time' => '2026-06-01 09:00:00', 'end_time' => '2026-06-01 09:30:00'],
        ]);
        $this->assertSame(60.0, $m['person_minutes']);
        $this->assertSame(30.0, $m['wall_minutes']);
        $this->assertSame(2, $m['crew']);
    }

    public function test_one_persons_overlapping_entries_are_merged(): void
    {
        // An auto-started timer and a manual one running over the same half hour.
        $m = SiteMinutesModel::visitMinutes([
            ['user_id' => 1, 'start_time' => '2026-06-01 09:00:00', 'end_time' => '2026-06-01 09:30:00'],
            ['user_id' => 1, 'start_time' => '2026-06-01 09:10:00', 'duration_minutes' => 30],
        ]);
        $this->assertSame(40.0, $m['person_minutes']);
        $this->assertSame(1, $m['crew']);
    }

    public function test_cluster_apportioned_visits_are_not_measurements(): void
    {
        $this->assertNull(SiteMinutesModel::visitMinutes([
            ['user_id' => 1, 'start_time' => '2026-06-01 00:00:00', 'duration_minutes' => 22, 'cluster_session_id' => 9, 'time_source' => 'cluster_apportioned'],
        ]));
        $this->assertNull(SiteMinutesModel::visitMinutes([]));
    }

    public function test_usable_rejects_forgotten_timers_and_unmeasured_lots(): void
    {
        $this->assertTrue(SiteMinutesModel::usable(4.0, ['person_minutes' => 30.0]));
        $this->assertFalse(SiteMinutesModel::usable(4.0, ['person_minutes' => 2.0]));
        $this->assertFalse(SiteMinutesModel::usable(4.0, ['person_minutes' => 700.0]));
        $this->assertFalse(SiteMinutesModel::usable(null, ['person_minutes' => 30.0]));
    }

    public function test_fit_recovers_known_coefficients(): void
    {
        $rows = [];
        for ($i = 1; $i <= 20; $i++) {
            $units = 1 + $i * 0.5;
            $rows[] = ['units' => $units, 'obstacles' => 0, 'minutes' => 10 + 6 * $units + (($i % 3) - 1) * 0.5];
        }
        $m = SiteMinutesModel::fit($rows);
        $this->assertSame(20, $m['n']);
        $this->assertEqualsWithDelta(10, $m['fixed_minutes'], 0.6);
        $this->assertEqualsWithDelta(6, $m['per_unit_minutes'], 0.1);
        $this->assertLessThan(3, $m['mae_pct']);
    }

    public function test_fit_uses_obstacles_when_they_vary(): void
    {
        $rows = [];
        for ($i = 0; $i < 18; $i++) {
            $units = 2 + ($i % 6);
            $obs = intdiv($i, 6);
            $rows[] = ['units' => $units, 'obstacles' => $obs, 'minutes' => 5 + 4 * $units + 3 * $obs];
        }
        $m = SiteMinutesModel::fit($rows);
        $this->assertEqualsWithDelta(3, $m['per_obstacle_minutes'], 0.01);
        $this->assertEqualsWithDelta(4, $m['per_unit_minutes'], 0.01);
    }

    public function test_few_visits_fall_back_to_the_median_rate(): void
    {
        $m = SiteMinutesModel::fit([
            ['units' => 2, 'minutes' => 20],
            ['units' => 4, 'minutes' => 32],
        ]);
        $this->assertSame(0.0, $m['fixed_minutes']);
        $this->assertSame(9.0, $m['per_unit_minutes']); // median of 10 and 8
    }
}
