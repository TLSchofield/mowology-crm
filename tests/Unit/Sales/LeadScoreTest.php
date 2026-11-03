<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Heat (0–100), the four buckets, and the rule that a flat score is never shown as a ranking.
 * Numbers come from the harvested lead-triage / speed-to-lead model (docs/plans/2026-11-03-sam-lead-score-and-buckets.md).
 */
class LeadScoreTest extends TestCase
{
    private const NOW = '2026-11-03 09:00:00';
    private const AVG = ['hedge trimming' => 400.0, 'snow removal' => 1500.0, 'all' => 600.0];
    private const OFFERED = ['lawn', 'mowing', 'hedge', 'garden', 'cleanup', 'aeration', 'mulch', 'snow', 'salting', 'landscaping', 'maintenance', 'strata', 'pruning'];

    private function lead(array $o = []): array
    {
        return $o + ['id' => 1, 'service_types' => 'hedge_trimming', 'urgency' => 'inquiring', 'project_description' => '',
            'created_at' => '2026-11-01 09:00:00', 'address' => '1 Oak St', 'city' => 'Vancouver', 'source' => 'website', 'first_name' => 'Ann'];
    }

    private function score(array $lead = [], array $signals = [], float $value = 400.0): array
    {
        return LeadScore::score($this->lead($lead), $signals + ['available' => true], self::AVG, $value, self::OFFERED, self::NOW);
    }

    public function test_a_plain_new_lead_scores_fit_and_a_little_freshness(): void
    {
        $s = $this->score();
        $this->assertSame(0, $s['engagement']);
        $this->assertSame(25, $s['fit']);            // work we do and have priced
        $this->assertSame(5, $s['urgency']);         // under 7 days old
        $this->assertSame(0, $s['recency']);
        $this->assertSame(30, $s['heat']);
        $this->assertSame('qualified', $s['bucket']);
        $this->assertNull($s['ask']);
        $this->assertTrue($s['untouched']);
    }

    public function test_engagement_reply_beats_open_and_many_silent_touches_cost_points(): void
    {
        $this->assertSame(15, $this->score([], ['inbound_at' => '2026-11-02 10:00:00', 'opened_at' => '2026-11-02 10:00:00'])['engagement']);
        $this->assertSame(8, $this->score([], ['opened_at' => '2026-11-02 10:00:00'])['engagement']);
        $this->assertSame(0, $this->score([], ['inbound_at' => '2026-10-01 10:00:00'])['engagement']);     // older than 14 days
        $s = $this->score([], ['outbound_count' => 4, 'outbound_at' => '2026-10-20 10:00:00']);
        $this->assertSame(0, $s['engagement']);                                                           // floor 0, but the reason is kept
        $this->assertContains('4 touches, no reply', $s['reasons']);
        $this->assertSame(15, $this->score([], ['outbound_count' => 4, 'inbound_at' => '2026-11-02 10:00:00'])['engagement']); // they replied: no penalty
    }

    public function test_the_enquiry_email_itself_is_not_a_reply(): void
    {
        // Email leads are created from the inbound message: same minute → no engagement, no "replied" reason
        $s = $this->score(['created_at' => '2026-11-02 15:27:00'], ['inbound_at' => '2026-11-02 15:27:17']);
        $this->assertSame(0, $s['engagement']);
        $this->assertNotContains('replied today', $s['reasons']);
        // A message two hours later is a real reply
        $this->assertSame(15, $this->score(['created_at' => '2026-11-02 13:00:00'], ['inbound_at' => '2026-11-02 15:27:17'])['engagement']);
    }

    public function test_fit_ladder_priced_offered_address_only_nothing(): void
    {
        $this->assertSame(25, $this->score(['service_types' => 'snow_removal'])['fit']);
        $this->assertSame(15, $this->score(['service_types' => 'mulch'])['fit']);                              // offered, never priced
        $this->assertSame(12, $this->score(['service_types' => ''])['fit']);                                   // address only
        $this->assertSame(5, $this->score(['service_types' => '', 'address' => '', 'city' => ''])['fit']);
        $this->assertSame(5, $this->score(['service_types' => 'roofing'])['fit']);                             // not our work
    }

    public function test_urgency_flag_or_words_plus_the_follow_up_window(): void
    {
        $this->assertSame(20, $this->score(['urgency' => 'asap'])['urgency']);                                 // 15 + 5 fresh
        $this->assertSame(20, $this->score(['project_description' => 'Need this done ASAP please'])['urgency']);
        $this->assertSame(15, $this->score(['urgency' => 'soon'])['urgency']);                                 // 10 + 5
        $this->assertSame(10, $this->score(['created_at' => '2026-10-20 09:00:00'])['urgency']);               // 14 days: window
        $this->assertSame(25, $this->score(['urgency' => 'asap', 'created_at' => '2026-10-20 09:00:00'])['urgency']); // capped
        $this->assertSame(0, $this->score(['created_at' => '2026-08-01 09:00:00'])['urgency']);                // > 60 days, floor 0
        $this->assertSame(0, $this->score(['created_at' => '2026-10-01 09:00:00'])['urgency']);                // 33 days: nothing
    }

