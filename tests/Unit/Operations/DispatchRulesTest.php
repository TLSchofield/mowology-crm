<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Otto the Dispatcher's pure rules: holidays, bylaw hours, the West End, the Might-E's
 * day, maintenance and fading packs.
 */
class DispatchRulesTest extends TestCase
{
    private static function rule(array $r): array
    {
        return $r + ['id' => 1, 'municipality' => 'Vancouver', 'area' => null, 'kind' => 'hours', 'equipment_class' => 'power_equipment',
                     'power_source' => 'any', 'day_type' => 'weekday', 'allowed_start' => '07:00:00', 'allowed_end' => '22:00:00',
                     'status' => 'unverified', 'note' => ''];
    }

    private static function vancouver(): array
    {
        return [
            self::rule(['id' => 1]),
            self::rule(['id' => 2, 'day_type' => 'saturday']),
            self::rule(['id' => 3, 'day_type' => 'sunday_holiday', 'allowed_start' => '10:00:00']),
            self::rule(['id' => 4, 'equipment_class' => 'leaf_blower', 'allowed_start' => '08:00:00', 'allowed_end' => '18:00:00']),
            self::rule(['id' => 5, 'equipment_class' => 'leaf_blower', 'day_type' => 'saturday', 'allowed_start' => '09:00:00', 'allowed_end' => '17:00:00']),
            self::rule(['id' => 6, 'equipment_class' => 'leaf_blower', 'day_type' => 'sunday_holiday', 'kind' => 'ban', 'allowed_start' => null, 'allowed_end' => null]),
            self::rule(['id' => 7, 'area' => 'West End', 'equipment_class' => 'leaf_blower', 'power_source' => 'gas', 'day_type' => 'any', 'kind' => 'ban', 'allowed_start' => null, 'allowed_end' => null]),
            self::rule(['id' => 8, 'kind' => 'note', 'equipment_class' => 'all', 'day_type' => 'any', 'allowed_start' => null, 'allowed_end' => null]),
        ];
    }

    // ── Holidays ────────────────────────────────────────────────────────────

    public function test_bc_statutory_holidays_2026(): void
    {
        $h = DispatchRules::bcHolidays(2026);
        $this->assertCount(11, $h);
        $this->assertSame('Family Day', $h['2026-02-16']);
        $this->assertSame('Good Friday', $h['2026-04-03']);
        $this->assertSame('Victoria Day', $h['2026-05-18']);
        $this->assertSame('British Columbia Day', $h['2026-08-03']);
        $this->assertSame('Labour Day', $h['2026-09-07']);
        $this->assertSame('Thanksgiving', $h['2026-10-12']);
        $this->assertArrayHasKey('2026-09-30', $h);
    }

    public function test_easter(): void
    {
        $this->assertSame('2024-03-31', DispatchRules::easterSunday(2024));
        $this->assertSame('2025-04-20', DispatchRules::easterSunday(2025));
        $this->assertSame('2026-04-05', DispatchRules::easterSunday(2026));
    }

    public function test_day_types(): void
    {
        $h = DispatchRules::bcHolidays(2026);
        $this->assertSame('weekday', DispatchRules::dayType('2026-10-06', $h));
        $this->assertSame('saturday', DispatchRules::dayType('2026-10-10', $h));
        $this->assertSame('sunday_holiday', DispatchRules::dayType('2026-10-11', $h));
        $this->assertSame('sunday_holiday', DispatchRules::dayType('2026-10-12', $h), 'Thanksgiving Monday');
    }

    // ── Bylaw hours ─────────────────────────────────────────────────────────

    public function test_inside_the_hours_is_fine_and_the_edges_count(): void
    {
        $this->assertNull(DispatchRules::breach(self::vancouver(), 'weekday', ['power_equipment'], [], '07:00', '22:00'));
        $this->assertSame('early', DispatchRules::breach(self::vancouver(), 'weekday', ['power_equipment'], [], '06:59', '08:00')['problem']);
        $this->assertSame('late', DispatchRules::breach(self::vancouver(), 'weekday', ['power_equipment'], [], '21:30', '22:30')['problem']);
    }

    public function test_blower_hours_are_narrower_than_mowing(): void
    {
        $b = DispatchRules::breach(self::vancouver(), 'saturday', ['power_equipment', 'leaf_blower'], [], '08:00', '09:30');
        $this->assertSame(5, $b['rule']['id']);
        $this->assertNull(DispatchRules::breach(self::vancouver(), 'saturday', ['power_equipment'], [], '08:00', '09:30'));
    }

    public function test_no_blowers_on_sundays_even_without_a_time(): void
    {
        $b = DispatchRules::breach(self::vancouver(), 'sunday_holiday', ['power_equipment', 'leaf_blower'], [], null, null);
        $this->assertSame('ban', $b['problem']);
        $this->assertNull(DispatchRules::breach(self::vancouver(), 'sunday_holiday', ['power_equipment'], [], null, null));
    }

    public function test_area_rules_only_apply_inside_the_area_and_notes_never_apply(): void
    {
        $this->assertNull(DispatchRules::breach(self::vancouver(), 'weekday', ['power_equipment', 'leaf_blower'], [], '09:00', '10:00'));
        $this->assertSame(7, DispatchRules::breach(self::vancouver(), 'weekday', ['power_equipment', 'leaf_blower'], ['West End'], '09:00', '10:00')['rule']['id']);
    }

