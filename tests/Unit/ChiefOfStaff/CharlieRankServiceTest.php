<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * How Charlie orders what the heads hand him, and how he learns the owner's order.
 */
class CharlieRankServiceTest extends TestCase
{
    private function item(string $key, int $p = 2, ?float $value = null, ?string $since = null): array
    {
        return CharlieRankService::normalize(['key' => $key, 'text' => "Do {$key}", 'priority' => $p, 'value' => $value, 'since' => $since], 'sam');
    }

    public function test_items_come_onto_the_contract_with_a_kind_from_their_key(): void
    {
        $it = CharlieRankService::normalize(['key' => 'sam:lead:12', 'text' => "  New lead\n from HomeStars ", 'priority' => 9], 'sam');
        $this->assertSame('sam:lead', $it['kind']);
        $this->assertSame('New lead from HomeStars', $it['text']);
        $this->assertSame(3, $it['priority']);
        $this->assertNull($it['url']);

        $this->assertSame('mia:review', CharlieRankService::normalize(['key' => 'mia:x:1', 'kind' => 'review', 'text' => 'a'], 'mia')['kind']);
        $this->assertSame('house:overdue_invoices', CharlieRankService::kindFromKey('house:overdue_invoices'));
    }

    public function test_an_item_with_no_text_is_dropped_and_one_with_no_key_still_shows(): void
    {
        $this->assertNull(CharlieRankService::normalize(['key' => 'sam:lead:1', 'text' => ' '], 'sam'));
        $a = CharlieRankService::normalize(['text' => 'Call Bob'], 'otto');
        $b = CharlieRankService::normalize(['text' => 'Call Bob'], 'otto');
        $this->assertStringStartsWith('otto:text:', $a['key']);
        $this->assertSame($a['key'], $b['key'], 'the fallback key is stable for the same wording');
    }

    public function test_priority_age_and_money_all_raise_the_score_and_age_is_capped(): void
    {
        $today = '2026-10-05';
        $p1 = CharlieRankService::score($this->item('sam:a:1', 1), 1.0, $today);
        $p3 = CharlieRankService::score($this->item('sam:a:2', 3), 1.0, $today);
        $this->assertGreaterThan($p3, $p1);

        $old = CharlieRankService::score($this->item('sam:a:3', 2, null, '2026-10-01'), 1.0, $today);
        $this->assertEqualsWithDelta(40 * 1.4, $old, 0.01);
        $ancient = CharlieRankService::score($this->item('sam:a:4', 2, null, '2025-01-01'), 1.0, $today);
        $this->assertEqualsWithDelta(80.0, $ancient, 0.01);

        $money = CharlieRankService::score($this->item('sam:a:5', 2, 900.0), 1.0, $today);
        $this->assertEqualsWithDelta(40 * 2, $money, 0.01);
    }

    public function test_rank_puts_the_best_first_and_leaves_out_muted_kinds_and_hidden_items(): void
    {
        $items = [$this->item('sam:a:1', 3), $this->item('sam:b:1', 1), $this->item('sam:c:1', 2), $this->item('sam:d:1', 1)];
        $ranked = CharlieRankService::rank($items, ['sam:d' => ['score' => 1.0, 'muted' => true]], ['sam:c:1'], '2026-10-05');
        $this->assertSame(['sam:b:1', 'sam:a:1'], array_column($ranked, 'key'));
    }

    public function test_learned_preference_can_lift_a_lower_priority_kind_over_a_higher_one(): void
    {
        $items = [$this->item('sam:quote:1', 1), $this->item('sam:lead:1', 2)];
        $ranked = CharlieRankService::rank($items, ['sam:lead' => ['score' => 3.0, 'muted' => false]], [], '2026-10-05');
        $this->assertSame('sam:lead:1', $ranked[0]['key']);
    }

    public function test_a_close_call_needs_two_kinds_within_ten_percent(): void
    {
        $a = ['kind' => 'sam:a', 'score' => 100.0];
        $this->assertTrue(CharlieRankService::closeCall([$a, ['kind' => 'sam:b', 'score' => 91.0]]));
        $this->assertFalse(CharlieRankService::closeCall([$a, ['kind' => 'sam:b', 'score' => 80.0]]));
        $this->assertFalse(CharlieRankService::closeCall([$a, ['kind' => 'sam:a', 'score' => 100.0]]));
        $this->assertFalse(CharlieRankService::closeCall([$a]));
    }

    public function test_learning_moves_winner_up_and_loser_down_symmetrically_and_within_bounds(): void
    {
        [$w, $l] = CharlieRankService::learnPair(1.0, 1.0);
        $this->assertGreaterThan(1.0, $w);
        $this->assertLessThan(1.0, $l);
        $this->assertEqualsWithDelta(1.0, $w * $l, 0.01, 'equal steps on a log scale');

        // An expected win teaches less than an upset.
        [$expected] = CharlieRankService::learnPair(3.0, 1.0);
        [$upset] = CharlieRankService::learnPair(1.0, 3.0);
        $this->assertLessThan(log($upset) - log(1.0), log($expected) - log(3.0));

        [$hi, $lo] = CharlieRankService::learnPair(4.0, 0.25, 5.0);
        $this->assertSame(4.0, $hi);
        $this->assertSame(0.25, $lo);
    }

    public function test_many_wins_keep_the_preference_inside_its_clamp(): void
    {
        $w = 1.0;
        $l = 1.0;
        for ($i = 0; $i < 500; $i++) [$w, $l] = CharlieRankService::learnPair($w, $l, CharlieRankService::K_ANSWER);
        $this->assertLessThanOrEqual(CharlieRankService::MAX_PREF, $w);
        $this->assertGreaterThanOrEqual(CharlieRankService::MIN_PREF, $l);
    }

    public function test_a_fresh_yes_outranks_an_older_top_priority_item(): void
    {
        $old = CharlieRankService::normalize(['key' => 'yui:promise:1', 'text' => 'Council approved', 'priority' => 1, 'since' => '2026-09-11'], 'yui');
        $yes = CharlieRankService::normalize(['key' => 'yui:reply:2', 'text' => 'Gaby replied "Yes"', 'priority' => 1, 'since' => '2026-10-06', 'yes' => true], 'yui');
        $this->assertTrue($yes['yes']);
        $this->assertFalse($old['yes']);
        $this->assertGreaterThan(
            CharlieRankService::score($old, 1.0, '2026-10-07'),
            CharlieRankService::score($yes, 1.0, '2026-10-07')
        );
    }
}