    public function test_recency_penalty_for_leads_we_wrote_to(): void
    {
        $this->assertSame(25, $this->score([], ['outbound_at' => '2026-11-03 07:00:00'])['recency']);
        $this->assertSame(10, $this->score([], ['outbound_at' => '2026-11-01 07:00:00'])['recency']);
        $this->assertSame(5, $this->score([], ['outbound_at' => '2026-10-28 07:00:00'])['recency']);
        $this->assertSame(0, $this->score([], ['outbound_at' => '2026-10-20 07:00:00'])['recency']);
        $s = $this->score([], ['outbound_at' => '2026-11-03 07:00:00']);
        $this->assertSame(5, $s['heat']);                                                                     // 30 − 25
        $this->assertFalse($s['untouched']);
    }

    public function test_heat_is_clamped_and_a_future_stamp_is_ignored(): void
    {
        $s = $this->score(['service_types' => 'roofing', 'address' => '', 'city' => '', 'created_at' => '2026-08-01'], ['outbound_at' => '2026-11-03 08:00:00']);
        $this->assertSame(0, $s['heat']);
        $this->assertSame(0, $this->score([], ['inbound_at' => '2026-12-01 10:00:00'])['engagement']);
    }

    public function test_unknown_or_failed_signals_are_reported_not_scored(): void
    {
        foreach ([['available' => false], []] as $signals) {
            $s = LeadScore::score($this->lead(), $signals, self::AVG, 400.0, self::OFFERED, self::NOW);
            $this->assertSame(0, $s['engagement']);
            $this->assertContains('no reply data', $s['reasons']);
            $this->assertFalse($s['untouched']);          // unknown is not "nobody has touched it"
        }
    }

    public function test_out_of_scope_is_never_flagged_hot_by_the_desk(): void
    {
        $rows = [['id' => 9, 'service_types' => 'roofing', 'urgency' => 'asap', 'project_description' => '[Classification: tier:high]', 'created_at' => '2026-11-02 09:00:00',
                  'first_name' => 'Al', 'last_name' => '', 'email' => '', 'phone' => '', 'address' => '', 'city' => '', 'source' => '']];
        $r = SalesDeskService::rankLeads($rows, self::AVG, self::NOW);
        $this->assertSame('out_of_scope', $r[0]['bucket']);
        $this->assertFalse($r[0]['hot']);
    }

    public function test_hot_when_urgent_repeat_referral_or_big_value_even_with_thin_fit(): void
    {
        $this->assertSame('hot', $this->score(['urgency' => 'asap', 'service_types' => '', 'address' => '', 'city' => ''])['bucket']); // urgency beats fit
        $this->assertSame('hot', $this->score([], ['repeat' => true])['bucket']);
        $this->assertSame('hot', $this->score(['source' => 'referral_program'])['bucket']);
        $this->assertSame('hot', $this->score([], [], 1000.0)['bucket']);        // > 1.5 × 600
        $this->assertSame('qualified', $this->score([], [], 900.0)['bucket']);   // = 1.5 × 600, not above
    }

    public function test_unclear_gets_exactly_one_question_and_out_of_scope_an_honest_no(): void
    {
        $s = $this->score(['service_types' => '']);
        $this->assertSame('unclear', $s['bucket']);
        $this->assertStringContainsString('What work are you after', $s['ask']);
        $s = $this->score(['service_types' => '', 'address' => '', 'city' => '']);
        $this->assertStringContainsString('What work are you after', $s['ask']);   // what before where
        $s = $this->score(['service_types' => 'roofing']);
        $this->assertSame('out_of_scope', $s['bucket']);
        $this->assertStringContainsString('not work we do', $s['ask']);
        $this->assertSame('out_of_scope', $this->score(['service_types' => 'roofing', 'urgency' => 'asap'])['bucket']); // scope is about fit, not urgency
        $this->assertSame('out_of_scope', $this->score([], ['not_lead' => true])['bucket']);
    }

    public function test_order_by_heat_then_value_then_newest(): void
    {
        $items = [
            ['id' => 1, 'heat' => 30, 'value' => 400.0, 'created_at' => '2026-11-01', 'urgency' => 'inquiring', 'untouched' => true],
            ['id' => 2, 'heat' => 55, 'value' => 100.0, 'created_at' => '2026-11-01', 'urgency' => 'inquiring', 'untouched' => true],
            ['id' => 3, 'heat' => 30, 'value' => 900.0, 'created_at' => '2026-10-30', 'urgency' => 'inquiring', 'untouched' => true],
        ];
        $o = LeadScore::order($items);
        $this->assertSame('heat', $o['ranked_by']);
        $this->assertSame([2, 3, 1], array_column($o['items'], 'id'));
    }

    public function test_a_flat_list_is_ordered_by_signal_untouched_urgency_value_newest(): void
    {
        $items = [
            ['id' => 1, 'heat' => 30, 'value' => 900.0, 'created_at' => '2026-11-01', 'urgency' => 'inquiring', 'untouched' => false],
            ['id' => 2, 'heat' => 35, 'value' => 100.0, 'created_at' => '2026-10-30', 'urgency' => 'inquiring', 'untouched' => true],
            ['id' => 3, 'heat' => 27, 'value' => 100.0, 'created_at' => '2026-11-02', 'urgency' => 'inquiring', 'untouched' => true],
            ['id' => 4, 'heat' => 30, 'value' => 50.0, 'created_at' => '2026-10-01', 'urgency' => 'soon', 'untouched' => true],
        ];
        $o = LeadScore::order($items);
        $this->assertSame('signal', $o['ranked_by']);
        $this->assertSame([4, 3, 2, 1], array_column($o['items'], 'id'));   // soon first among untouched; ties by newest; touched last
        $this->assertSame('heat', LeadScore::order([$items[0]])['ranked_by']);   // one lead is never "flat"
    }
}
