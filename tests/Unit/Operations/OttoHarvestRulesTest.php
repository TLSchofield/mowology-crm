<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Operations/Services/ClockWeekRules.php';
require_once __DIR__ . '/../../../app/Modules/Operations/Services/WeekLoadRules.php';
require_once __DIR__ . '/../../../app/Modules/Operations/Services/SeasonalStockRules.php';
require_once __DIR__ . '/../../../app/Modules/Operations/Services/SupplierReviewRules.php';

/**
 * Otto harvest (2026-11-03): payroll-prep punch rules on last week's clock, capacity-plan's 80% on the
 * week ahead, inventory-planner's dated seasonal watch, vendor-review's quarterly look at suppliers.
 */
class OttoHarvestRulesTest extends TestCase
{
    private const NOW = '2026-11-03 15:00:00';   // a Tuesday

    private function e(int $id, string $in, ?string $out, ?int $min = null): array
    {
        return ['id' => $id, 'clock_in' => $in, 'clock_out' => $out, 'total_minutes' => $min];
    }

    // A. clock week ───────────────────────────────────────────────────────────

    public function test_last_week_is_monday_to_sunday_and_shown_mon_to_wed(): void
    {
        $this->assertSame('2026-10-26', ClockWeekRules::lastWeekMonday('2026-11-03'));   // Tue → the Monday before last
        $this->assertSame('2026-10-26', ClockWeekRules::lastWeekMonday('2026-11-02'));   // Monday itself
        $this->assertSame('2026-10-26', ClockWeekRules::lastWeekMonday('2026-11-08'));   // Sunday
        $this->assertSame('2026-10-19', ClockWeekRules::lastWeekMonday('2026-11-01'));
        $this->assertTrue(ClockWeekRules::shownToday('2026-11-03'));
        $this->assertTrue(ClockWeekRules::shownToday('2026-11-04'));
        $this->assertFalse(ClockWeekRules::shownToday('2026-11-05'));   // Thursday: dropped
    }

    public function test_duplicate_pair_zero_length_open_shift_and_week_high(): void
    {
        $week = [
            $this->e(412, '2026-10-27 01:33:57', '2026-10-27 13:33:57'),   // the live Oct 11 pair, moved into the week
            $this->e(413, '2026-10-27 01:33:57', '2026-10-27 13:33:57'),
            $this->e(409, '2026-10-28 15:47:42', '2026-10-28 15:48:36'),   // 54 s
            $this->e(420, '2026-10-29 08:00:00', '2026-10-29 16:00:00'),
            $this->e(421, '2026-10-30 08:00:00', null),                    // never clocked out
        ];
        $flags = ClockWeekRules::person('Tim', $week, [40.0, 38.0, 41.0, 39.0], 30.0, '2026-10-26', self::NOW);
        $kinds = array_column($flags, 'kind');
        $this->assertContains('overlap', $kinds);
        $this->assertContains('zero_length', $kinds);
        $this->assertContains('open', $kinds);
        $this->assertLessThanOrEqual(PayrollRunChecks::PUNCH_CAP + 1, count($flags));   // capped + "and N more"
        $texts = implode(' ', array_column($flags, 'text'));
        $this->assertStringContainsString('duplicate punch', $texts);
        $this->assertStringContainsString('#412 and #413', $texts);
        // Week-high alone: 84.5 h against ~40 h
        $big = [];
        for ($d = 26; $d <= 31; $d++) $big[] = $this->e($d, "2026-10-{$d} 07:00:00", "2026-10-{$d} 21:00:00");   // 6 × 14 h = 84 h
        $f = ClockWeekRules::person('Tim', $big, [40.0, 38.0, 41.0, 39.0], 30.0, '2026-10-26', self::NOW);
        $k = array_column($f, 'kind');
        $this->assertContains('shift', $k);                 // 14 h shifts (Penny's rule)
        $this->assertTrue(in_array('week_high', $k, true) || in_array('more', $k, true));
        $all = ClockWeekRules::person('Tim', array_slice($big, 0, 1), [40.0, 38.0, 41.0, 39.0], 30.0, '2026-10-26', self::NOW);
        $this->assertSame(['shift'], array_column($all, 'kind'));   // one 14 h day is not a high week
    }

