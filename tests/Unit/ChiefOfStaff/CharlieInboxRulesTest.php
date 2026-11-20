<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The decision inbox (view + router) and Tim's conflict rules, plus the urgent list.
 */
class CharlieInboxRulesTest extends TestCase
{
    private const RULES = [
        'collections_first' => ['enabled' => true, 'params' => ['late_days' => 14]],
        'one_message_week'  => ['enabled' => true, 'params' => ['window_days' => 7]],
    ];

    private function msg(string $key, int $cid, string $kind = 'sam:quote_followup', string $name = 'Oak Strata'): array
    {
        return ['key' => $key, 'head' => 'sam', 'kind' => $kind, 'channel' => 'message', 'contact_id' => $cid, 'contact_name' => $name, 'text' => $key];
    }

    public function test_collections_first_holds_sales_messages_to_clients_more_than_14_days_late(): void
    {
        $r = ConflictRules::apply([$this->msg('a', 1), $this->msg('b', 2), $this->msg('c', 3)], ['late' => [1 => 20, 2 => 14]], self::RULES);
        $this->assertSame(['b', 'c'], array_column(array_column($r['allowed'], null), 'key'));
        $this->assertSame('collections_first', $r['held'][0]['rule']);
        $this->assertSame(20, $r['held'][0]['detail']['days_late']);
        $this->assertStringContainsString('20 days late', $r['held'][0]['reason']);
    }

    public function test_collections_messages_themselves_are_never_held_and_never_escalate(): void
    {
        $r = ConflictRules::apply([$this->msg('a', 1, 'mia:invoice_reminder')], ['late' => [1 => 60]], self::RULES);
        $this->assertCount(1, $r['allowed']);
        $this->assertSame([], $r['escalated']);
    }

    public function test_one_message_per_client_per_week(): void
    {
        $r = ConflictRules::apply(
            [$this->msg('first', 5), $this->msg('second', 5), $this->msg('recent', 6), $this->msg('old', 7)],
            ['last_message' => [6 => 3, 7 => 9]], self::RULES);
        $this->assertSame(['first', 'old'], array_column($r['allowed'], 'key'));
        $held = array_column($r['held'], 'reason', null);
        $this->assertStringContainsString('higher-priority message', $r['held'][0]['reason']);
        $this->assertStringContainsString('3 days ago', $r['held'][1]['reason']);
    }

    public function test_a_reply_to_a_customer_who_wrote_in_is_never_held_but_counts_as_the_weeks_message(): void
    {
        $r = ConflictRules::apply([$this->msg('reply', 5, 'sam:customer_replied'), $this->msg('nudge', 5)], ['last_message' => [5 => 1]], self::RULES);
        $this->assertSame(['reply'], array_column($r['allowed'], 'key'));
        $this->assertSame(['nudge'], array_column(array_column($r['held'], 'proposal'), 'key'));
        $late = ConflictRules::apply([$this->msg('reply', 5, 'sam:customer_replied')], ['late' => [5 => 40]], self::RULES);
        $this->assertCount(1, $late['held'], 'collections first still applies');
    }

    public function test_legal_notices_always_go_and_are_logged_as_escalations(): void
    {
        $r = ConflictRules::apply([$this->msg('note', 5), $this->msg('legal', 5, 'mia:legal_notice')], ['last_message' => [5 => 1]], self::RULES);
        $this->assertSame(['legal'], array_column($r['allowed'], 'key'));
        $this->assertCount(1, $r['escalated']);
    }

    public function test_an_override_releases_the_proposal_and_rules_can_be_switched_off(): void
    {
        $r = ConflictRules::apply([$this->msg('a', 1)], ['late' => [1 => 30]], self::RULES, ['collections_first|a' => true]);
        $this->assertCount(1, $r['allowed']);
        $off = self::RULES;
        $off['one_message_week']['enabled'] = false;
        $r = ConflictRules::apply([$this->msg('a', 1), $this->msg('b', 1)], [], $off);
        $this->assertCount(2, $r['allowed']);
        $r = ConflictRules::apply([['key' => 'x', 'channel' => 'schedule', 'contact_id' => 1]], ['late' => [1 => 99]], self::RULES);
        $this->assertCount(1, $r['allowed'], 'only messages to a person are ruled on');
    }

