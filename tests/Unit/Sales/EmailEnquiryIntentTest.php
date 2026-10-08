<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Comms/FakeImapClient.php';

/**
 * An enquiry is a request FOR Mowology's services — not a vendor pitching TO Mowology and not
 * a payment platform's notice. Fixtures include the two false positives from the 2026-10-07
 * seven-day dry run of mowology@icloud.com.
 */
class EmailEnquiryIntentTest extends TestCase
{
    private const VENDORS = [
        ['id' => 9, 'name' => 'Lawnboy', 'aliases' => 'Lawnboy Supply, LAWNBOY', 'website' => 'https://www.lawnboy.ca/'],
    ];

    /** Live false positive #1: scored STRONG (its own street address sat in the footer). */
    private const GIFTS_SUBJECT = 'Custom engraved gifts for MOWOLOGY';
    private const GIFTS_BODY = "Hi there,\n\nHope your season is going well! We offer custom engraved gifts for your clients and crew — "
        . "branded tumblers, cutting boards and more, perfect for landscaping and lawn care companies. Can you let me know if you'd be "
        . "interested in a quote? We can do a free mockup with your logo.\n\nSean Carne\nSmart Promo\n1200 Marine Drive\nNorth Vancouver, BC\n\n"
        . "If you'd rather not hear from us, reply unsubscribe.";

    /** Live false positive #2: scored weak. Money Tim SENT. */
    private const PAYPAL_SENT_BODY = "Hello Tim Schofield,\nYou sent $84.00 CAD to Acme Tree Care.\nIt may take a few moments for this "
        . "transaction to appear in your account.\nMerchant\nAcme Tree Care\nInstructions to merchant\nPlease quote invoice 1182 for the hedge job.\n"
        . "Transaction ID 7XY12345AB678901C";

    private function ctx(): array
    {
        return ['contacts' => ['jodi@vanmgmt.ca' => 7], 'vendors' => self::VENDORS, 'ours' => ['mowology@icloud.com']];
    }

    private function route(array $m): array
    {
        return IcloudInboxRouter::classify($m + ['folder' => 'inbox', 'to' => 'Tim Schofield <mowology@icloud.com>',
                                                  'headers' => '', 'attachments' => [], 'body' => ''], $this->ctx());
    }

    // ── the live false positives ─────────────────────────────────────────

    public function test_the_engraved_gifts_pitch_is_not_an_enquiry(): void
    {
        $s = EmailLeadService::score(self::GIFTS_SUBJECT, self::GIFTS_BODY, 'seancarne@smartpromo.cc');
        $this->assertNull($s['strength']);
        foreach (['pitch in subject', 'marketing sender', 'custom gifts', 'engraved', 'we offer', 'unsubscribe footer'] as $sig) {
            $this->assertContains($sig, $s['pitch'], $sig);
        }
        $this->assertNull($s['address'], 'a footer address is not their property');
        $this->assertStringContainsString('pitch:', $s['why']);

        // Even from a free-mail address and with no sender at all, the words alone exclude it.
        $this->assertNull(EmailLeadService::score(self::GIFTS_SUBJECT, self::GIFTS_BODY, 'sean.carne@gmail.com')['strength']);
        $this->assertNull(EmailLeadService::score(self::GIFTS_SUBJECT, self::GIFTS_BODY)['strength']);

        $r = $this->route(['from' => 'Sean Carne <seancarne@smartpromo.cc>', 'subject' => self::GIFTS_SUBJECT, 'body' => self::GIFTS_BODY]);
        $this->assertSame('ignore', $r['route']);
        $this->assertTrue($r['pitch']);
        $this->assertStringContainsString('cold sales pitch', $r['reason']);
    }

    public function test_the_pitch_is_decided_from_the_envelope_without_reading_the_body(): void
    {
        $reads = 0;
        $body = function () use (&$reads): string { $reads++; return self::GIFTS_BODY; };
        $t = IcloudInboxRouter::triage(['folder' => 'inbox', 'from' => 'Sean Carne <seancarne@smartpromo.cc>', 'to' => 'mowology@icloud.com',
                                        'subject' => self::GIFTS_SUBJECT], $this->ctx());
        $this->assertSame(['final', 'ignore'], [$t['stage'], $t['route']]);
        // From gmail, the subject alone ("… for MOWOLOGY") still decides it at the envelope.
        $t2 = IcloudInboxRouter::triage(['folder' => 'inbox', 'from' => 'sean.carne@gmail.com', 'to' => 'mowology@icloud.com',
                                         'subject' => self::GIFTS_SUBJECT], $this->ctx());
        $this->assertSame('final', $t2['stage']);
        $this->route(['from' => 'seancarne@smartpromo.cc', 'subject' => self::GIFTS_SUBJECT, 'body' => $body]);
        $this->assertSame(0, $reads);
    }

