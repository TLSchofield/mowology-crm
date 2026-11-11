<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * What a campaign earned: each person counted once, in their best outcome, within 30 days
 * of their own email — bookings first, opens last.
 */
class MiaCampaignResultsTest extends TestCase
{
    private function recipients(): array
    {
        return [
            ['contact_id' => 1, 'status' => 'sent', 'sent_at' => '2026-10-06 08:00:00', 'opened_at' => '2026-10-06 09:00:00'],
            ['contact_id' => 2, 'status' => 'sent', 'sent_at' => '2026-10-06 08:15:00', 'opened_at' => null],
            ['contact_id' => 3, 'status' => 'sent', 'sent_at' => '2026-10-06 08:30:00', 'opened_at' => '2026-10-07 10:00:00'],
            ['contact_id' => 4, 'status' => 'skipped', 'sent_at' => null, 'opened_at' => null],
            ['contact_id' => 5, 'status' => 'sent', 'sent_at' => '2026-10-06 08:30:00', 'opened_at' => null],
        ];
    }

    public function test_bookings_quotes_replies_and_spring_holds_each_count_a_person_once(): void
    {
        $quotes = [
            ['contact_id' => 1, 'status' => 'accepted', 'created_at' => '2026-10-08 10:00:00', 'amount' => 285.00],
            ['contact_id' => 1, 'status' => 'sent',     'created_at' => '2026-10-09 10:00:00', 'amount' => 90.00],   // already booked
            ['contact_id' => 2, 'status' => 'sent',     'created_at' => '2026-10-09 10:00:00', 'amount' => 180.00],
            ['contact_id' => 3, 'status' => 'accepted', 'created_at' => '2026-12-01 10:00:00', 'amount' => 999.00],  // after 30 days
            ['contact_id' => 9, 'status' => 'accepted', 'created_at' => '2026-10-08 10:00:00', 'amount' => 500.00],  // never sent to
        ];
        $plans = [['contact_id' => 5, 'created_at' => '2026-10-20 10:00:00']];
        $replies = [
            ['contact_id' => 1, 'sent_at' => '2026-10-06 12:00:00'],
            ['contact_id' => 3, 'sent_at' => '2026-10-07 12:00:00'],
            ['contact_id' => 3, 'sent_at' => '2026-10-08 12:00:00'],
            ['contact_id' => 2, 'sent_at' => '2026-10-01 12:00:00'],  // before the campaign
        ];
        $r = MiaCampaignService::tally($this->recipients(), $quotes, $plans, $replies, [3, 3, 9]);
        $this->assertSame(4, $r['sent'], 'skipped people are not counted as sent');
        $this->assertSame(2, $r['booked'], 'contact 1 (accepted quote) and 5 (plan started)');
        $this->assertSame(285.0, $r['booked_amount']);
        $this->assertSame(1, $r['quoted'], 'contact 2 — contact 1 already counts as booked');
        $this->assertSame(2, $r['replied'], 'contacts 1 and 3, once each; contact 2 wrote before the email');
        $this->assertSame(1, $r['spring_holds'], 'contact 3 once; contact 9 was never sent the campaign');
        $this->assertSame(2, $r['opened']);
    }

    public function test_nothing_sent_yet_reads_as_zeros(): void
    {
        $r = MiaCampaignService::tally([['contact_id' => 1, 'status' => 'pending', 'sent_at' => null, 'opened_at' => null]], [], [], [], []);
        $this->assertSame(0, $r['sent']);
        $this->assertSame(0, $r['booked']);
    }
}