    public function test_two_overrides_propose_the_one_change_that_would_have_allowed_them(): void
    {
        $this->assertSame(['late_days' => 21], ConflictRules::rewrite('collections_first', ['late_days' => 14], [['days_late' => 18], ['days_late' => 21]]));
        $this->assertNull(ConflictRules::rewrite('collections_first', ['late_days' => 30], [['days_late' => 18]]));
        $this->assertSame(['window_days' => 4], ConflictRules::rewrite('one_message_week', ['window_days' => 7], [['days_since' => 5], ['days_since' => 4]]));
        $this->assertNull(ConflictRules::rewrite('one_message_week', ['window_days' => 7], [['days_since' => 0]]), 'same-day clashes have no gentler number');
        $this->assertStringContainsString('more than 21 days late', ConflictRules::sentence('collections_first', ['late_days' => 21], ''));
    }

    public function test_buttons_only_post_to_head_endpoints(): void
    {
        $a = CharlieInboxService::safeActions([
            ['label' => 'Go', 'endpoint' => '/crm/api/otto.php', 'body' => ['mode' => 'decide']],
            ['label' => 'Evil', 'endpoint' => 'https://example.com/x', 'body' => ['mode' => 'x']],
            ['label' => 'No mode', 'endpoint' => '/crm/api/otto.php', 'body' => []],
        ]);
        $this->assertSame(['Go'], array_column($a, 'label'));
    }

    public function test_otto_suggestions_become_one_tap_proposals_and_batch(): void
    {
        $items = [
            ['key' => 'otto:clock:1', 'kind' => 'clock_out', 'sid' => 11, 'text' => 'Nigel never clocked out', 'priority' => 1, 'for_date' => '2026-10-05', 'propose' => ['clock_out' => '2026-10-05T16:30']],
            ['key' => 'otto:clock:2', 'kind' => 'clock_out', 'sid' => 12, 'text' => 'Tim never clocked out', 'priority' => 1, 'for_date' => '2026-10-05', 'propose' => ['clock_out' => '2026-10-05T17:00']],
            ['key' => 'otto:visit:9', 'kind' => 'weather', 'sid' => 13, 'text' => 'Rain', 'priority' => 1, 'for_date' => '2026-10-06', 'propose' => []],
            ['key' => 'otto:visit:10', 'kind' => 'weather', 'sid' => null, 'text' => 'Not recorded yet', 'priority' => 2, 'for_date' => '2026-10-06'],
        ];
        $p = CharlieInboxService::fromOtto($items);
        $this->assertSame(['mode' => 'decide', 'suggestion_id' => 11, 'choice' => 'apply', 'clock_out' => '2026-10-05T16:30'], $p[0]['actions'][0]['body']);
        $this->assertSame('otto:clock_out', $p[0]['batch_key']);
        $this->assertSame('keep', $p[2]['actions'][0]['body']['choice']);
        $this->assertSame([], $p[3]['actions'], 'no suggestion id yet → view only');

        $ranked = CharlieInboxService::rank(array_map(fn($x) => CharlieRankService::normalize($x, 'otto') + $x, $p), [], '2026-10-06');
        $rows = CharlieInboxService::batch($ranked);
        $batch = array_values(array_filter($rows, fn($r) => $r['type'] === 'batch'));
        $this->assertCount(1, $batch);
        $this->assertSame('Set 2 suggested clock-outs', $batch[0]['label']);
        $this->assertCount(3, $rows);
    }