    public function test_a_paypal_you_sent_a_payment_is_a_receipt_for_the_payee(): void
    {
        $this->assertSame('PayPal', EmailLeadService::paymentPlatform('service@intl.paypal.com'));
        $this->assertSame('sent', EmailLeadService::paymentKind('You sent a payment'));
        $this->assertSame('Acme Tree Care', EmailLeadService::paymentPayee('You sent a payment', self::PAYPAL_SENT_BODY));
        // Never an enquiry, whatever the words (quote, hedge, invoice…).
        $this->assertNull(EmailLeadService::score('You sent a payment', self::PAYPAL_SENT_BODY, 'service@intl.paypal.com')['strength']);

        $r = $this->route(['from' => 'PayPal <service@intl.paypal.com>', 'subject' => 'You sent a payment', 'body' => self::PAYPAL_SENT_BODY]);
        $this->assertSame('receipt', $r['route']);
        $this->assertSame('body', $r['receipt_kind']);
        $this->assertSame('Acme Tree Care', $r['payee']);
        $this->assertNull($r['vendor_id']);
        $this->assertStringContainsString('payee: Acme Tree Care', $r['reason']);

        // A payee who is a known vendor is matched to it.
        $r2 = $this->route(['from' => 'service@paypal.ca', 'subject' => 'Receipt for your payment to Lawnboy Supply',
                            'body' => 'You paid $212.80 CAD to Lawnboy Supply']);
        $this->assertSame(['receipt', 'Lawnboy Supply', 9], [$r2['route'], $r2['payee'], $r2['vendor_id']]);

        // From the envelope: a full read (the body holds the payee), never a header-then-enquiry read.
        $t = IcloudInboxRouter::triage(['folder' => 'Money', 'from' => 'service@intl.paypal.com', 'to' => 'mowology@icloud.com',
                                        'subject' => 'You sent a payment'], $this->ctx());
        $this->assertSame('full', $t['stage']);
        $this->assertSame('payments', IcloudInboxRouter::folderHint('Money'));
    }

    public function test_a_paypal_you_received_a_payment_is_a_payment_notice(): void
    {
        foreach (['You received a payment', "You've got money", 'Jodi Peacock sent you money'] as $subj) {
            $this->assertSame('received', EmailLeadService::paymentKind($subj), $subj);
            $r = $this->route(['from' => 'PayPal <service@paypal.ca>', 'subject' => $subj, 'body' => 'Jodi Peacock sent you $262.50 CAD']);
            $this->assertSame('payment', $r['route'], $subj);
            $this->assertSame('PayPal', $r['platform']);
            $t = IcloudInboxRouter::triage(['folder' => 'inbox', 'from' => 'service@paypal.ca', 'to' => 'mowology@icloud.com', 'subject' => $subj], $this->ctx());
            $this->assertSame(['final', 'payment'], [$t['stage'], $t['route']]);
        }
        // Anything else from a platform is ignored — never an enquiry.
        foreach ([['service@paypal.ca', 'Your account statement is ready'], ['support@stripe.com', 'Can you confirm your lawn care business details?'],
                  ['noreply@squareup.com', 'Do you need a quote for card readers?'], ['hello@wise.com', 'Are you available for a quick survey?'],
                  ['invoices@waveapps.com', 'How much did your lawn business make?']] as [$from, $subj]) {
            $r = $this->route(['from' => $from, 'subject' => $subj, 'body' => 'Can you quote my front lawn at 123 W 4th Ave?']);
            $this->assertSame('ignore', $r['route'], $from);
            $this->assertStringContainsString('payment platform', $r['reason']);
        }
    }

    // ── true enquiries ───────────────────────────────────────────────────

    public function test_true_enquiries_are_still_leads(): void
    {
        $a = EmailLeadService::score('Lawn quote', 'Hi, can you quote my front lawn at 123 W 4th Ave?', 'sarah.chen88@gmail.com');
        $this->assertSame('strong', $a['strength']);
        $this->assertSame('123 W 4th Ave', $a['address']);
        $this->assertSame([], $a['pitch']);
        $this->assertStringContainsString('intent:', $a['why']);
        $this->assertStringContainsString('services: lawn', $a['why']);

        $b = EmailLeadService::score('Hedge', 'Do you do hedge trimming in Kitsilano? How much for a 40 ft hedge?', 'dave.k@outlook.com');
        $this->assertSame('strong', $b['strength']);
        $this->assertContains('hedge', $b['services']);
        $this->assertContains('how much', $b['intent']);

        $c = $this->route([
            'from' => 'Linda Moss <linda.moss@firstservice-residential.ca>',
            'subject' => 'Fall cleanup quote - Strata VR 1234',
            'body' => "Hello,\nI manage the strata at 2150 W 41st Ave. Could you send us a quote for a fall cleanup of the common gardens, "
                    . "including leaf removal? Council would like it done before November.\n\nRegards,\nLinda Moss\nStrata Manager, FirstService Residential",
        ]);
        $this->assertSame('lead', $c['route']);
        $this->assertSame('strong', $c['lead']['strength']);
        $this->assertSame('2150 W 41st Ave', $c['lead']['address']);
        $this->assertStringContainsString('strong enquiry — intent:', $c['reason']);

        // A landlord's "investment property" is not an investment pitch.
        $this->assertSame('strong', EmailLeadService::score('', 'Could you quote weekly mowing for my investment property at 4512 Oak St?', 'a@b.ca')['strength']);
        // "Does your company do snow removal?" is a customer, not a pitch.
        $this->assertSame([], EmailLeadService::score('', 'Does your company do snow removal and salting?')['pitch']);
    }

