<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * OwnerFreedomService — pure maths behind the Owner Freedom dashboard.
 *
 * compute(): the owner's 40 h cheque vs profit left after buying back every
 * hour they work. recommend(): ranked, sized directions. dataQualityFromCounts():
 * the tracking checklist. All fed canned inputs; the DB loaders are not tested here.
 */
class OwnerFreedomServiceTest extends TestCase
{
    /** Owner draws 40 h × $40 = $1,600/week. */
    private function settings(array $over = []): array
    {
        return array_merge([
            'owner_user_id' => 1, 'owner_name' => 'Tim', 'owner_explicit' => true,
            'target_hours_week' => 40.0, 'owner_rate' => 40.0, 'owner_rate_explicit' => true,
            'field_replacement_rate' => null, 'admin_replacement_rate' => 30.0,
            'burden_pct' => 0.0, 'fixed_overhead_month' => 0.0,
        ], $over);
    }

    /** Four weeks: $20k revenue, $6k crew wages, $4k expenses; owner works 30 h field + 10 h office per week. */
    private function inputs(array $over = []): array
    {
        return array_merge([
            'weeks' => 4.0, 'revenue' => 20000.0, 'collected' => 18000.0,
            'crew_labour_cost' => 6000.0, 'expenses' => 4000.0, 'crew_avg_rate' => 25.0,
            'owner_clock_minutes' => 40 * 60 * 4.0,   // 160 h clocked
            'owner_field_minutes' => 30 * 60 * 4.0,   // 120 h on jobs
            'visit_revenue_total' => 18000.0, 'visit_revenue_owner' => 9000.0, 'recurring_revenue' => 12600.0,
            'outstanding' => 3000.0, 'overdue' => 1200.0,
        ], $over);
    }

    // ── compute ─────────────────────────────────────────────────────────────────

    /** @test */
    public function target_is_hours_times_rate_times_weeks(): void
    {
        $m = OwnerFreedomService::compute($this->inputs(), $this->settings());
        $this->assertSame(1600.0, $m['target_week']);
        $this->assertSame(6400.0, $m['target_period']);
    }

    /** @test */
    public function profit_before_owner_and_replacement_cost_split_field_and_office_hours(): void
    {
        $m = OwnerFreedomService::compute($this->inputs(), $this->settings());

        // 20000 − 6000 − 4000 = 10000 over 4 weeks
        $this->assertSame(10000.0, $m['profit_before_owner']);
        $this->assertSame(2500.0, $m['profit_before_owner_week']);

        // 120 field h × $25 crew avg + 40 office h × $30 = 3000 + 1200
        $this->assertSame(25.0, $m['field_replacement_rate']);
        $this->assertSame(4200.0, $m['replacement_cost']);
        $this->assertSame(750.0, $m['replacement_field_week']);
        $this->assertSame(300.0, $m['replacement_admin_week']);

        // left for owner: 10000 − 4200 = 5800 → 1450/week of a 1600 cheque
        $this->assertSame(5800.0, $m['profit_after_replacement']);
        $this->assertSame(1450.0, $m['available_week']);
        $this->assertSame(90.6, $m['freedom_pct']);
        $this->assertSame(91, $m['freedom_score']);
        $this->assertSame(150.0, $m['gap_week']);
        $this->assertSame(0.0, $m['surplus_week']);
        $this->assertSame('manager', $m['stage']['key']);
    }

    /** @test */
    public function coverage_now_ignores_replacement_cost(): void
    {
        $m = OwnerFreedomService::compute($this->inputs(), $this->settings());
        // 10000 / 6400
        $this->assertSame(156.3, $m['coverage_now_pct']);
    }

    /** @test */
    public function burden_and_explicit_field_rate_raise_replacement_cost(): void
    {
        $m = OwnerFreedomService::compute($this->inputs(), $this->settings([
            'field_replacement_rate' => 30.0, 'burden_pct' => 20.0,
        ]));
        // explicit field rate is used as-is; office rate carries burden: 30 × 1.2 = 36
        $this->assertSame(30.0, $m['field_replacement_rate']);
        $this->assertSame(36.0, $m['admin_replacement_rate']);
        $this->assertSame(120 * 30.0 + 40 * 36.0, $m['replacement_cost']);
    }

    /** @test */
    public function fixed_overhead_is_prorated_by_month(): void
    {
        $m = OwnerFreedomService::compute($this->inputs(), $this->settings(['fixed_overhead_month' => 1000.0]));
        // 4 weeks ≈ 0.92 months
        $this->assertEqualsWithDelta(920.6, $m['fixed_overhead'], 0.5);
        $this->assertEqualsWithDelta(10000 - 920.6, $m['profit_before_owner'], 0.5);
    }