    public function test_sam_cards_are_message_proposals_with_the_client_attached(): void
    {
        $p = CharlieInboxService::fromSam([['key' => 'c42', 'kind' => 'stale', 'contact_id' => 42, 'name' => 'Oak Strata', 'label' => 'Fall cleanup', 'amount' => 1800, 'days' => 12]]);
        $this->assertSame('sam:contact:c42', $p[0]['key']);
        $this->assertSame('message', $p[0]['channel']);
        $this->assertSame(42, $p[0]['contact_id']);
        $this->assertSame(['mode' => 'park', 'card_key' => 'c42', 'how' => 'skip'], $p[0]['actions'][0]['body']);
    }

    public function test_deadlines_rank_higher_as_the_due_date_nears(): void
    {
        $base = ['priority' => 2, 'value' => null, 'since' => null, 'kind' => 'charlie:x', 'head' => 'charlie'];
        $r = CharlieInboxService::rank([$base + ['key' => 'later', 'due' => '2026-10-20'], $base + ['key' => 'tomorrow', 'due' => '2026-10-07']], [], '2026-10-06');
        $this->assertSame('tomorrow', $r[0]['key']);
        $this->assertEqualsWithDelta(80.0, $r[0]['score'], 0.01);
    }

    public function test_urgent_payment_and_same_day_weather(): void
    {
        $a = CharlieUrgentService::paymentAlert(['id' => 7, 'amount_cents' => 125000, 'status' => 'failed', 'failure_message' => 'Your card was declined.',
                                                'who' => 'Vancouver Management', 'invoice_number' => 'INV-2026-0042', 'invoice_id' => 42]);
        $this->assertSame('urgent:payment:7', $a['key']);
        $this->assertSame('Autopay failed: $1,250.00 from Vancouver Management (invoice INV-2026-0042) — Your card was declined..', $a['text']);
        $w = CharlieUrgentService::weatherAlerts([
            ['kind' => 'weather', 'for_date' => '2026-10-06', 'subject_id' => 5, 'text' => 'Heavy rain at Oak St', 'propose' => ['status' => 'NOT_OK']],
            ['kind' => 'weather', 'for_date' => '2026-10-06', 'subject_id' => 6, 'text' => 'Showers', 'propose' => ['status' => 'MARGINAL']],
            ['kind' => 'weather', 'for_date' => '2026-10-07', 'subject_id' => 7, 'text' => 'Tomorrow', 'propose' => ['status' => 'NOT_OK']],
        ], '2026-10-06');
        $this->assertSame(['urgent:weather:5:2026-10-06'], array_column($w, 'key'));
        $m = CharlieUrgentService::email([$a], 'Tim', 'https://mowology.ca');
        $this->assertStringStartsWith('Needs you now: Autopay failed', $m['subject']);
        $this->assertStringContainsString('href="https://mowology.ca/crm/invoices/view.php?id=42"', $m['body']);
    }

    public function test_bad_news_comes_first_in_the_brief(): void
    {
        $bad = CharlieDeskService::badNews(['sam' => 'down'], [
            ['key' => 'charlie:deadline:payroll_remit:2026-10-01', 'text' => 'Payroll remittance to CRA — overdue since Oct 1'],
            ['key' => 'sam:lead:1', 'text' => 'New lead'],
        ], ['Autopay failed: $900.00']);
        $this->assertSame(['Autopay failed: $900.00', 'Overdue: Payroll remittance to CRA — overdue since Oct 1',
                           "I couldn't reach Sam — their part of this brief is missing."], $bad);
        $this->assertSame('First, the bad news — 3 things:', CharlieVoice::badLead($bad));
        $m = CharlieBriefEmail::render(['one' => null, 'items' => [], 'heads' => [], 'bad' => $bad], 'Tim', 'https://mowology.ca');
        $this->assertLessThan(strpos($m['body'], 'Nothing needs you'), strpos($m['body'], 'Autopay failed'), 'bad news leads the email');
    }
}