    public function test_breach_words_say_when_the_rule_is_unconfirmed(): void
    {
        $b = DispatchRules::breach(self::vancouver(), 'saturday', ['power_equipment', 'leaf_blower'], [], '08:00', '09:30');
        $t = DispatchRules::breachText($b, 'Leaf cleanup at 12 Oak St', 'Saturday', '08:00');
        $this->assertSame('Leaf cleanup at 12 Oak St Saturday at 8:00 am: Vancouver allows leaf blowers 9:00 am–5:00 pm. (Rule not yet confirmed.)', $t);
        $ban = DispatchRules::breach(self::vancouver(), 'sunday_holiday', ['leaf_blower'], [], null, null);
        $this->assertStringContainsString('allows no leaf blowers on Sundays or holidays.', DispatchRules::breachText($ban, 'Cleanup', 'Sunday', null));
    }

    public function test_service_classes_map_to_rule_classes(): void
    {
        $this->assertSame(['power_equipment'], DispatchRules::ruleClasses([]));
        $this->assertSame(['power_equipment', 'leaf_blower'], DispatchRules::ruleClasses(['mower', 'blower']));
    }

    public function test_end_time(): void
    {
        $this->assertSame('09:30', DispatchRules::endTime('08:00', 90));
    }

    // ── Places ──────────────────────────────────────────────────────────────

    public function test_west_end_by_postal_code(): void
    {
        $areas = [['name' => 'West End', 'fsa_prefixes' => 'V6E, v6g']];
        $this->assertSame('V6G', DispatchRules::fsa('v6g 1a1'));
        $this->assertSame(['West End'], DispatchRules::areasFor('V6G', $areas));
        $this->assertSame([], DispatchRules::areasFor('V5K', $areas));
        $this->assertSame([], DispatchRules::areasFor(DispatchRules::fsa(''), $areas));
    }

    public function test_city_names_compare_loosely(): void
    {
        $this->assertSame('vancouver', DispatchRules::normalizeCity(' City of Vancouver '));
        $this->assertSame('north vancouver', DispatchRules::normalizeCity('District of North  Vancouver'));
    }

    // ── The Might-E ─────────────────────────────────────────────────────────

    public function test_planned_km_follows_the_points_times_the_road_factor(): void
    {
        $km = DispatchRules::plannedKm([[49.2827, -123.1207], [49.2488, -123.0016], [49.2827, -123.1207]], 1.0);
        $this->assertEqualsWithDelta(18.6, $km, 0.6);
        $this->assertEqualsWithDelta($km * 1.35, DispatchRules::plannedKm([[49.2827, -123.1207], [49.2488, -123.0016], [49.2827, -123.1207]], 1.35), 0.2);
        $this->assertSame(0.0, DispatchRules::plannedKm([[49.0, -123.0]], 1.35));
    }

    public function test_road_factor_needs_five_odometer_days(): void
    {
        $this->assertSame(1.35, DispatchRules::roadFactor([[30, 20], [33, 22], [28, 20], [40, 25]]));
        $this->assertSame(1.5, DispatchRules::roadFactor([[30, 20], [33, 22], [28, 20], [40, 25], [45, 30]]));
        $this->assertSame(2.5, DispatchRules::roadFactor(array_fill(0, 5, [100, 10])));
    }

    public function test_day_cap_keeps_a_reserve(): void
    {
        $this->assertSame(72.0, DispatchRules::dayCapKm(90, null));
        $this->assertSame(96.0, DispatchRules::dayCapKm(120, 20));
        $this->assertSame(81.0, DispatchRules::dayCapKm(90, 10));
    }

    // ── Maintenance and packs ───────────────────────────────────────────────

    public function test_maintenance_due_by_hours_or_days(): void
    {
        $iv = [['task' => 'blade sharpening', 'every_hours' => 25, 'every_days' => null], ['task' => 'oil', 'every_hours' => 50, 'every_days' => 365]];
        $due = DispatchRules::due($iv, ['blade sharpening' => [26.0, 10], 'oil' => [12.0, 400]]);
        $this->assertSame(['blade sharpening', 'oil'], array_column($due, 'task'));
        $this->assertSame('26 h since the last one (every 25 h)', $due[0]['why']);
        $this->assertSame([], DispatchRules::due($iv, ['blade sharpening' => [3.0, 3], 'oil' => [3.0, 3]]));
    }

    public function test_item_intervals_override_their_type(): void
    {
        $all = [['equipment_class' => 'mower', 'equipment_id' => null, 'task' => 'Blades', 'every_hours' => 25],
                ['equipment_class' => null, 'equipment_id' => 7, 'task' => 'blades', 'every_hours' => 15],
                ['equipment_class' => 'trimmer', 'equipment_id' => null, 'task' => 'Line', 'every_hours' => 5]];
        $mine = DispatchRules::intervalsFor(['id' => 7, 'equipment_class' => 'mower'], $all);
        $this->assertCount(1, $mine);
        $this->assertSame(15, $mine[0]['every_hours']);
        $this->assertSame(25, DispatchRules::intervalsFor(['id' => 8, 'equipment_class' => 'mower'], $all)[0]['every_hours']);
    }

    public function test_a_pack_fades_under_seventy_percent_over_three_runs(): void
    {
        $this->assertFalse(DispatchRules::packFading([40, 41], 60)['fading'], 'needs three runs');
        $this->assertTrue(DispatchRules::packFading([38, 40, 55, 60], 60)['fading']);
        $this->assertFalse(DispatchRules::packFading([45, 42, 50], 60)['fading'], 'median 45/60 = 75% is not fading');
        $this->assertFalse(DispatchRules::packFading([10, 10, 10], null)['fading'], 'no runtime-when-new, no call');
    }
}
