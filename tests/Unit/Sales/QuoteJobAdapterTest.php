<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Sam's quote strip (2026-11-10): which quotes count as gone quiet, what the buttons carry, how sure he looks. */
class QuoteJobAdapterTest extends TestCase
{
    private function row(array $o = []): array
    {
        return $o + ['id' => 93, 'quote_number' => 'QUO-2026-0093', 'title' => 'Irrigation shutdown', 'amount' => 1260.0, 'sent_at' => '2026-10-15 11:06:00',
            'email_opened_at' => '2026-10-15 11:13:00', 'view_count' => 9, 'follow_up_sent_at' => null, 'follow_up_count' => 0,
            'first_name' => 'Linda', 'last_name' => 'N', 'company_name' => '', 'address' => '1685 West 14th', 'contact_id' => 5];
    }

    private function now(): DateTimeImmutable { return new DateTimeImmutable('2026-11-10 09:00:00'); }

    public function test_a_quote_sent_long_ago_is_quiet_and_carries_decline_through_the_one_endpoint(): void
    {
        $jobs = QuoteJobAdapter::queue([$this->row()], [], [], [0, 0], $this->now());
        $this->assertCount(1, $jobs);
        $j = $jobs[0];
        $this->assertSame('quiet:93', $j['key']);
        $this->assertSame('/crm/quotes/view.php?id=93', $j['primary']['href']);
        $this->assertSame('/crm/quotes/api-quotes.php?action=decline', $j['secondary']['request']['url']);
        $this->assertSame(['id' => 93], $j['secondary']['request']['body']);
        $this->assertTrue($j['secondary']['withFields']);
        $this->assertSame(['lost_reason'], $j['fields']);
        $this->assertTrue($j['later']);
        $this->assertSame('forming', $j['sure']);
    }

    public function test_recent_follow_ups_replies_snoozes_and_contracts_keep_a_quote_out(): void
    {
        $now = $this->now();
        $this->assertSame([], QuoteJobAdapter::queue([$this->row(['sent_at' => '2026-11-06 10:00:00'])], [], [], [0, 0], $now), 'sent 4 days ago');
        $this->assertSame([], QuoteJobAdapter::queue([$this->row(['follow_up_sent_at' => '2026-11-08 10:00:00'])], [], [], [0, 0], $now), 'followed up 2 days ago');
        $this->assertSame([], QuoteJobAdapter::queue([$this->row()], [5 => ['last_in' => '2026-11-01 10:00:00']], [], [0, 0], $now), 'they wrote back');
        $this->assertSame([], QuoteJobAdapter::queue([$this->row()], [5 => ['snooze_until' => '2026-11-20 00:00:00']], [], [0, 0], $now), 'Sam desk snooze');
        $this->assertSame([], QuoteJobAdapter::queue([$this->row()], [], [93], [0, 0], $now), 'a contract is never nudged or declined from here');
        $this->assertCount(1, QuoteJobAdapter::queue([$this->row()], [5 => ['last_in' => '2026-10-01 10:00:00']], [], [0, 0], $now), 'mail from before the send is not a reply');
    }

    public function test_oldest_first_and_the_quote_being_viewed_first(): void
    {
        $rows = [$this->row(['id' => 1, 'quote_number' => 'A', 'sent_at' => '2026-10-20 10:00:00']), $this->row(['id' => 2, 'quote_number' => 'B', 'sent_at' => '2026-10-01 10:00:00'])];
        $this->assertSame([2, 1], array_column(QuoteJobAdapter::queue($rows, [], [], [0, 0], $this->now()), 'quote_id'));
        $this->assertSame([1, 2], array_column(QuoteJobAdapter::queue($rows, [], [], [0, 0], $this->now(), 1), 'quote_id'));
    }

    public function test_the_triangle_is_earned_by_record(): void
    {
        $this->assertSame('forming', QuoteJobAdapter::sure([0, 0]));
        $this->assertSame('held', QuoteJobAdapter::sure([1, 1]));
        $this->assertSame('held', QuoteJobAdapter::sure([3, 2]), 'one was undone');
        $this->assertSame('known', QuoteJobAdapter::sure([2, 2]));
        $this->assertSame('known', QuoteJobAdapter::sure([4, 4]));
        $this->assertSame('mastered', QuoteJobAdapter::sure([5, 5]));
    }

    public function test_the_strip_is_off_without_the_switch(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT)");
        $this->assertFalse(QuoteJobAdapter::enabled($db));
        $this->assertSame([], QuoteJobAdapter::jobs($db));
        $db->exec("INSERT INTO ops_settings VALUES ('it_quote_job', '1')");
        $this->assertTrue(QuoteJobAdapter::enabled($db));
    }
}
