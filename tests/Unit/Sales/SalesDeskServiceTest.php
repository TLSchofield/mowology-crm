<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sam's desk: who is waiting on a follow-up, in what order, and which leads come first.
 */
class SalesDeskServiceTest extends TestCase
{
    private const NOW = '2026-10-20 09:00:00';

    private function quote(int $id, ?int $contact, string $sent, float $amount, array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'quote_number' => 'QUO-2026-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT), 'title' => '', 'service_type' => 'Snow removal',
            'amount' => $amount, 'status' => 'sent', 'sent_at' => $sent, 'viewed_at' => null, 'last_viewed_at' => null, 'email_opened_at' => null,
            'view_count' => 0, 'valid_until' => '2026-11-30', 'follow_up_sent_at' => null, 'follow_up_count' => 0,
            'contact_id' => $contact, 'first_name' => 'Jodi', 'last_name' => 'Peacock', 'email' => 'jodi@example.com', 'phone' => '',
            'company_name' => 'Vancouver Management', 'address' => $id . ' West 42nd Avenue',
        ];
    }

    public function test_win_rate_ignores_quotes_still_open(): void
    {
        $this->assertSame(75, SalesDeskService::winRate(3, 1));
        $this->assertNull(SalesDeskService::winRate(0, 0));
    }

    public function test_a_property_manager_with_many_quotes_gets_one_card_with_the_total(): void
    {
        $q = [$this->quote(1, 7, '2026-10-04 10:00:00', 1024.28), $this->quote(2, 7, '2026-10-04 10:05:00', 555.32), $this->quote(3, 9, '2026-10-04 11:00:00', 815.85, ['first_name' => 'John'])];
        $cards = SalesDeskService::groupStale($q, [], self::NOW, 5);
        $this->assertCount(2, $cards);
        $this->assertSame('c7', $cards[0]['key']);
        $this->assertSame(1579.6, $cards[0]['amount']);
        $this->assertSame('multi', $cards[0]['template']);
        $this->assertSame('2 quotes', $cards[0]['label']);
        $this->assertSame('first_nudge', $cards[1]['template']);
    }

    public function test_a_quote_is_not_due_until_the_wait_has_passed_since_the_last_touch(): void
    {
        $q = [$this->quote(1, 7, '2026-10-17 10:00:00', 500)];
        $this->assertSame([], SalesDeskService::groupStale($q, [], self::NOW, 5));
        $q[0]['sent_at'] = '2026-10-01 10:00:00';
        $q[0]['follow_up_sent_at'] = '2026-10-18 10:00:00';
        $q[0]['follow_up_count'] = 1;
        $this->assertSame([], SalesDeskService::groupStale($q, [], self::NOW, 5), 'a follow-up two days ago resets the clock');
        $q[0]['follow_up_sent_at'] = '2026-10-10 10:00:00';
        $this->assertSame('second_nudge', SalesDeskService::groupStale($q, [], self::NOW, 5)[0]['template']);
    }

    public function test_an_office_email_after_the_quote_also_counts_as_a_touch(): void
    {
        $q = [$this->quote(1, 7, '2026-10-01 10:00:00', 500)];
        $t = [7 => ['last_in' => null, 'last_out' => '2026-10-19 08:00:00', 'last_sam' => null, 'snooze_until' => null, 'thread' => []]];
        $this->assertSame([], SalesDeskService::groupStale($q, $t, self::NOW, 5));
    }

    public function test_a_customer_who_wrote_back_comes_first_even_if_recent_and_even_after_three_follow_ups(): void
    {
        $q = [$this->quote(1, 7, '2026-10-19 10:00:00', 300, ['follow_up_count' => 3]), $this->quote(2, 9, '2026-10-01 10:00:00', 5000)];
        $t = [7 => ['last_in' => '2026-10-19 15:00:00', 'last_out' => null, 'last_sam' => null, 'snooze_until' => null, 'thread' => [['dir' => 'inbound', 'snippet' => 'Can you start Monday?']]]];
        $cards = SalesDeskService::groupStale($q, $t, self::NOW, 5);
        $this->assertSame(['replied', 'stale'], array_column($cards, 'kind'));
        $this->assertSame('reply', $cards[0]['template']);
        $this->assertSame('Can you start Monday?', $cards[0]['thread'][0]['snippet']);
    }

    public function test_three_follow_ups_without_a_reply_is_enough(): void
    {
        $q = [$this->quote(1, 7, '2026-09-01 10:00:00', 500, ['follow_up_count' => 3, 'follow_up_sent_at' => '2026-09-20 10:00:00'])];
        $this->assertSame([], SalesDeskService::groupStale($q, [], self::NOW, 5));
    }

    public function test_snoozed_customers_stay_hidden_until_the_snooze_ends(): void
    {
        $q = [$this->quote(1, 7, '2026-10-01 10:00:00', 500)];
        $t = [7 => ['last_in' => null, 'last_out' => null, 'last_sam' => null, 'snooze_until' => '2026-10-25', 'thread' => []]];
        $this->assertSame([], SalesDeskService::groupStale($q, $t, self::NOW, 5));
        $t[7]['snooze_until'] = '2026-10-20';
        $this->assertCount(1, SalesDeskService::groupStale($q, $t, self::NOW, 5));
    }

    public function test_opened_and_nearly_expired_quotes_get_their_own_note(): void
    {
        $viewed = [$this->quote(1, 7, '2026-10-01 10:00:00', 500, ['viewed_at' => '2026-10-02 10:00:00'])];
        $this->assertSame('viewed', SalesDeskService::groupStale($viewed, [], self::NOW, 5)[0]['template']);
        $ending = [$this->quote(1, 7, '2026-10-01 10:00:00', 500, ['viewed_at' => '2026-10-02 10:00:00', 'valid_until' => '2026-10-23'])];
        $this->assertSame('last_call', SalesDeskService::groupStale($ending, [], self::NOW, 5)[0]['template']);
    }

    public function test_the_wait_is_learned_per_service_from_how_long_yeses_took(): void
    {
        $rows = [];
        foreach ([2, 3, 4, 9, 10] as $d) $rows[] = ['service' => 'hedge', 'days' => $d];
        foreach ([1, 1] as $d) $rows[] = ['service' => 'snow removal', 'days' => $d];
        foreach ([30, 30, 40, 50, 60] as $d) $rows[] = ['service' => 'design', 'days' => $d];
        $this->assertSame(['hedge' => 4, 'design' => 14], SalesDeskService::learnTiming($rows), 'median, clamped 3–14, needs 5 quotes');

        $q = [$this->quote(1, 7, '2026-10-15 10:00:00', 500, ['service_type' => 'Hedge'])];
        $this->assertCount(1, SalesDeskService::groupStale($q, [], self::NOW, 7, ['hedge' => 4]));
        $this->assertSame([], SalesDeskService::groupStale($q, [], self::NOW, 7));
    }

    public function test_leads_rank_by_likely_value_urgency_and_age(): void
    {
        $avg = ['hedge trimming' => 400.0, 'snow removal' => 1500.0, 'all' => 600.0];
        $rows = [
            ['id' => 1, 'service_types' => 'hedge_trimming', 'urgency' => 'inquiring', 'project_description' => '[Classification: tier:low]', 'created_at' => '2026-10-19 09:00:00',
             'first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'a@x.ca', 'phone' => '604 555 0101', 'address' => '1 Oak St', 'city' => 'Vancouver', 'source' => 'website'],
            ['id' => 2, 'service_types' => 'snow_removal', 'urgency' => 'asap', 'project_description' => '[Classification: tier:high]', 'created_at' => '2026-10-19 09:00:00',
             'first_name' => 'Bo', 'last_name' => '', 'email' => '', 'phone' => '604 555 0102', 'address' => '', 'city' => '', 'source' => 'homestars'],
            ['id' => 3, 'service_types' => 'snow_removal', 'urgency' => 'inquiring', 'project_description' => '', 'created_at' => '2026-10-05 09:00:00',
             'first_name' => 'Cy', 'last_name' => '', 'email' => 'c@x.ca', 'phone' => '', 'address' => '', 'city' => '', 'source' => ''],
        ];
        $r = SalesDeskService::rankLeads($rows, $avg, self::NOW);
        $this->assertSame([2, 3, 1], array_column($r, 'id'));
        $this->assertTrue($r[0]['hot']);
        $this->assertStringStartsWith('Call Bo today', $r[0]['next']);
        $this->assertStringStartsWith('Going cold (15 days) — email Cy', $r[1]['next']);
        $this->assertSame('/crm/quotes/create.php?quote_request_id=1', $r[2]['url']);
    }

    public function test_jobflow_tags_are_read_from_the_description(): void
    {
        $d = 'Front yard [Classification: job_type:maintenance tier:high freq:weekly lawn_size:large priority:1]';
        $this->assertSame('high', SalesDeskService::leadTag($d, 'tier'));
        $this->assertSame('large', SalesDeskService::leadTag($d, 'lawn_size'));
        $this->assertNull(SalesDeskService::leadTag('no tags', 'tier'));
    }

    public function test_owner_name_is_a_tidy_first_name(): void
    {
        $this->assertSame('Tim', SalesDeskService::ownerName(['first_name' => 'Tim']));
        $this->assertSame('Tim', SalesDeskService::ownerName(['full_name' => 'TIM Schofield']));
        $this->assertSame('', SalesDeskService::ownerName([]));
    }
}