    /** @test */
    public function field_minutes_outside_a_clocked_shift_still_count_as_hours(): void
    {
        // Owner never clocks in but has 120 h of job timers → total hours = 120, office = 0
        $m = OwnerFreedomService::compute($this->inputs(['owner_clock_minutes' => 0]), $this->settings());
        $this->assertSame(30.0, $m['owner_hours_week']);
        $this->assertSame(0.0, $m['owner_admin_hours_week']);
    }

    /** @test */
    public function score_is_clamped_and_shares_are_computed(): void
    {
        $m = OwnerFreedomService::compute($this->inputs(['revenue' => 60000.0]), $this->settings());
        $this->assertSame(100, $m['freedom_score']);
        $this->assertGreaterThan(100, $m['freedom_pct']);
        $this->assertSame(50.0, $m['owner_revenue_share_pct']);
        $this->assertSame(70.0, $m['recurring_share_pct']);
        $this->assertGreaterThan(0, $m['surplus_week']);
        $this->assertSame('owner', $m['stage']['key']);     // covered but still working 40 h
    }

    /** @test */
    public function free_stage_needs_full_coverage_and_under_ten_hours(): void
    {
        $m = OwnerFreedomService::compute($this->inputs([
            'revenue' => 60000.0, 'owner_clock_minutes' => 8 * 60 * 4.0, 'owner_field_minutes' => 0,
        ]), $this->settings());
        $this->assertSame('free', $m['stage']['key']);
    }

    /** @test */
    public function zero_rate_gives_no_score_and_unknown_stage(): void
    {
        $m = OwnerFreedomService::compute($this->inputs(), $this->settings(['owner_rate' => 0.0]));
        $this->assertNull($m['freedom_pct']);
        $this->assertNull($m['freedom_score']);
        $this->assertSame('unknown', $m['stage']['key']);
    }

    /** @test */
    public function operator_stage_when_profit_cannot_cover_the_cheque_without_owner(): void
    {
        $m = OwnerFreedomService::compute($this->inputs(['revenue' => 11000.0]), $this->settings());
        // 11000 − 10000 costs = 1000 − 4200 replacement → negative
        $this->assertLessThan(0, $m['profit_after_replacement']);
        $this->assertSame(0, $m['freedom_score']);
        $this->assertSame('operator', $m['stage']['key']);
    }

    // ── recommend ───────────────────────────────────────────────────────────────

    /** @test */
    public function directions_include_field_handoff_admin_delegation_and_priced_gap_levers(): void
    {
        $s = $this->settings();
        $in = $this->inputs(['avg_plan_monthly_value' => 400.0]);
        $m = OwnerFreedomService::compute($in, $s);
        $dirs = OwnerFreedomService::recommend($m, $in, $s, []);
        $keys = array_column($dirs, 'key');

        $this->assertContains('hand-off-field', $keys);
        $this->assertContains('delegate-admin', $keys);
        $this->assertContains('price', $keys);
        $this->assertContains('grow-recurring', $keys);
        $this->assertContains('owner-dependent-revenue', $keys);   // 50% > 40%
        $this->assertContains('collect', $keys);                    // overdue 1200
        $this->assertNotContains('recurring-share', $keys);         // 70% is fine
        $this->assertNotContains('fix-tracking', $keys);

        $price = $dirs[array_search('price', $keys, true)];
        // gap 150/week over 5000/week revenue = 3.0%
        $this->assertStringContainsString('3.0%', $price['title']);
        $this->assertSame('+$150/week', $price['impact']);

        $grow = $dirs[array_search('grow-recurring', $keys, true)];
        // gross margin 50% → need $300/week revenue → $1,303/month ÷ $400 = 4 clients
        $this->assertStringContainsString('4 recurring clients', $grow['title']);
    }

    /** @test */
    public function failed_tracking_checks_come_first(): void
    {
        $s = $this->settings();
        $in = $this->inputs();
        $m = OwnerFreedomService::compute($in, $s);
        $quality = [['key' => 'owner_clocking', 'label' => 'You clock every working day', 'status' => 'fail']];
        $dirs = OwnerFreedomService::recommend($m, $in, $s, $quality);
        $this->assertSame('fix-tracking', $dirs[0]['key']);
        $this->assertStringContainsString('You clock every working day', $dirs[0]['why']);
    }

    /** @test */
    public function covered_business_with_no_levers_gets_protect_direction(): void
    {
        $s = $this->settings();
        $in = $this->inputs([
            'revenue' => 60000.0, 'owner_clock_minutes' => 0, 'owner_field_minutes' => 0,
            'visit_revenue_owner' => 0.0, 'overdue' => 0.0, 'recurring_revenue' => 18000.0,
        ]);
        $m = OwnerFreedomService::compute($in, $s);
        $dirs = OwnerFreedomService::recommend($m, $in, $s, []);
        $this->assertCount(1, $dirs);
        $this->assertSame('protect', $dirs[0]['key']);
    }

