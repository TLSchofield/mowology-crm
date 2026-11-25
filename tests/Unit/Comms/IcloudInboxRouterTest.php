<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tim's iCloud: every message goes to exactly one place, and personal mail goes nowhere.
 * Fixtures are shaped like the real headers each sender uses.
 */
class IcloudInboxRouterTest extends TestCase
{
    private const VENDORS = [
        ['id' => 1, 'name' => 'Home Depot', 'aliases' => 'HD, Home Depot, HomeDepot, THE HOME DEPOT', 'website' => null],
        ['id' => 4, 'name' => 'Shell', 'aliases' => 'Shell, SHELL CANADA', 'website' => null],
        ['id' => 9, 'name' => 'Lawnboy', 'aliases' => 'Lawnboy Supply, LAWNBOY', 'website' => 'https://www.lawnboy.ca/'],
    ];

    private function ctx(): array
    {
        return [
            'contacts' => ['jodi@vanmgmt.ca' => 7, 'ron.p@telus.net' => 9],
            'vendors'  => self::VENDORS,
            'ours'     => ['mowology@icloud.com'],
        ];
    }

    private function route(array $m): array
    {
        return IcloudInboxRouter::classify($m + ['folder' => 'inbox', 'to' => 'Tim Schofield <mowology@icloud.com>',
                                                  'headers' => '', 'attachments' => [], 'body' => ''], $this->ctx());
    }

    public function test_a_customer_reply_goes_to_the_conversation_history(): void
    {
        $r = $this->route([
            'from' => '"Jodi Peacock" <Jodi@VanMgmt.ca>',
            'subject' => 'Re: Your quote QUO-2026-0142 from Mowology',
            'body' => "Hi Tim, yes please go ahead with the fall cleanup.\n\nOn Mon, Oct 5, 2026 at 9:00 AM Tim <mowology@icloud.com> wrote:\n> Hi Jodi",
        ]);
        $this->assertSame('contact', $r['route']);
        $this->assertSame('inbound', $r['direction']);
        $this->assertSame(7, $r['contact_id']);
    }

    public function test_tims_own_sent_mail_to_a_customer_is_outbound_history(): void
    {
        $r = $this->route(['folder' => 'sent', 'from' => 'Tim Schofield <mowology@icloud.com>', 'to' => 'Ron <ron.p@telus.net>',
                           'subject' => 'Hedge trimming next week']);
        $this->assertSame('contact', $r['route']);
        $this->assertSame('outbound', $r['direction']);
        $this->assertSame(9, $r['contact_id']);
    }

    public function test_an_interac_notice_goes_to_the_payment_parser(): void
    {
        $r = $this->route([
            'from' => 'Interac e-Transfer <notify@payments.interac.ca>',
            'subject' => 'INTERAC e-Transfer: JODI PEACOCK sent you money.',
            'headers' => "List-Unsubscribe: <mailto:x@interac.ca>\r\n",
        ]);
        $this->assertSame('interac', $r['route']);

        // Forwarded by Tim — the same subject rule the office@ poller searches with.
        $fwd = $this->route(['from' => 'mowology@icloud.com', 'subject' => 'Fwd: Interac e-Transfer: RON P sent you $262.50 (CAD)']);
        $this->assertSame('interac', $fwd['route']);
    }

    public function test_a_yardi_remittance_goes_to_the_yardi_parser(): void
    {
        $r = $this->route(['from' => 'DoNotReply@yardi.com', 'subject' => 'EFT Payment - Tribe Management']);
        $this->assertSame('yardi', $r['route']);
    }

    public function test_a_home_depot_e_receipt_is_a_receipt_read_from_the_email_itself(): void
    {
        $r = $this->route([
            'from' => '"The Home Depot" <HomeDepotReceipt@homedepot.ca>',
            'subject' => 'Your Electronic Receipt',
            'headers' => "List-Unsubscribe: <https://homedepot.ca/unsub>\r\n",
        ]);
        $this->assertSame('receipt', $r['route']);
        $this->assertSame('body', $r['receipt_kind']);
        $this->assertSame(1, $r['vendor_id']);
    }

    public function test_a_lawnboy_order_with_its_invoice_pdf_is_a_receipt(): void
    {
        $r = $this->route([
            'from' => 'Lawnboy Orders <orders@lawnboy.ca>',
            'subject' => 'Order #48213 confirmed',
            'attachments' => [['filename' => 'Invoice-48213.pdf', 'mime' => 'application/pdf']],
        ]);
        $this->assertSame('receipt', $r['route']);
        $this->assertSame('attachment', $r['receipt_kind']);
        $this->assertSame(9, $r['vendor_id']);
    }

    public function test_other_mail_from_a_vendor_is_vendor_history(): void
    {
        $r = $this->route([
            'from' => 'Mike at Lawnboy <mike@lawnboy.ca>',
            'subject' => 'Re: Thursday delivery',
            'body' => 'We can drop the 10 yards of mulch Thursday morning if that works.',
        ]);
        $this->assertSame('vendor', $r['route']);
        $this->assertSame(9, $r['vendor_id']);
        $this->assertSame('inbound', $r['direction']);
    }

