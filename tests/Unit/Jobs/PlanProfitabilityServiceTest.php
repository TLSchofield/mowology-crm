<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure money math extracted into PlanProfitabilityService
 * (refactor Phase 2). These methods take plain numbers — no DB — so they are
 * fully deterministic. They carry the overhead / profit / margin arithmetic that
 * the job-view page and schedule cards depend on.
 */
class PlanProfitabilityServiceTest extends TestCase
{
    // ---- normalizeMonthlyOverhead ------------------------------------------

    public function test_normalizeMonthlyOverhead_sums_with_frequency_multipliers(): void
    {
        $items = [
            ['amount' => 100, 'frequency' => 'monthly'],   // 100
            ['amount' => 10,  'frequency' => 'weekly'],    // 10 * 52/12 = 43.33…
            ['amount' => 30,  'frequency' => 'quarterly'], // 30 / 3 = 10
            ['amount' => 120, 'frequency' => 'annual'],    // 120 / 12 = 10
        ];
        $expected = 100 + (10 * (52 / 12)) + (30 / 3) + (120 / 12);
        $this->assertEqualsWithDelta($expected, PlanProfitabilityService::normalizeMonthlyOverhead($items), 0.0001);
    }

    public function test_normalizeMonthlyOverhead_unknown_frequency_is_monthly(): void
    {
        $this->assertSame(
            50.0,
            PlanProfitabilityService::normalizeMonthlyOverhead([['amount' => 50, 'frequency' => 'whatever']])
        );
    }

    public function test_normalizeMonthlyOverhead_empty_is_zero(): void
    {
        $this->assertSame(0.0, PlanProfitabilityService::normalizeMonthlyOverhead([]));
    }

    // ---- computeOverheadCost -----------------------------------------------

    public function test_overhead_percentage_mode(): void
    {
        // mode 0: (labor + expenses) * overhead_percent/100
        $settings = ['overhead_percent' => 20, 'estimated_billable_hours' => 160];
        $cost = PlanProfitabilityService::computeOverheadCost(0, 100.0, 50.0, 600.0, $settings, 0.0);
        $this->assertSame(30.0, $cost); // 150 * 0.20
    }

    public function test_overhead_per_hour_mode(): void
    {
        // mode 1: (monthlyOverhead / billableHours) * laborHours
        // 4000 / 160 = 25/hr; laborMinutes 600 = 10h → 250
        $settings = ['overhead_percent' => 20, 'estimated_billable_hours' => 160];
        $cost = PlanProfitabilityService::computeOverheadCost(1, 100.0, 50.0, 600.0, $settings, 4000.0);
        $this->assertSame(250.0, $cost);
    }

    public function test_overhead_per_hour_mode_guards_zero_billable_hours(): void
    {
        // billable hours floored at 1 to avoid divide-by-zero
        $settings = ['overhead_percent' => 20, 'estimated_billable_hours' => 0];
        $cost = PlanProfitabilityService::computeOverheadCost(1, 0.0, 0.0, 60.0, $settings, 100.0);
        $this->assertSame(100.0, $cost); // (100/1) * (60/60)
    }

    // ---- buildPlanProfitabilityResult --------------------------------------

    public function test_build_result_totals_and_margin(): void
    {
        $r = PlanProfitabilityService::buildPlanProfitabilityResult(
            1000.0, 400.0, 480.0, false, 100.0, 0, 100.0, 4
        );
        $this->assertSame(1000.0, $r['revenue']);
        $this->assertSame(600.0, $r['total_cost']);   // 400 + 100 + 100
        $this->assertSame(400.0, $r['profit']);        // 1000 - 600
        $this->assertSame(40.0, $r['margin_pct']);     // 400/1000 * 100
        $this->assertSame(4, $r['completed_visits']);
        $this->assertTrue($r['has_data']);
        $this->assertFalse($r['labor_estimated']);
        $this->assertSame(0, $r['overhead_mode']);
    }

    public function test_build_result_zero_revenue_margin_is_zero(): void
    {
        $r = PlanProfitabilityService::buildPlanProfitabilityResult(
            0.0, 100.0, 60.0, true, 0.0, 0, 20.0, 1
        );
        $this->assertSame(0.0, $r['margin_pct']);      // guarded (round() → float), not div-by-zero
        $this->assertSame(-120.0, $r['profit']);       // 0 - (100+0+20)
    }

