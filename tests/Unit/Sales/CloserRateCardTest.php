<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class CloserRateCardTest extends TestCase
{
    private const FACTORS = [
        ['factor_name' => 'Owner/Manager', 'factor_type' => 'labor', 'rate' => 45, 'rate_with_burden' => 52, 'unit' => 'per hour'],
        ['factor_name' => 'Foreman', 'factor_type' => 'labor', 'rate' => 35, 'rate_with_burden' => 40.5, 'unit' => 'per hour'],
        ['factor_name' => 'Walk-Behind Mower', 'factor_type' => 'equipment', 'rate' => 8, 'unit' => 'per hour'],
        ['factor_name' => 'Riding Mower', 'factor_type' => 'equipment', 'rate' => 15, 'unit' => 'per hour'],
        ['factor_name' => 'String Trimmer', 'factor_type' => 'equipment', 'rate' => 3, 'unit' => 'per hour'],
        ['factor_name' => 'Leaf Blower', 'factor_type' => 'equipment', 'rate' => 2.5, 'unit' => 'per hour'],
        ['factor_name' => 'Pickup Truck', 'factor_type' => 'equipment', 'rate' => 12, 'unit' => 'per hour'],
        ['factor_name' => 'Dump Truck', 'factor_type' => 'equipment', 'rate' => 25, 'unit' => 'per hour'],
        ['factor_name' => 'Cedar Mulch', 'factor_type' => 'material', 'rate' => 35, 'unit' => 'per yard'],
    ];

    public function test_the_closest_labour_factor_is_the_owner(): void
    {
        $this->assertSame('Owner/Manager', CloserRateCard::closestLabourFactor(self::FACTORS)['factor_name']);
        $noOwner = array_slice(self::FACTORS, 1);
        $this->assertSame('Foreman', CloserRateCard::closestLabourFactor($noOwner)['factor_name']);
    }

    public function test_resolve_uses_the_existing_target_margin_and_floor_below_it(): void
    {
        $c = CloserRateCard::resolve(
            ['closer_hourly_cost' => ['value' => '52', 'description' => 'Closer: seeded from cost factor "Owner/Manager"']],
            ['profit_margin' => '35'], self::FACTORS, []);
        $this->assertSame(52.0, $c['hourly_cost']);
        $this->assertSame(0.35, $c['target_margin']);
        $this->assertSame(25.0, $c['margin_floor_pct']);
        $this->assertSame(40.0, $c['kmh']);
        $this->assertSame(15, $c['calibration_min']);
        $this->assertNull($c['depot']);
        $this->assertNotEmpty(array_filter($c['flags'], function ($f) { return strpos($f, 'seed') !== false; }));
    }

    public function test_tims_floor_and_depot_win(): void
    {
        $c = CloserRateCard::resolve([
            'closer_hourly_cost' => ['value' => '70', 'description' => 'Set by Tim'],
            'closer_margin_floor_pct' => ['value' => '28'],
            'closer_depot_lat' => ['value' => '49.70'], 'closer_depot_lng' => ['value' => '-123.15'],
            'closer_season_plan' => ['value' => '{"visits":30}'],
        ], [], [], []);
        $this->assertSame(0.28, $c['margin_floor']);
        $this->assertSame(['lat' => 49.70, 'lng' => -123.15], $c['depot']);
        $this->assertSame(30, $c['season']['visits']);
        $this->assertSame(2, $c['season']['cleanup']);
        $this->assertSame([], $c['flags']);
    }

    public function test_unset_hourly_cost_is_flagged(): void
    {
        $c = CloserRateCard::resolve([], [], [], []);
        $this->assertNull($c['hourly_cost']);
        $this->assertContains('Hourly cost is not set', $c['flags']);
        $this->assertSame(35.0, $c['target_margin_pct']);
    }

    public function test_suggested_loaded_cost(): void
    {
        $s = CloserRateCard::suggestedLoaded(self::FACTORS,
            [['amount' => 1200, 'frequency' => 'monthly'], ['amount' => 2400, 'frequency' => 'annual']],
            ['estimated_billable_hours' => 140]);
        // 52 labour + (8 + 3 + 2.5 + 12) kit + (1200 + 200)/140 overhead = 52 + 25.5 + 10 = 87.5
        $this->assertSame(87.5, $s['total']);
        $this->assertNotContains('Dump Truck', $s['kit_names']);
        $this->assertNotContains('Riding Mower', $s['kit_names']);
    }

    public function test_the_rate_card_has_no_write_path(): void
    {
        $writers = array_filter(get_class_methods(CloserRateCard::class), function ($m) {
            return (bool)preg_match('/^(save|set|update|write|store|insert|delete)/i', $m);
        });
        $this->assertSame([], array_values($writers));
    }
}
