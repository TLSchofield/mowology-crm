<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Who is on a field-created job, and what date it starts. */
class FieldJobCrewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../app/Modules/Jobs/Services/FieldJobService.php';
    }

    public function test_picked_staff_kept_in_order_without_repeats(): void
    {
        $this->assertSame([7, 3], FieldJobService::resolveCrewIds([7, '3', 7], [3, 7, 9], 3));
    }

    public function test_inactive_or_junk_ids_dropped(): void
    {
        $this->assertSame([9], FieldJobService::resolveCrewIds([99, 0, -1, 'x', 9], [3, 9], 3));
    }

    public function test_nothing_valid_falls_back_to_creator(): void
    {
        $this->assertSame([3], FieldJobService::resolveCrewIds([], [3, 9], 3));
        $this->assertSame([3], FieldJobService::resolveCrewIds(null, [3, 9], 3));
        $this->assertSame([3], FieldJobService::resolveCrewIds([99], [3, 9], 3));
    }

    public function test_plan_data_uses_picked_crew_and_date(): void
    {
        $plan = FieldJobService::buildPlanData(
            ['service_type' => 'Cleanup', 'date' => '2026-11-25'], 5, 3, '2026-11-19', [9, 3]
        );
        $this->assertSame([9, 3], $plan['crew_ids']);
        $this->assertSame(9, $plan['default_crew_id']);
        $this->assertSame('2026-11-25', $plan['plan_start_date']);
    }

    public function test_plan_data_defaults_to_creator_and_today(): void
    {
        $plan = FieldJobService::buildPlanData(['service_type' => 'Cleanup'], 5, 3, '2026-11-19');
        $this->assertSame([3], $plan['crew_ids']);
        $this->assertSame('2026-11-19', $plan['plan_start_date']);
    }
}