    public function test_week_high_needs_a_baseline_and_zero_hours_needs_a_regular(): void
    {
        $week = [$this->e(1, '2026-10-26 08:00:00', '2026-10-26 20:00:00')];   // 12 h exactly: not > 12
        $this->assertSame([], ClockWeekRules::person('New', $week, [0.0, 0.0, 0.0, 0.0], null, '2026-10-26', self::NOW));
        $this->assertSame([], ClockWeekRules::person('New', $week, [3.0, 2.0], null, '2026-10-26', self::NOW));   // under MIN_HISTORY_HOURS
        $this->assertSame([], ClockWeekRules::person('Casual', [], [0.0, 20.0, 0.0, 0.0], null, '2026-10-26', self::NOW));
        $z = ClockWeekRules::person('Nigel', [], [35.6, 28.6, 34.3, 28.4], null, '2026-10-26', self::NOW);
        $this->assertSame('zero_hours', $z[0]['kind']);
        $this->assertStringContainsString('4 of the last 4 weeks', $z[0]['text']);
    }

    public function test_overlaps_and_hours(): void
    {
        $rows = [$this->e(1, '2026-10-26 08:00:00', '2026-10-26 12:00:00'), $this->e(2, '2026-10-26 11:00:00', '2026-10-26 15:00:00'), $this->e(3, '2026-10-26 15:00:00', '2026-10-26 18:00:00')];
        $this->assertSame([[1, 2]], ClockWeekRules::overlaps($rows, self::NOW));   // touching at 15:00 is not an overlap
        $this->assertSame(11.0, ClockWeekRules::hours($rows, self::NOW));
        $this->assertSame(0.0, ClockWeekRules::hours([$this->e(4, '2026-11-03 08:00:00', null)], self::NOW));          // open = hours unknown (Codex)
        $this->assertSame(7.0, ClockWeekRules::hours([$this->e(4, '2026-11-03 08:00:00', null)], self::NOW, true));
        // An open punch from three weeks ago does not inflate the baseline, so the 125% rule still fires.
        $week = []; for ($d = 26; $d <= 31; $d++) $week[] = $this->e($d, "2026-10-{$d} 07:00:00", "2026-10-{$d} 17:00:00");   // 60 h
        $f = ClockWeekRules::person('Tim', $week, [40.0, 38.0, 41.0, 0.0], null, '2026-10-26', self::NOW);
        $this->assertContains('week_high', array_column($f, 'kind'));
        // Overlap across Sunday night: the neighbour week's entry against Monday's.
        $sun = $this->e(90, '2026-10-25 23:30:00', '2026-10-26 01:00:00');
        $mon = $this->e(91, '2026-10-26 00:30:00', '2026-10-26 08:00:00');
        $f = ClockWeekRules::person('Nigel', [$mon], [40.0, 40.0], null, '2026-10-26', self::NOW, [$sun]);
        $this->assertSame('overlap', $f[0]['kind']);
        $this->assertStringContainsString('#90 and #91', $f[0]['text']);
        $this->assertSame([], ClockWeekRules::person('Nigel', [], [40.0, 40.0], null, '2026-10-26', self::NOW, [$sun]));   // a pair wholly in the old week is not this week's
    }

    public function test_clock_brief_items_one_per_person_hard_flags_first(): void
    {
        $items = ClockWeekRules::briefItems([
            ['user_id' => 1, 'flags' => [['kind' => 'week_high', 'text' => 'Tim: 84.5 h…', 'impact' => 600.0]]],
            ['user_id' => 6, 'flags' => []],
            ['user_id' => 7, 'flags' => [['kind' => 'overlap', 'text' => 'Dodge: two shifts…', 'impact' => null]]],
        ], '2026-10-26', '2026-11-03');
        $this->assertCount(2, $items);
        $this->assertSame('otto:clock-week:1:2026-10-26', $items[0]['key']);
        $this->assertSame(3, $items[0]['priority']);
        $this->assertSame(600.0, $items[0]['value']);
        $this->assertSame(2, $items[1]['priority']);
        $this->assertStringStartsWith("Last week's clock — ", $items[1]['text']);
    }

