<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure logic extracted into PlanMaterialsService (Phase 2):
 *   - computeMaterialQuantity: the product application_rate parser
 *   - bucketTasksByDate: the purchase-task schedule distribution rules
 * Both are DB-free and deterministic.
 */
class PlanMaterialsServiceTest extends TestCase
{
    // ---- computeMaterialQuantity -------------------------------------------

    public function test_parses_bags_per_sqft_and_scales_to_area(): void
    {
        // 2 bags per 4000 sqft, lawn 8000 sqft → 4 bags
        $r = PlanMaterialsService::computeMaterialQuantity('2 bags per 4000 sqft', 8000.0);
        $this->assertSame(4.0, $r['qty']);
        $this->assertSame('bags', $r['unit']);
    }

    public function test_unit_is_taken_from_rate_and_lowercased(): void
    {
        // "1 Bag per 5000 sq ft" → unit "bag" (lowercased), 10000 sqft → 2
        $r = PlanMaterialsService::computeMaterialQuantity('1 Bag per 5000 sq ft', 10000.0);
        $this->assertSame(2.0, $r['qty']);
        $this->assertSame('bag', $r['unit']);
    }

    public function test_non_bag_unit(): void
    {
        // 3 kg per 1000 sqft, 2500 sqft → 7.5 kg
        $r = PlanMaterialsService::computeMaterialQuantity('3 kg per 1000 sqft', 2500.0);
        $this->assertSame(7.5, $r['qty']);
        $this->assertSame('kg', $r['unit']);
    }

    public function test_rounds_to_one_decimal(): void
    {
        // 1 bag per 3000 sqft, 4000 sqft → 1.333… → 1.3
        $r = PlanMaterialsService::computeMaterialQuantity('1 bag per 3000 sqft', 4000.0);
        $this->assertSame(1.3, $r['qty']);
    }

    public function test_unparseable_rate_returns_null_qty_default_unit(): void
    {
        $r = PlanMaterialsService::computeMaterialQuantity('apply generously', 8000.0);
        $this->assertNull($r['qty']);
        $this->assertSame('bags', $r['unit']);
    }

    public function test_zero_area_yields_null_qty_but_keeps_unit(): void
    {
        // rate parses (unit captured) but area 0 → qty stays null
        $r = PlanMaterialsService::computeMaterialQuantity('2 bags per 4000 sqft', 0.0);
        $this->assertNull($r['qty']);
        $this->assertSame('bags', $r['unit']);
    }

    // ---- bucketTasksByDate -------------------------------------------------

    public function test_every_date_in_range_is_a_key(): void
    {
        $byDate = PlanMaterialsService::bucketTasksByDate([], '2026-06-01', '2026-06-03');
        $this->assertSame(['2026-06-01', '2026-06-02', '2026-06-03'], array_keys($byDate));
        $this->assertSame([[], [], []], array_values($byDate));
    }

    public function test_fixed_date_task_shows_from_scheduled_date_onward(): void
    {
        $task = ['id' => 1, 'scheduled_date' => '2026-06-02', 'procurement_mode' => 'scheduled', 'created_at' => '2026-05-01 09:00:00'];
        $byDate = PlanMaterialsService::bucketTasksByDate([$task], '2026-06-01', '2026-06-03');
        $this->assertCount(0, $byDate['2026-06-01']);
        $this->assertCount(1, $byDate['2026-06-02']);
        $this->assertCount(1, $byDate['2026-06-03']); // overdue carry-forward
    }

    public function test_asap_task_is_persistent_from_created_date(): void
    {
        $task = ['id' => 2, 'scheduled_date' => null, 'procurement_mode' => 'asap', 'created_at' => '2026-06-02 12:00:00'];
        $byDate = PlanMaterialsService::bucketTasksByDate([$task], '2026-06-01', '2026-06-03');
        $this->assertCount(0, $byDate['2026-06-01']); // before created date
        $this->assertCount(1, $byDate['2026-06-02']);
        $this->assertCount(1, $byDate['2026-06-03']);
    }

    public function test_no_scheduled_date_uses_created_date_as_earliest(): void
    {
        $task = ['id' => 3, 'scheduled_date' => null, 'procurement_mode' => 'scheduled', 'created_at' => '2026-06-03 08:00:00'];
        $byDate = PlanMaterialsService::bucketTasksByDate([$task], '2026-06-01', '2026-06-03');
        $this->assertCount(0, $byDate['2026-06-01']);
        $this->assertCount(0, $byDate['2026-06-02']);
        $this->assertCount(1, $byDate['2026-06-03']);
    }
}