    /** @test */
    public function no_target_yields_only_a_set_target_direction(): void
    {
        $s = $this->settings(['owner_rate' => 0.0]);
        $in = $this->inputs();
        $dirs = OwnerFreedomService::recommend(OwnerFreedomService::compute($in, $s), $in, $s, []);
        $this->assertSame(['set-target'], array_column($dirs, 'key'));
    }

    // ── data quality ────────────────────────────────────────────────────────────

    /** @test */
    public function tracking_checklist_grades_counts(): void
    {
        $q = OwnerFreedomService::dataQualityFromCounts([
            'owner_shifts_28d' => 3,
            'users_without_rate' => 0,
            'completed_visits' => 100, 'visits_attributed' => 100, 'visits_timed' => 65, 'visits_priced' => 99, 'visits_snapshotted' => 20,
            'owner_visits' => 40, 'owner_visits_with_time' => 10,
            'uninvoiced_visits' => 0,
            'expenses_30d' => 0, 'expenses_total' => 12, 'expenses_categorised' => 12,
        ], $this->settings(['fixed_overhead_month' => 0.0]));

        $by = [];
        foreach ($q as $item) $by[$item['key']] = $item;

        $this->assertSame('ok',   $by['owner']['status']);
        $this->assertSame('ok',   $by['owner_rate']['status']);
        $this->assertSame('warn', $by['owner_clocking']['status']);      // 3 shifts in 28 days
        $this->assertSame('fail', $by['owner_visit_time']['status']);    // 25% of owner visits timed
        $this->assertSame('ok',   $by['crew_rates']['status']);
        $this->assertSame('ok',   $by['visits_attributed']['status']);
        $this->assertSame('fail', $by['visits_timed']['status']);        // 65% < 70%
        $this->assertSame('ok',   $by['visits_priced']['status']);
        $this->assertSame('ok',   $by['visits_invoiced']['status']);
        $this->assertSame('fail', $by['expenses_recent']['status']);     // nothing in 30 days
        $this->assertSame('ok',   $by['expenses_categorised']['status']);
        $this->assertSame('warn', $by['fixed_overhead']['status']);
        $this->assertSame('warn', $by['margin_snapshots']['status']);
        foreach ($q as $item) {
            $this->assertNotSame('', $item['why']);
            $this->assertNotSame('', $item['fix']);
        }
    }

    /** @test */
    public function unreadable_tables_become_unknown_not_failures(): void
    {
        $q = OwnerFreedomService::dataQualityFromCounts([
            'owner_shifts_28d' => null, 'users_without_rate' => null, 'completed_visits' => null,
            'uninvoiced_visits' => null, 'expenses_30d' => null,
        ], $this->settings(['owner_user_id' => 0, 'owner_explicit' => false]));
        $by = [];
        foreach ($q as $item) $by[$item['key']] = $item;
        $this->assertSame('fail',    $by['owner']['status']);
        $this->assertSame('unknown', $by['owner_clocking']['status']);
        $this->assertSame('unknown', $by['visits_attributed']['status']);
        $this->assertSame('unknown', $by['expenses_recent']['status']);
    }

    // ── trend ───────────────────────────────────────────────────────────────────

    /** @test */
    public function score_trend_computes_each_month_independently(): void
    {
        $rows = [
            ['month' => '2026-08', 'weeks' => 4.43, 'revenue' => 20000, 'crew_labour_cost' => 6000, 'expenses' => 4000,
             'owner_clock_minutes' => 160 * 60, 'owner_field_minutes' => 120 * 60, 'visit_revenue_total' => 18000, 'visit_revenue_owner' => 9000, 'crew_avg_rate' => 25],
            ['month' => '2026-09', 'weeks' => 3.0, 'revenue' => 0, 'crew_labour_cost' => 0, 'expenses' => 0,
             'owner_clock_minutes' => 0, 'owner_field_minutes' => 0, 'visit_revenue_total' => 0, 'visit_revenue_owner' => 0, 'crew_avg_rate' => 25],
        ];
        $t = OwnerFreedomService::scoreTrend($rows, $this->settings());
        $this->assertCount(2, $t);
        $this->assertSame('Aug 26', $t[0]['label']);
        $this->assertGreaterThan(0, $t[0]['freedom_pct']);
        $this->assertSame(0.0, $t[1]['freedom_pct']);
        $this->assertSame(0.0, $t[1]['owner_hours_week']);
    }
}