    // B. week load ────────────────────────────────────────────────────────────

    public function test_week_load_states_and_the_sanity_rule(): void
    {
        $crew = 5;   // 2,400 crew-minutes a day
        $days = [
            '2026-11-03' => ['minutes' => 1452, 'visits' => 21, 'biggest' => ['plan_id' => 1, 'title' => 'A', 'minutes' => 240]],
            '2026-11-04' => ['minutes' => 2249, 'visits' => 23, 'biggest' => ['plan_id' => 2, 'title' => 'B', 'minutes' => 300]],
            '2026-11-05' => ['minutes' => 15392, 'visits' => 43, 'biggest' => ['plan_id' => 9, 'title' => 'Big', 'minutes' => 12000]],
            '2026-11-06' => ['minutes' => 2600, 'visits' => 31, 'biggest' => ['plan_id' => 3, 'title' => '', 'minutes' => 400]],
            '2026-11-07' => ['minutes' => 500, 'visits' => 9, 'biggest' => null],
            '2026-11-08' => ['minutes' => 0, 'visits' => 0, 'biggest' => null],
        ];
        $rows = WeekLoadRules::days($days, $crew);
        $states = array_combine(array_column($rows, 'date'), array_column($rows, 'state'));
        $this->assertSame('ok', $states['2026-11-03']);         // 60%
        $this->assertSame('full', $states['2026-11-04']);       // 94%
        $this->assertSame('suspect', $states['2026-11-05']);    // 641%: a plan length
        $this->assertSame('over', $states['2026-11-06']);       // 108%
        $this->assertSame('light', $states['2026-11-07']);      // 21%
        $this->assertSame('ok', $states['2026-11-08']);         // empty day says nothing
        $this->assertStringContainsString('plan #9 Big: 12000 min', $rows[2]['note']);
        $item = WeekLoadRules::briefItem($rows, $crew, '2026-11-03');
        $this->assertSame(3, $item['priority']);                                   // tomorrow (Nov 4) is full, not over
        $this->assertStringContainsString('Thu 641% (a plan length looks wrong', $item['text']);
        $this->assertStringContainsString('Fix the plan length before reading the rest.', $item['text']);
        $this->assertSame(1, WeekLoadRules::briefItem($rows, $crew, '2026-11-04')['priority']);   // tomorrow is the suspect day
        $this->assertNull(WeekLoadRules::briefItem(WeekLoadRules::days(['2026-11-03' => ['minutes' => 1200, 'visits' => 10]], $crew), $crew, '2026-11-03'));
        $this->assertSame('n/a', WeekLoadRules::days(['2026-11-03' => ['minutes' => 1200, 'visits' => 10]], 0)[0]['state']);
    }