    public function test_build_result_rounds_to_money_precision(): void
    {
        $r = PlanProfitabilityService::buildPlanProfitabilityResult(
            99.999, 33.333, 45.6, false, 0.0, 0, 0.0, 1
        );
        $this->assertSame(100.0, $r['revenue']);       // round(99.999, 2)
        $this->assertSame(33.33, $r['labor_cost']);    // round(33.333, 2)
        $this->assertSame(46.0, $r['labor_minutes']);  // round(45.6, 0)
    }

    // ---- computeStopMargin -------------------------------------------------

    public function test_stop_margin_no_completed_visits(): void
    {
        $m = PlanProfitabilityService::computeStopMargin(100.0, 0, 0.0, 0.0, 60.0, 25.0, 20.0);
        $this->assertNull($m['margin_pct']);
        $this->assertFalse($m['has_data']);
    }

    public function test_stop_margin_uses_actual_revenue_when_present(): void
    {
        // actual revenue 400; labor 240min=4h * 25 = 100; overhead 20% = 20; cost 120
        // margin = (400-120)/400*100 = 70
        $m = PlanProfitabilityService::computeStopMargin(0.0, 4, 400.0, 240.0, 60.0, 25.0, 20.0);
        $this->assertSame(70, $m['margin_pct']);
        $this->assertTrue($m['has_data']);
    }

    public function test_stop_margin_falls_back_to_price_and_estimated_labor(): void
    {
        // no actual revenue → price 100 * 2 completed = 200 revenue
        // no actual labor → estimated 60min * 2 = 120min = 2h * 30 = 60 labor
        // overhead 10% = 6; cost 66; margin = (200-66)/200*100 = 67
        $m = PlanProfitabilityService::computeStopMargin(100.0, 2, 0.0, 0.0, 60.0, 30.0, 10.0);
        $this->assertSame(67, $m['margin_pct']);
        $this->assertTrue($m['has_data']);
    }

    public function test_stop_margin_zero_revenue_returns_no_data(): void
    {
        // price 0, no actual revenue → revenue 0 → guarded
        $m = PlanProfitabilityService::computeStopMargin(0.0, 3, 0.0, 100.0, 60.0, 25.0, 20.0);
        $this->assertNull($m['margin_pct']);
        $this->assertFalse($m['has_data']);
    }

    public function test_time_over_ratio_expects_estimate_times_crew(): void
    {
        // PLN-2026-0145: 257 person-minutes, 60 minute estimate, 2 crew
        $this->assertEqualsWithDelta(0.0, PlanProfitabilityService::timeOverRatio(120, 60, 2), 0.0001);
        $this->assertEqualsWithDelta(1.1417, PlanProfitabilityService::timeOverRatio(257, 60, 2), 0.001);   // 2 crew: 137 over 120
        $this->assertEqualsWithDelta(3.2833, PlanProfitabilityService::timeOverRatio(257, 60, 1), 0.001);   // as if one person
        $this->assertEqualsWithDelta(0.1417, PlanProfitabilityService::timeOverRatio(137, 60, 2), 0.001);
        $this->assertSame(0.0, PlanProfitabilityService::timeOverRatio(30, 60, 0));
    }

    public function test_job_costing_lines_take_costs_off_sales(): void
    {
        $p = PlanProfitabilityService::buildPlanProfitabilityResult(1125.0, 158.48, 257, false, 0.0, 0, 110.0, 1);
        $c = PlanProfitabilityService::jobCostingLines($p);
        $by = array_column($c['lines'], null, 'key');
        $this->assertSame(1125.0, $by['sales']['amount']);
        $this->assertSame(-158.48, $by['labor']['amount']);
        $this->assertSame(-110.0, $by['overhead']['amount']);
        $this->assertSame(856.52, $by['profit']['amount']);
        $this->assertSame(['sales', 'labor', 'expenses', 'overhead', 'profit'], array_column($c['lines'], 'key'));
        $this->assertTrue($c['materials_missing'], 'a completed job with no purchases is flagged');

        $p2 = PlanProfitabilityService::buildPlanProfitabilityResult(1125.0, 158.48, 257, false, 295.05, 0, 110.0, 1);
        $this->assertFalse(PlanProfitabilityService::jobCostingLines($p2)['materials_missing']);
    }

}