    // ── cold pitches ─────────────────────────────────────────────────────

    public function test_an_seo_cold_pitch_is_not_an_enquiry(): void
    {
        $body = "Hi Tim,\nI was looking at mowology.ca and noticed you're not on the first page of Google for \"lawn care Vancouver\". "
              . "We help landscaping companies rank higher with SEO and get more leads. Can you spare 15 minutes for a call this week?\n\nMike\nRankBoost";
        $s = EmailLeadService::score('Quick question about your lawn care rankings', $body, 'mike@rankboost.io');
        $this->assertNull($s['strength']);
        $this->assertContains('SEO', $s['pitch']);
        $r = $this->route(['from' => 'Mike <mike@rankboost.io>', 'subject' => 'Quick question about your lawn care rankings', 'body' => $body]);
        $this->assertSame('ignore', $r['route']);
        $this->assertTrue($r['pitch']);
    }

    public function test_a_more_leads_pitch_is_not_an_enquiry(): void
    {
        $body = "Hey there, we can get you more landscaping leads every month — exclusive leads for lawn care companies in Vancouver. "
              . "Do you want 10 free leads to try us out?";
        $s = EmailLeadService::score('Leads for your lawn business', $body, 'jason.r@gmail.com');
        $this->assertNull($s['strength']);
        $this->assertContains('leads for you', $s['pitch']);
        $this->assertTrue(EmailLeadService::marketingDomain('jason@homeprosleads.com'));
        $this->assertFalse(EmailLeadService::marketingDomain('linda.moss@firstservice-residential.ca'));
        $this->assertFalse(EmailLeadService::marketingDomain('sarah@gmail.com'));
        $t = IcloudInboxRouter::triage(['folder' => 'inbox', 'from' => 'jason@homeprosleads.com', 'to' => 'mowology@icloud.com',
                                        'subject' => 'Leads for your lawn business'], $this->ctx());
        $this->assertSame(['final', 'ignore'], [$t['stage'], $t['route']]);
    }

    // ── dry run shows why ────────────────────────────────────────────────

    public function test_dry_run_samples_say_why(): void
    {
        $msgs = [
            1 => ['from' => 'Sean Carne <seancarne@smartpromo.cc>', 'to' => 'mowology@icloud.com', 'subject' => self::GIFTS_SUBJECT],
            2 => ['from' => 'PayPal <service@intl.paypal.com>', 'to' => 'mowology@icloud.com', 'subject' => "You've got money"],
            3 => ['from' => 'Dave K <dave.k@outlook.com>', 'to' => 'mowology@icloud.com', 'subject' => 'Hedge',
                  'header' => "From: dave.k@outlook.com\r\n", 'body' => 'Do you do hedge trimming in Kitsilano? How much for a 40 ft hedge?'],
        ];
        $imap = new FakeImapClient(['INBOX' => ['uidvalidity' => 1, 'messages' => $msgs]]);
        $router = new IcloudInboxRouter(new PDO('sqlite::memory:'), null, ['imap' => $imap, 'clock' => $imap->clock(),
            'mailbox' => ['key' => 'icloud', 'host' => 'h', 'port' => 993, 'user' => 'mowology@icloud.com', 'pass' => 'x'], 'context' => $this->ctx()]);
        $res = $router->poll(true);
        $this->assertSame([1, 1, 1], [$res['counts']['lead'], $res['counts']['payment'], $res['counts']['ignore']]);
        $this->assertStringContainsString('services: hedge', $res['samples']['lead'][0]['why']);
        $this->assertStringContainsString('marketing sender', $res['samples']['pitch'][0]['why']);
        $this->assertSame("You've got money", $res['samples']['payment'][0]['subject']);
        $this->assertSame(['envelope' => 2, 'header' => 0, 'body' => 1], $res['decided_at']);
        $this->assertSame(1, $imap->calls('header'));   // only Dave's
    }
}