    public function test_one_plan_of_a_full_day_per_person_is_suspect_and_unknown_lengths_are_never_light(): void
    {
        // 480 min for one person on one visit: suspect, whatever the crew count (Codex: per plan, not per available crew).
        $rows = WeekLoadRules::days(['2026-11-03' => ['minutes' => 480, 'visits' => 2, 'biggest' => ['plan_id' => 4, 'title' => 'X', 'minutes' => 480, 'crew' => 1]]], 5);
        $this->assertSame('suspect', $rows[0]['state']);
        $this->assertStringContainsString('plan #4 X: 480 min)', $rows[0]['note']);
        // A 2 h plan for 5 people is fine even with one person "available".
        $rows = WeekLoadRules::days(['2026-11-03' => ['minutes' => 600, 'visits' => 1, 'biggest' => ['plan_id' => 5, 'title' => '', 'minutes' => 120, 'crew' => 5]]], 1);
        $this->assertSame('over', $rows[0]['state']);
        // Visits with no length: unknown, with "at least".
        $rows = WeekLoadRules::days(['2026-11-03' => ['minutes' => 300, 'visits' => 6, 'untimed' => 4, 'biggest' => null]], 5);
        $this->assertSame('unknown', $rows[0]['state']);
        $this->assertSame('4 visits with no length — at least 13%', $rows[0]['note']);
        $rows = WeekLoadRules::days(['2026-11-03' => ['minutes' => 2300, 'visits' => 6, 'untimed' => 1, 'biggest' => null]], 5);
        $this->assertSame('full', $rows[0]['state']);
        $this->assertStringContainsString(', 1 more with no length', $rows[0]['note']);
        $this->assertSame('2026-11-02', WeekLoadRules::weekKey('2026-11-03'));
        $this->assertSame('2026-11-02', WeekLoadRules::weekKey('2026-11-08'));
        $this->assertSame('otto:week-load:2026-11-02', WeekLoadRules::briefItem($rows, 5, '2026-11-05')['key']);
    }

    // C. seasonal stock ───────────────────────────────────────────────────────

    public function test_season_start_decide_by_and_the_two_messages(): void
    {
        $this->assertSame('2026-11-01', SeasonalStockRules::seasonStart('11-01', '2026-11-03'));
        $this->assertSame('2026-11-01', SeasonalStockRules::seasonStart('11-01', '2026-10-10'));
        $this->assertSame('2027-11-01', SeasonalStockRules::seasonStart('11-01', '2027-02-15'));   // this season is 3 months old: watch next year's
        $this->assertSame('2026-10-18', SeasonalStockRules::decideBy('2026-11-01', 14));
        $watch = SeasonalStockRules::seed();
        $this->assertSame('snow_routes', $watch[0]['needs']);
        // Before decide-by: quiet.
        $this->assertSame([], SeasonalStockRules::items($watch, [null], ['snow_routes' => true], ['snow_routes' => 43], '2026-10-10'));
        // After decide-by, before the season: order by.
        $i = SeasonalStockRules::items($watch, [null], ['snow_routes' => true], ['snow_routes' => 43], '2026-10-20');
        $this->assertCount(1, $i);
        $this->assertStringStartsWith('Order ice melt / salt by Nov 1', $i[0]['text']);
        $this->assertSame(2, $i[0]['priority']);
        $this->assertSame('2026-10-18', $i[0]['since']);
        // Season started, nothing bought: priority 1, with the route count and the honest limit.
        $i = SeasonalStockRules::items($watch, [null], ['snow_routes' => true], ['snow_routes' => 43], '2026-11-03');
        $this->assertSame(1, $i[0]['priority']);
        $this->assertStringContainsString('Snow season started Nov 1 — no ice melt / salt on any receipt (43 snow routes)', $i[0]['text']);
        $this->assertStringContainsString('receipts only', $i[0]['text']);
        $this->assertSame('otto:season-stock:ice-melt-salt:2026', $i[0]['key']);
        // Bought in time: quiet. Bought too long ago: not in time.
        $this->assertSame([], SeasonalStockRules::items($watch, ['2026-10-20'], ['snow_routes' => true], ['snow_routes' => 43], '2026-11-03'));
        $this->assertCount(1, SeasonalStockRules::items($watch, ['2026-05-01'], ['snow_routes' => true], ['snow_routes' => 43], '2026-11-03'));
        // No snow routes: the item is not needed.
        $this->assertSame([], SeasonalStockRules::items($watch, [null], ['snow_routes' => false], [], '2026-11-03'));
        // An owner-named item without a need gets its own words, not "snow season".
        $fert = [['item' => 'spring fertilizer', 'pattern' => 'fertili[sz]er', 'season_start' => '03-15', 'lead_days' => 21, 'needs' => '']];
        $i = SeasonalStockRules::items($fert, [null], [], [], '2027-03-20');
        $this->assertStringStartsWith('The season for spring fertilizer started Mar 15', $i[0]['text']);
        $this->assertTrue(SeasonalStockRules::patternOk('salt|ice ?melt'));
        $this->assertFalse(SeasonalStockRules::patternOk('['));
        $this->assertFalse(SeasonalStockRules::patternOk(''));
    }

