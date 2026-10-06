<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Otto's pure rules: rain, clock-outs, visit lengths, quiet phones, words.
 */
class OttoRulesTest extends TestCase
{
    // ── Rain ────────────────────────────────────────────────────────────────

    public function test_rain_chance_is_the_worst_hour_in_the_visit_window(): void
    {
        $snap = json_encode(['hourly_window' => [['precip_chance_pct' => 20], ['precip_chance_pct' => 70], ['precip_chance_pct' => 40]]]);
        $this->assertSame(70, OttoRules::rainChance($snap));
    }

    public function test_rain_chance_falls_back_to_the_worst_hour_and_is_null_without_data(): void
    {
        $this->assertSame(55, OttoRules::rainChance(json_encode(['worst_hour' => ['precip_chance_pct' => 55]])));
        $this->assertNull(OttoRules::rainChance(null));
        $this->assertNull(OttoRules::rainChance('not json'));
    }

    public function test_no_lean_until_three_calls_like_this_one(): void
    {
        $this->assertNull(OttoRules::weatherLean(['move' => [60, 65]], 60));
        $this->assertSame('move', OttoRules::weatherLean(['move' => [60, 65, 70]], 60));
    }

    public function test_calls_at_very_different_rain_do_not_count(): void
    {
        // Kept three times at 20–30%: says nothing about 80%.
        $this->assertNull(OttoRules::weatherLean(['keep' => [20, 25, 30]], 80));
        $this->assertSame('keep', OttoRules::weatherLean(['keep' => [20, 25, 30]], 25));
    }

    public function test_a_split_owner_gets_no_lean(): void
    {
        $this->assertNull(OttoRules::weatherLean(['keep' => [60, 60], 'move' => [60, 60]], 60));
        $this->assertSame('keep', OttoRules::weatherLean(['keep' => [60, 60, 60], 'move' => [60]], 60));
    }

    public function test_weather_lessons_keep_the_newest_calls(): void
    {
        $l = OttoRules::addWeatherDecision([], 'move', 70);
        $l = OttoRules::addWeatherDecision($l, 'keep', 40);
        $l = OttoRules::addWeatherDecision($l, 'keep', null);
        $this->assertSame(['keep' => [40], 'move' => [70]], $l);
        $many = ['keep' => range(1, 30)];
        $this->assertSame(range(2, 31), OttoRules::addWeatherDecision($many, 'keep', 31)['keep']);
    }

    // ── Clock-outs ──────────────────────────────────────────────────────────

    public function test_clock_out_is_the_later_of_last_timer_and_last_fix_on_a_job(): void
    {
        $in = strtotime('2026-10-05 08:00:00');
        $timer = strtotime('2026-10-05 15:40:00');
        $fix = strtotime('2026-10-05 16:12:00');
        $this->assertSame([strtotime('2026-10-05 16:10:00'), 'gps'], OttoRules::suggestClockOut($in, $timer, $fix));
        $this->assertSame([$timer, 'timer'], OttoRules::suggestClockOut($in, $timer, null));
    }

    public function test_clock_out_ignores_times_outside_the_shift(): void
    {
        $in = strtotime('2026-10-05 08:00:00');
        $this->assertNull(OttoRules::suggestClockOut($in, $in - 60, null));
        $this->assertNull(OttoRules::suggestClockOut($in, null, $in + (OttoRules::MAX_SHIFT_HOURS + 1) * 3600));
        $this->assertNull(OttoRules::suggestClockOut($in, null, null));
    }

    public function test_kept_within_five_minutes_counts_as_right(): void
    {
        $this->assertSame('accepted', OttoRules::keptOrEdited(60, 65));
        $this->assertSame('edited', OttoRules::keptOrEdited(60, 75));
    }

    // ── Visit lengths ───────────────────────────────────────────────────────

    public function test_visit_length_prefers_gps_then_what_tim_set_then_the_estimate(): void
    {
        $this->assertSame([45, 'gps'], OttoRules::guessMinutes(42, [90], 60));
        $this->assertSame([50, 'learned'], OttoRules::guessMinutes(null, [40, 50, 60], 60));
        $this->assertSame([60, 'estimate'], OttoRules::guessMinutes(2, [], 60));
        $this->assertNull(OttoRules::guessMinutes(null, [], null));
    }

    public function test_a_gps_dwell_of_a_whole_day_is_not_believed(): void
    {
        $this->assertSame([60, 'estimate'], OttoRules::guessMinutes(900, [], 60));
    }

    // ── Quiet phones ────────────────────────────────────────────────────────

    public function test_quiet_phone_wait_is_fifteen_minutes_unless_learned_longer(): void
    {
        $this->assertSame(15, OttoRules::silentWait(null));
        $this->assertSame(15, OttoRules::silentWait(['wait_minutes' => 5]));
        $this->assertSame(60, OttoRules::silentWait(['wait_minutes' => 60]));
    }

    public function test_ask_about_a_phone_only_after_three_fines_in_a_row(): void
    {
        $this->assertFalse(OttoRules::shouldAskSilentPattern(['fine', 'fine']));
        $this->assertFalse(OttoRules::shouldAskSilentPattern(['fine', 'real', 'fine']));
        $this->assertTrue(OttoRules::shouldAskSilentPattern(['fine', 'fine', 'fine', 'real']));
    }

    // ── Words and order ─────────────────────────────────────────────────────

    public function test_headline_is_plain_and_names_what_needs_tim(): void
    {
        $s = OttoRules::headline(['stops' => 21, 'done' => 6, 'crews' => 3, 'silent' => ['Nigel'], 'weather' => 2, 'gaps' => 1], 'Tim');
        $this->assertSame("Hey Tim — 3 crews, 21 stops today, 6 done. Nigel's phone has gone quiet. The weather puts 2 visits in doubt. 1 timesheet gap to close.", $s);
        $this->assertStringNotContainsString('!', $s);
    }

    public function test_headline_on_a_quiet_day(): void
    {
        $this->assertSame('Hey Tim — 1 crew, 4 stops today. Nothing needs you right now.',
            OttoRules::headline(['stops' => 4, 'crews' => 1], 'Tim'));
        $this->assertSame('Hey — no stops on the schedule today.', OttoRules::headline([], ''));
    }

    public function test_items_sort_by_priority_keeping_found_order(): void
    {
        $sorted = OttoRules::sortItems([['k' => 'a', 'priority' => 3], ['k' => 'b', 'priority' => 1], ['k' => 'c', 'priority' => 3], ['k' => 'd', 'priority' => 1]]);
        $this->assertSame(['b', 'd', 'a', 'c'], array_column($sorted, 'k'));
        $this->assertArrayNotHasKey('_n', $sorted[0]);
    }

    public function test_day_words(): void
    {
        $this->assertSame('today', OttoRules::dayWord('2026-10-05', '2026-10-05'));
        $this->assertSame('tomorrow', OttoRules::dayWord('2026-10-06', '2026-10-05'));
        $this->assertSame('Thu Oct 8', OttoRules::dayWord('2026-10-08', '2026-10-05'));
    }

    public function test_right_first_time_needs_three_judged_calls(): void
    {
        $this->assertNull(OttoRules::rightFirstTime(['accepted', 'dismissed', 'edited']));
        $this->assertSame(67, OttoRules::rightFirstTime(['accepted', 'accepted', 'edited', 'dismissed']));
    }
}
