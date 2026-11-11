<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class CloserServiceTest extends TestCase
{
    public function test_lot_prefers_the_measured_total_over_the_legacy_size(): void
    {
        $lot = CloserService::lotFromRow(['id' => 7, 'total_lawn_sqft' => 4200, 'lawn_size_sqft' => 9000, 'latitude' => 49.7, 'longitude' => -123.1]);
        $this->assertSame(4200.0, $lot['lawn_sqft']);
        $this->assertTrue($lot['measured']);
        $this->assertTrue($lot['has_coords']);
        $lot = CloserService::lotFromRow(['total_lawn_sqft' => 0, 'lawn_size_sqft' => 3000]);
        $this->assertSame(3000.0, $lot['lawn_sqft']);
        $this->assertFalse($lot['has_coords']);
    }

    public function test_realised_margin_uses_timer_minutes_not_the_snapshot(): void
    {
        $rows = [
            ['date' => '2026-09-01', 'minutes' => 30, 'quoted' => ['amount' => 50, 'materials' => 0]],
            ['date' => '2026-09-08', 'minutes' => 45, 'quoted' => ['amount' => 50, 'materials' => 5]],
            ['date' => '2026-06-01', 'minutes' => 90, 'quoted' => ['amount' => 50, 'materials' => 0]], // before the window
            ['date' => '2026-09-15', 'minutes' => 30, 'quoted' => null],                               // never invoiced
        ];
        // Revenue 100; cost 30 + 45 + 5 = 80 at $60/h → 20%.
        $this->assertSame(['margin_pct' => 20.0, 'n' => 2], CloserService::realisedMargin($rows, 60, '2026-08-10'));
        $this->assertNull(CloserService::realisedMargin([], 60, '2026-08-10'));
    }

    public function test_basis_labels(): void
    {
        $this->assertSame('Your timed visits (18)', CloserService::basisLabel(['source' => 'fit', 'n' => 18]));
        $this->assertSame('Implied by the current price rule — set real minutes', CloserService::basisLabel(['source' => 'implied']));
        $this->assertNull(CloserService::basisLabel(null));
    }

    public function test_settings_validation(): void
    {
        $v = CloserSettingsService::validate([
            'hourly_cost' => '58.5', 'margin_floor_pct' => '', 'min_visit' => '45',
            'minutes' => ['mow' => ['fixed' => '6', 'per_unit' => '5.5'], 'edge' => ['fixed' => '', 'per_unit' => ''], 'snow' => ['fixed' => 9]],
        ]);
        $this->assertSame([], $v['errors']);
        $this->assertSame(['closer_hourly_cost' => '58.5', 'closer_min_visit' => '45'], $v['ops']);
        $this->assertSame(['mow' => ['fixed' => 6.0, 'per_unit' => 5.5]], $v['minutes']);

        $bad = CloserSettingsService::validate(['hourly_cost' => '0', 'margin_floor_pct' => '95', 'minutes' => ['mow' => ['fixed' => '-1']]]);
        $this->assertCount(3, $bad['errors']);
        $this->assertSame([], $bad['ops']);
    }
}