    // D. supplier review ──────────────────────────────────────────────────────

    public function test_supplier_review_top_concentration_stale_and_fixed_categories(): void
    {
        $this->assertSame('2026Q4', SupplierReviewRules::quarterKey('2026-11-03'));
        $this->assertSame('2026Q1', SupplierReviewRules::quarterKey('2026-03-31'));
        $this->assertTrue(SupplierReviewRules::fixedCategory('Fuel'));
        $this->assertTrue(SupplierReviewRules::fixedCategory('Dump fees'));
        $this->assertFalse(SupplierReviewRules::fixedCategory('Materials'));
        $spend = [
            ['vendor' => 'CHEVRON', 'category' => 'Fuel', 'spend' => 868.68, 'n' => 48],
            ['vendor' => 'LAWNBOY', 'category' => 'Materials', 'spend' => 715.00, 'n' => 9],
            ['vendor' => 'Home Depot', 'category' => 'Materials', 'spend' => 217.38, 'n' => 1],
            ['vendor' => 'City of Vancouver Vancouver Landfill', 'category' => 'Dump fees', 'spend' => 408.40, 'n' => 12],
        ];
        $r = SupplierReviewRules::build($spend, ['Materials' => 3, 'Fuel' => 4], ['LAWNBOY' => '2026-07-01', 'Brecks' => '2026-10-29'], '2026-11-03');
        $this->assertSame('CHEVRON', $r['top'][0]['vendor']);
        $this->assertSame(2209.46, $r['total']);
        $this->assertCount(1, $r['concentration']);                             // Lawnboy 77% of Materials with 2 alternatives; Chevron is Fuel: never
        $this->assertSame(['category' => 'Materials', 'vendor' => 'LAWNBOY', 'share' => 0.77, 'spend' => 715.0, 'alternatives' => 2, 'verdict' => 'Negotiate'], $r['concentration'][0]);
        $this->assertSame([['vendor' => 'LAWNBOY', 'days' => 125]], $r['stale']);
        $item = SupplierReviewRules::briefItem($r, '2026-11-03');
        $this->assertSame('otto:supplier-review:2026Q4', $item['key']);
        $this->assertSame(2, $item['priority']);
        $this->assertStringContainsString('LAWNBOY has 77% of Materials with 2 other suppliers in the directory — Negotiate.', $item['text']);
        $this->assertStringContainsString("LAWNBOY's newest price list is 125 days old.", $item['text']);
        // Only one vendor in the directory for that category: nothing to negotiate with.
        $r2 = SupplierReviewRules::build($spend, ['Materials' => 1], [], '2026-11-03');
        $this->assertSame([], $r2['concentration']);
        $proceed = SupplierReviewRules::briefItem($r2, '2026-11-03')['text'];
        $this->assertStringContainsString('No supplier holds over 60% of a category that has an alternative in the directory', $proceed);
        $this->assertStringContainsString('58% of the spend is fuel, dump fees and the like', $proceed);   // 1277/2209
        $split = SupplierReviewRules::build([['vendor' => 'A', 'category' => 'Materials', 'spend' => 100, 'n' => 1], ['vendor' => 'B', 'category' => 'Materials', 'spend' => 100, 'n' => 1]], ['Materials' => 2], [], '2026-11-03');
        $this->assertStringNotContainsString('fuel', SupplierReviewRules::briefItem($split, '2026-11-03')['text']);   // no fixed spend: the sentence is not claimed
        $this->assertNull(SupplierReviewRules::briefItem(SupplierReviewRules::build([], [], [], '2026-11-03'), '2026-11-03'));
    }
}
