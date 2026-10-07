<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class CloserPricingTest extends TestCase
{
    private const CARD = ['hourly_cost' => 60.0, 'target_margin' => 0.35, 'margin_floor' => 0.25];

    public function test_formula_by_hand(): void
    {
        // (30 site + 10 drive) min × $60/h = $40, + $5 materials + $3 disposal = $48 cost.
        // 48 / 0.65 = 73.85 → $74.
        $r = CloserPricing::price(['site_minutes' => 30, 'drive_minutes' => 10, 'materials' => 5, 'disposal' => 3, 'minimum' => 0], self::CARD);
        $this->assertSame(48.0, $r['cost']);
        $this->assertSame(74.0, $r['price']);
        $this->assertFalse($r['minimum_applied']);
        $this->assertSame(['site' => 30.0, 'drive' => 10.0, 'materials' => 5.0, 'disposal' => 3.0], $r['parts']);
        $this->assertGreaterThanOrEqual(35.0, $r['margin_pct']);
        $this->assertFalse($r['below_floor']);
    }

    public function test_minimum_clamps(): void
    {
        $r = CloserPricing::price(['site_minutes' => 10, 'minimum' => 45], self::CARD);
        $this->assertSame(45.0, $r['price']);
        $this->assertTrue($r['minimum_applied']);
    }

    public function test_drive_minutes_are_paid_for_every_person_in_the_truck(): void
    {
        $one = CloserPricing::price(['site_minutes' => 60, 'drive_minutes' => 30, 'crew' => 1], self::CARD);
        $two = CloserPricing::price(['site_minutes' => 60, 'drive_minutes' => 30, 'crew' => 2], self::CARD);
        $this->assertSame(30.0, $one['parts']['drive']);
        $this->assertSame(60.0, $two['parts']['drive']);
    }

    public function test_zero_margin_prices_at_cost(): void
    {
        $r = CloserPricing::price(['site_minutes' => 60], ['hourly_cost' => 50, 'target_margin' => 0]);
        $this->assertSame(50.0, $r['price']);
    }

    public function test_margin_round_trips_to_target(): void
    {
        $r = CloserPricing::price(['site_minutes' => 47, 'drive_minutes' => 6, 'materials' => 2.4], self::CARD);
        // Rounding up to the dollar can only add margin, never lose it.
        $this->assertGreaterThanOrEqual(35.0, $r['margin_pct']);
        $this->assertLessThan(37.0, $r['margin_pct']);
    }

    public function test_refuses_impossible_margins_and_missing_cost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CloserPricing::price(['site_minutes' => 30], ['hourly_cost' => 60, 'target_margin' => 0.99]);
    }

    public function test_refuses_unset_hourly_cost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CloserPricing::price(['site_minutes' => 30], ['hourly_cost' => 0, 'target_margin' => 0.35]);
    }

    public function test_below_floor(): void
    {
        $this->assertTrue(CloserPricing::belowFloor(40, 35, 0.25));   // 12.5%
        $this->assertFalse(CloserPricing::belowFloor(50, 35, 0.25));  // 30%
        $this->assertTrue(CloserPricing::belowFloor(0, 35, 0.25));
        $this->assertSame(30.0, CloserPricing::marginAt(50, 35));
    }

    public function test_service_keys_from_free_text(): void
    {
        $this->assertSame('mow', CloserPricing::serviceKey('Weekly Lawn Mowing'));
        $this->assertSame('edge', CloserPricing::serviceKey('Edging & Trimming'));
        $this->assertSame('hedge', CloserPricing::serviceKey('Hedge Trimming'));
        $this->assertSame('cleanup', CloserPricing::serviceKey('Fall Clean-up'));
        $this->assertSame('aeration', CloserPricing::serviceKey('Core aeration (spring/fall)'));
        $this->assertSame('overseed', CloserPricing::serviceKey('Overseeding'));
        $this->assertSame('beds', CloserPricing::serviceKey('Garden Bed Weeding'));
        $this->assertNull(CloserPricing::serviceKey('Snow removal'));
    }

    public function test_seasonal_work_is_spread_over_the_season(): void
    {
        $fold = CloserPricing::foldVisit([
            'mow'     => ['minutes' => 30, 'materials' => 0],
            'cleanup' => ['minutes' => 140, 'disposal' => 28],
        ], ['visits' => 28, 'cleanup' => 2]);
        $this->assertSame(40.0, $fold['site_minutes']); // 30 + 140×2/28
        $this->assertSame(2.0, $fold['disposal']);       // 28×2/28
        $this->assertSame([], $fold['missing']);
    }

    public function test_tiers_ascend_and_use_the_defaults(): void
    {
        $per = [];
        foreach (array_keys(CloserPricing::SERVICES) as $k) $per[$k] = ['minutes' => 20, 'materials' => 1, 'disposal' => 0];
        $per['mow']['minutes'] = 35;
        $t = CloserPricing::tiers([], $per, ['drive_minutes' => 4, 'minimum' => 40], CloserPricing::DEFAULT_SEASON, self::CARD);
        $this->assertSame(['Basic', 'Standard', 'Full care'], array_column($t, 'label'));
        $this->assertLessThan($t[1]['price'], $t[0]['price']);
        $this->assertLessThan($t[2]['price'], $t[1]['price']);
    }

    public function test_a_tier_without_mowing_minutes_has_no_price(): void
    {
        $t = CloserPricing::tiers([], ['mow' => ['minutes' => null]], ['drive_minutes' => 0], CloserPricing::DEFAULT_SEASON, self::CARD);
        $this->assertNull($t[0]['price']);
        $this->assertContains('mow', $t[0]['missing']);
    }

    public function test_bundle_tiers_override_the_defaults(): void
    {
        $per = ['mow' => ['minutes' => 30], 'hedge' => ['minutes' => 56]];
        $t = CloserPricing::tiers(['good' => ['mow', 'hedge']], $per, [], ['visits' => 28, 'hedge' => 2], self::CARD);
        $this->assertSame(['mow', 'hedge'], $t[0]['services']);
        $this->assertSame(34.0, $t[0]['labour_minutes']); // 30 + 56×2/28
    }
}
