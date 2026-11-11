<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * What the owner sees and hears from Charlie: badges earned from what he actually did,
 * the 7 am gate, the kept morning brief, the email and the card's words.
 */
class CharlieBriefOutputTest extends TestCase
{
    private function day(string $date, ?string $top, ?string $first, ?string $opened, int $total = 3, int $done = 0): array
    {
        return ['date' => $date, 'top_key' => $top, 'first_acted_key' => $first, 'opened_time' => $opened, 'total' => $total, 'done_same_day' => $done];
    }

    public function test_called_it_counts_mornings_the_owner_went_first_to_charlies_pick(): void
    {
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime("2026-10-05 -{$i} days"));
            $days[] = $this->day($d, 'a', $i < 5 ? 'a' : 'b', null);
        }
        $b = CharlieBadgeService::compute($days, [], '2026-10-05');
        $this->assertContains('called', array_column($b['earned'], 'key'));
        $this->assertSame(0.71, $b['called_rate']);

        $days[0]['first_acted_key'] = 'b';
        $b = CharlieBadgeService::compute($days, [], '2026-10-05');
        $this->assertNotContains('called', array_column($b['earned'], 'key'), 'lost again when he slips');
    }

    public function test_early_bird_is_a_run_that_today_does_not_break_before_it_is_read(): void
    {
        $days = [$this->day('2026-10-05', 'a', null, null)];
        foreach (['2026-10-02', '2026-10-01', '2026-09-30', '2026-09-29', '2026-09-28'] as $d) $days[] = $this->day($d, 'a', null, '07:40');
        $b = CharlieBadgeService::compute($days, [], '2026-10-05');
        $this->assertContains('early', array_column($b['earned'], 'key'));

        $days[2]['opened_time'] = '08:15';
        $b = CharlieBadgeService::compute($days, [], '2026-10-05');
        $this->assertNotContains('early', array_column($b['earned'], 'key'));
    }

    public function test_clean_sweep_and_tuned_in(): void
    {
        $b = CharlieBadgeService::compute([$this->day('2026-10-02', 'a', 'a', null, 3, 3)], ['a' => 5, 'b' => 9, 'c' => 1], '2026-10-05');
        $this->assertContains('sweep', array_column($b['earned'], 'key'));
        $this->assertNotContains('tuned', array_column($b['earned'], 'key'));
        $this->assertNull(CharlieBadgeService::compute([], [], '2026-10-05')['called_rate']);
        $this->assertNotNull(CharlieBadgeService::compute([], [], '2026-10-05')['next']);
    }

    public function test_the_brief_goes_on_weekday_mornings_only(): void
    {
        $this->assertTrue(CharlieDeskService::isSendTime('2026-10-05 07:02:00'));   // Monday
        $this->assertTrue(CharlieDeskService::isSendTime('2026-10-09 09:59:00'));   // Friday, catching up
        $this->assertFalse(CharlieDeskService::isSendTime('2026-10-05 06:59:00'));
        $this->assertFalse(CharlieDeskService::isSendTime('2026-10-05 10:00:00'));
        $this->assertFalse(CharlieDeskService::isSendTime('2026-10-10 07:02:00'));  // Saturday
        $this->assertFalse(CharlieDeskService::isSendTime('2026-10-11 07:02:00'));  // Sunday
    }

    private function view(): array
    {
        $one = CharlieRankService::normalize(['key' => 'house:overdue_invoice', 'text' => '3 overdue invoices <b>now</b>', 'url' => '/crm/invoices/index.php?status=overdue', 'priority' => 1], 'house') + ['score' => 120.0];
        $two = CharlieRankService::normalize(['key' => 'sam:lead:12', 'text' => 'New lead from HomeStars', 'url' => '/crm/quotes/view.php?id=12'], 'sam') + ['score' => 40.0];
        return [
            'one' => $one, 'rest' => [$two],
            'heads' => [
                'sam'   => ['name' => 'Sam', 'role' => 'Sales', 'headline' => '1 new lead', 'waiting' => 4, 'items' => [$two]],
                'house' => ['name' => 'Everything else', 'role' => 'Work Queue', 'headline' => '1 thing nobody else is watching', 'waiting' => 1, 'items' => [$one]],
                'penny' => ['name' => 'Penny', 'role' => 'Bookkeeper', 'headline' => 'The books are up to date', 'waiting' => 0, 'items' => []],
            ],
        ];
    }

    public function test_the_kept_brief_holds_the_pick_and_each_heads_items(): void
    {
        $p = CharlieDeskService::payload($this->view());
        $this->assertSame('house:overdue_invoice', $p['one']);
        $this->assertSame(['house:overdue_invoice', 'sam:lead:12'], array_column($p['items'], 'key'));
        $this->assertSame(4, $p['heads']['sam']['waiting']);
        $this->assertSame([], $p['heads']['penny']['items']);
    }

    public function test_the_email_leads_with_the_one_thing_escapes_text_and_links_back_absolutely(): void
    {
        $m = CharlieBriefEmail::render(CharlieDeskService::payload($this->view()), 'Tim', 'https://mowology.ca/');
        $this->assertSame("Today's one thing: 3 overdue invoices <b>now</b>", $m['subject']);
        $this->assertStringContainsString('Hey Tim —', $m['body']);
        $this->assertStringContainsString('3 overdue invoices &lt;b&gt;now&lt;/b&gt;', $m['body']);
        $this->assertStringNotContainsString('<b>now</b>', $m['body']);
        $this->assertStringContainsString('href="https://mowology.ca/crm/invoices/index.php?status=overdue"', $m['body']);
        $this->assertStringContainsString('+ 3 more on the dashboard', $m['body']);
        $this->assertSame(1, substr_count($m['body'], '3 overdue invoices'), 'the one thing is not repeated under its head');
        $this->assertStringNotContainsString('!', strip_tags($m['body']), 'no exclamation marks');
    }

    public function test_a_clear_morning_says_so(): void
    {
        $m = CharlieBriefEmail::render(['one' => null, 'items' => [], 'heads' => []], '', 'https://mowology.ca');
        $this->assertSame('Clear morning — nothing needs you today', $m['subject']);
        $this->assertStringContainsString('Hey —', $m['body']);
    }

    public function test_the_card_speaks_plainly(): void
    {
        $s = CharlieVoice::say('Tim', ['text' => 'Call the Smiths'], 4, 3);
        $this->assertSame('Hey Tim — the one thing that needs you today:', $s['lead']);
        $this->assertSame('Call the Smiths', $s['thing']);
        $this->assertSame('After that, 4 more things from 3 of the team — they can wait.', $s['after']);
        $this->assertSame("That's the only thing on the list.", CharlieVoice::say('Tim', ['text' => 'x'], 0, 1)['after']);
        $this->assertStringContainsString('nothing needs you right now', CharlieVoice::say('Tim', null, 0, 0)['lead']);
        $this->assertSame('Tim', CharlieVoice::firstName(['first_name' => 'tim']));
        $this->assertSame('Tim', CharlieVoice::firstName(['full_name' => 'Tim Schofield']));
        $this->assertSame('A long sentence that…', CharlieVoice::short('A long sentence that keeps on going', 22));
    }
}
