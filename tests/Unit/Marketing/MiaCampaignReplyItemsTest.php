<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Campaign replies become Mia's brief items: one per person who answered, gone once a
 * quote for them exists that was made after the reply.
 */
class MiaCampaignReplyItemsTest extends TestCase
{
    private const CAMPAIGN = ['id' => 1, 'name' => 'Post-drought lawn recovery (2026)', 'label' => 'the fall lawn email'];

    private function recipients(): array
    {
        return [
            ['contact_id' => 11, 'status' => 'sent', 'sent_at' => '2026-10-06 08:00:00'],
            ['contact_id' => 12, 'status' => 'sent', 'sent_at' => '2026-10-06 08:00:00'],
            ['contact_id' => 13, 'status' => 'sent', 'sent_at' => '2026-10-06 08:00:00'],
            ['contact_id' => 14, 'status' => 'skipped', 'sent_at' => null],
        ];
    }

    private function people(): array
    {
        return [
            11 => ['first_name' => 'Colleen', 'property_id' => 501],
            12 => ['first_name' => 'raj', 'property_id' => null],
            13 => ['first_name' => 'deb@example.com', 'property_id' => 503],
            14 => ['first_name' => 'Skip', 'property_id' => 504],
        ];
    }

    public function test_each_reply_becomes_one_high_priority_item_on_the_brief_contract(): void
    {
        $replies = [
            ['contact_id' => 11, 'sent_at' => '2026-10-06 10:00:00', 'snippet' => 'Yes please do it'],
            ['contact_id' => 11, 'sent_at' => '2026-10-07 09:00:00', 'snippet' => 'Also the side yard'],
            ['contact_id' => 12, 'sent_at' => '2026-10-08 12:00:00', 'snippet' => 'Please do it in the Spring'],
        ];
        $items = MiaCampaignService::replyBriefItems(self::CAMPAIGN, $this->recipients(), $replies, [], $this->people());

        $this->assertCount(2, $items);
        $c = $items[0];
        $this->assertSame('mia:campaign_reply:1:11', $c['key']);
        $this->assertSame('campaign_reply', $c['kind']);
        $this->assertSame(1, $c['priority']);
        $this->assertSame('2026-10-06', $c['since']);          // the first reply
        $this->assertSame('Colleen replied to the fall lawn email. Start the quote.', $c['text']);
        $this->assertSame('/crm/quotes/create.php?contact_id=11&property_id=501', $c['url']);

        $r = $items[1];
        $this->assertSame('Raj replied to the fall lawn email and wants it done in spring. Start the quote.', $r['text']);
        $this->assertSame('/crm/clients_appstack.php?action=view_contact&id=12', $r['url'], 'no property → the contact page');

        // Charlie's normalizer accepts it as-is.
        $n = CharlieRankService::normalize($c, 'mia');
        $this->assertSame('mia:campaign_reply', $n['kind']);
        $this->assertSame(1, $n['priority']);
        $this->assertSame('2026-10-06', $n['since']);
    }

    public function test_a_quote_made_after_the_reply_clears_it_by_contact_or_site_contact(): void
    {
        $replies = [
            ['contact_id' => 11, 'sent_at' => '2026-10-06 10:00:00', 'snippet' => 'yes'],
            ['contact_id' => 12, 'sent_at' => '2026-10-06 10:00:00', 'snippet' => 'yes'],
            ['contact_id' => 13, 'sent_at' => '2026-10-07 10:00:00', 'snippet' => 'yes'],
        ];
        $quotes = [
            ['contact_id' => 11, 'site_contact_id' => null, 'created_at' => '2026-10-06 11:00:00'],   // after → cleared
            ['contact_id' => null, 'site_contact_id' => 12, 'created_at' => '2026-10-07 09:00:00'],  // property's site contact → cleared
            ['contact_id' => 13, 'site_contact_id' => 13, 'created_at' => '2026-10-06 09:00:00'],   // before the reply → still waiting
        ];
        $items = MiaCampaignService::replyBriefItems(self::CAMPAIGN, $this->recipients(), $replies, $quotes, $this->people());
        $this->assertSame(['mia:campaign_reply:1:13'], array_column($items, 'key'));
    }

    public function test_never_shows_an_email_and_ignores_non_recipients_and_late_or_early_replies(): void
    {
        $replies = [
            ['contact_id' => 13, 'sent_at' => '2026-10-09 10:00:00', 'snippet' => 'Contact me at deb@example.com'],
            ['contact_id' => 14, 'sent_at' => '2026-10-09 10:00:00', 'snippet' => 'yes'],   // never sent (skipped)
            ['contact_id' => 99, 'sent_at' => '2026-10-09 10:00:00', 'snippet' => 'yes'],   // not on the list
            ['contact_id' => 11, 'sent_at' => '2026-10-05 10:00:00', 'snippet' => 'yes'],   // before the send
            ['contact_id' => 12, 'sent_at' => '2026-12-20 10:00:00', 'snippet' => 'yes'],   // past 30 days
        ];
        $items = MiaCampaignService::replyBriefItems(self::CAMPAIGN, $this->recipients(), $replies, [], $this->people());
        $this->assertCount(1, $items);
        $this->assertSame('Someone replied to the fall lawn email. Start the quote.', $items[0]['text']);
        $this->assertStringNotContainsString('@', $items[0]['text']);
        $this->assertStringNotContainsString('!', $items[0]['text']);
    }

    public function test_label_falls_back_to_the_campaign_name(): void
    {
        $items = MiaCampaignService::replyBriefItems(['id' => 7, 'name' => 'Spring cleanup (2027)', 'label' => null],
            [['contact_id' => 11, 'status' => 'sent', 'sent_at' => '2027-03-01 08:00:00']],
            [['contact_id' => 11, 'sent_at' => '2027-03-02 08:00:00', 'snippet' => 'ok']], [], $this->people());
        $this->assertSame('Colleen replied to the Spring cleanup email. Start the quote.', $items[0]['text']);
    }

    public function test_catalogue_labels_the_fall_campaign(): void
    {
        $this->assertSame('the fall lawn email', MiaCampaignService::catalogue(2026)['post_drought_2026']['label']);
    }
}