    public function test_a_new_enquiry_with_an_address_is_a_strong_lead(): void
    {
        $r = $this->route([
            'from' => 'Sarah Chen <sarah.chen88@gmail.com>',
            'subject' => 'Lawn',
            'body' => "Hi there,\nCan you quote my front lawn at 123 W 4th? It needs mowing every two weeks.\nThanks, Sarah",
        ]);
        $this->assertSame('lead', $r['route']);
        $this->assertSame('strong', $r['lead']['strength']);
        $this->assertSame('123 W 4th', $r['lead']['address']);
        $this->assertContains('lawn', $r['lead']['services']);
    }

    public function test_a_vague_question_is_only_a_maybe(): void
    {
        $r = $this->route(['from' => 'dave.k@outlook.com', 'subject' => 'question', 'body' => 'Hey, do you do hedges?']);
        $this->assertSame('lead', $r['route']);
        $this->assertSame('weak', $r['lead']['strength']);
    }

    public function test_a_newsletter_is_ignored_even_when_it_talks_lawns_and_quotes(): void
    {
        $r = $this->route([
            'from' => 'Lawn & Garden Weekly <hello@lawngardenweekly.com>',
            'subject' => 'Get a quote on spring aeration — 20% off lawn care',
            'headers' => "List-Unsubscribe: <mailto:unsub@lawngardenweekly.com>\r\nPrecedence: bulk\r\n",
            'body' => 'How much does aeration cost? Get a quote for your lawn and garden today.',
        ]);
        $this->assertSame('ignore', $r['route']);
    }

    public function test_personal_mail_is_ignored(): void
    {
        $r = $this->route([
            'from' => 'Mum <margaret.s@shaw.ca>',
            'subject' => 'Sunday dinner',
            'body' => "Are you available for dinner on Sunday? Dad is making a roast.\nLove, Mum",
        ]);
        $this->assertSame('ignore', $r['route']);
        // A personal photo without receipt words is not a receipt either.
        $photo = $this->route(['from' => 'margaret.s@shaw.ca', 'subject' => 'Look at the grandkids',
                               'attachments' => [['filename' => 'IMG_2231.jpeg', 'mime' => 'image/jpeg']]]);
        $this->assertSame('ignore', $photo['route']);
    }

    public function test_the_body_is_only_read_for_unknown_senders(): void
    {
        $reads = 0;
        $body = function () use (&$reads): string { $reads++; return 'nothing here'; };
        $this->route(['from' => 'notify@payments.interac.ca', 'subject' => 'INTERAC e-Transfer: X sent you money.', 'body' => $body]);
        $this->route(['from' => 'jodi@vanmgmt.ca', 'subject' => 'Re: quote', 'body' => $body]);
        $this->assertSame(0, $reads);
        $this->route(['from' => 'stranger@example.org', 'subject' => 'hello', 'body' => $body]);
        $this->assertSame(1, $reads);
    }

    public function test_message_key_is_the_message_id_or_a_mailbox_free_hash(): void
    {
        $this->assertSame('<abc@mail.gmail.com>', IcloudInboxRouter::messageKey(' <abc@mail.gmail.com> ', 'a@b.ca', 'c@d.ca', 'Hi', 'Tue, 6 Oct 2026 10:00:00 -0700'));
        $a = IcloudInboxRouter::messageKey(null, 'A@b.ca', 'c@d.ca', 'Hi', 'Tue, 6 Oct 2026 10:00:00 -0700');
        $b = IcloudInboxRouter::messageKey('', 'a@b.ca', 'C@D.ca', 'Hi', 'Tue, 6 Oct 2026 17:00:00 +0000');
        $this->assertSame($a, $b);   // same email, same key, whichever mailbox read it
        $this->assertStringStartsWith('h-', $a);
    }

    public function test_only_new_uids_are_read_and_a_new_uidvalidity_starts_over(): void
    {
        $state = ['uid_validity' => 555, 'last_uid' => 120];
        $this->assertSame([121, 130], IcloudInboxRouter::newUids([130, 118, 120, 121], $state, 555));
        $this->assertSame([118, 120, 121, 130], IcloudInboxRouter::newUids([130, 118, 120, 121], $state, 777));
        $this->assertSame([5, 9], IcloudInboxRouter::newUids([9, 5], null, 555));
    }

    public function test_inert_until_both_icloud_constants_exist(): void
    {
        $this->assertStringContainsString('not configured', IcloudInboxRouter::notConfigured([]));
        $this->assertStringContainsString('ICLOUD_IMAP_PASS', IcloudInboxRouter::notConfigured(['ICLOUD_IMAP_USER' => 'mowology@icloud.com']));
        $this->assertSame('', IcloudInboxRouter::notConfigured(['ICLOUD_IMAP_USER' => 'mowology@icloud.com', 'ICLOUD_IMAP_PASS' => 'abcd-efgh-ijkl-mnop']));
    }

    public function test_summary_is_counts_only(): void
    {
        $c = array_fill_keys(IcloudInboxRouter::ROUTES, 1) + ['stored' => 2, 'dupe' => 0, 'held' => 0, 'leads_made' => 1, 'maybe_leads' => 0];
        $s = IcloudInboxRouter::summary($c);
        $this->assertStringContainsString('1 customer', $s);
        $this->assertStringContainsString('1 ignored', $s);
    }
}
