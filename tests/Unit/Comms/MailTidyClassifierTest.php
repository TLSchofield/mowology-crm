<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tidy iCloud: where a senior assistant would file each kind of mail Tim gets, and which
 * Junk messages are really work.
 */
class MailTidyClassifierTest extends TestCase
{
    private const LIST = "List-Unsubscribe: <mailto:unsub@news.example.com>\r\nList-Id: <weekly.example.com>\r\n";

    private function ctx(): array
    {
        return [
            'contacts' => ['jodi@vanmgmt.ca' => 7, 'pat.lee@gmail.com' => 12, 'strata@fsrcorp.com' => 31],
            'vendors' => [
                ['id' => 3, 'name' => 'Lawnboy', 'aliases' => 'Lawnboy Equipment', 'website' => 'lawnboy.ca'],
                ['id' => 4, 'name' => 'Art Knapp', 'aliases' => null, 'website' => 'artknapp.com'],
            ],
            'ours' => ['mowology@icloud.com'],
        ];
    }

    private function to(array $m): array
    {
        return MailTidyClassifier::classify($m + ['to' => 'mowology@icloud.com'], $this->ctx());
    }

    /** @dataProvider fixtures */
    public function test_fixture_lands_in_the_right_folder(array $m, ?string $folder, string $why): void
    {
        $r = $this->to($m);
        $this->assertSame($folder, $r['move'] ? $r['folder'] : null, $why . ' — got ' . json_encode($r));
    }

    public static function fixtures(): array
    {
        return [
            'customer reply'    => [['from' => 'Pat Lee <pat.lee@gmail.com>', 'subject' => 'Re: hedge trimming Thursday?'], 'clients', 'a CRM contact'],
            'PM strata mail'    => [['from' => 'FirstService <strata@fsrcorp.com>', 'subject' => 'Strata council: snow clearing contract'], 'clients', 'PM contact'],
            'Interac'           => [['from' => 'INTERAC e-Transfer <notify@payments.interac.ca>', 'subject' => 'INTERAC e-Transfer: JODI sent you money'], 'Money', 'payment notice'],
            'Yardi EFT'         => [['from' => 'DoNotReply@yardi.com', 'subject' => 'Payment Remittance'], 'Money', 'Yardi'],
            'PayPal sent'       => [['from' => 'service@paypal.ca', 'subject' => 'You sent a payment to Lawn Pros'], 'Money', 'PayPal is Money, never an enquiry'],
            'Wave payroll'      => [['from' => 'Wave <payroll@waveapps.com>', 'subject' => 'Payroll for Oct 1 - Oct 15 is complete'], 'Team &- Payroll', 'Wave'],
            'Vancity statement' => [['from' => 'Vancity <estatements@vancity.com>', 'subject' => 'Your eStatement is ready'], 'Banking &- Tax', 'bank'],
            'CRA'               => [['from' => 'notification@cra-arc.gc.ca', 'subject' => 'You have new mail in My Business Account'], 'Banking &- Tax', 'CRA'],
            'ICBC'              => [['from' => 'ICBC <noreply@icbc.com>', 'subject' => 'Your Autoplan renewal'], 'Insurance &- Vehicles', 'ICBC'],
            'Lawnboy order'     => [['from' => 'Lawnboy Equipment <sales@lawnboy.ca>', 'subject' => 'Your parts are in'], 'Suppliers', 'CRM vendor'],
            'Lawnboy invoice'   => [['from' => 'sales@lawnboy.ca', 'subject' => 'Invoice 48213'], 'RECEIPTS', 'vendor receipt'],
            'Home Depot receipt'=> [['from' => 'The Home Depot <HomeDepotReceipt@homedepot.ca>', 'subject' => 'Your Electronic Receipt'], 'RECEIPTS', 'supplier e-receipt'],
            'Home Depot promo'  => [['from' => 'The Home Depot <news@homedepot.ca>', 'subject' => 'Fall savings on mulch'], 'Suppliers', 'supplier domain wins over promo'],
            'Search Console'    => [['from' => 'Google Search Console <sc-noreply@google.com>', 'subject' => 'New coverage issue detected for mowology.ca'], 'Marketing &- Web', 'GSC'],
            'Arlo alert'        => [['from' => 'Arlo <alerts@arlo.com>', 'subject' => 'Motion detected at Front Door'], 'Arlo', 'Arlo keeps its folder'],
            'Apple receipt'     => [['from' => 'Apple <no_reply@email.apple.com>', 'subject' => 'Your receipt from Apple.'], 'Apple &- Tech', 'Apple keeps its folder'],
            'newsletter'        => [['from' => 'Garden Weekly <hello@gardenweekly.com>', 'subject' => 'Five plants for shade', 'headers' => self::LIST], 'Newsletters &- Promos', 'list mail'],
            'Wing Chun'         => [['from' => 'Sifu Ken <ken@shaw.ca>', 'subject' => 'Wing Chun class moved to Tuesday'], 'Wing Chun', 'personal'],
            'enquiry'           => [['from' => 'Maria Gomez <maria.g@gmail.com>', 'subject' => 'Quote for lawn mowing',
                                     'body' => "Hi, could you quote weekly lawn mowing and hedge trimming at 4512 Oak St? Thanks"], 'Enquiries', 'strong enquiry'],
            'pitch to us'       => [['from' => 'Amy <amy@giftpro.co>', 'subject' => 'Custom engraved gifts for MOWOLOGY',
                                     'body' => 'Can we quote you on custom engraved gifts for your lawn mowing crew? Are you available for a call?'], null, 'a sales pitch is not an enquiry'],
            'pitch via list'    => [['from' => 'Amy <amy@giftpro.co>', 'subject' => 'Custom engraved gifts for MOWOLOGY', 'headers' => self::LIST], 'Newsletters &- Promos', 'list pitch → promos'],
            'CRM system mail'   => [['from' => 'Mowology CRM <noreply@mowology.ca>', 'subject' => 'Cron report: 2 warnings'], 'Mowology CRM', 'CRM robots'],
            'Tim to himself'    => [['from' => 'mowology@icloud.com', 'subject' => 'note to self'], null, 'stays'],
            'unknown person'    => [['from' => 'Bob <bob@shaw.ca>', 'subject' => 'Dinner Saturday?'], null, 'unsure stays'],
            'robot no list'     => [['from' => 'noreply@someapp.io', 'subject' => 'Your verification code'], null, 'unsure notification stays'],
        ];
    }

    public function test_confidence_reason_and_threshold(): void
    {
        $r = $this->to(['from' => 'pat.lee@gmail.com', 'subject' => 'hi']);
        $this->assertSame(['clients', 'clients', 90, 'CRM contact', 'contact', true],
            [$r['key'], $r['folder'], $r['confidence'], $r['reason'], $r['basis'], $r['move']]);

        $plan = MailTidyClassifier::defaultPlan();
        $plan['settings']['min_confidence'] = '80';
        $r = MailTidyClassifier::classify(['from' => 'x@gardenweekly.com', 'subject' => 'News', 'headers' => self::LIST], $this->ctx(), $plan);
        $this->assertSame('newsletters', $r['key']);
        $this->assertFalse($r['move'], '75 < 80 stays put');
    }

    public function test_weak_enquiry_stays_and_body_only_read_for_unknown_people(): void
    {
        $called = 0;
        $body = function () use (&$called): string { $called++; return 'Do you do snow removal?'; };
        $r = $this->to(['from' => 'someone@gmail.com', 'subject' => 'Question', 'body' => $body]);
        $this->assertNull($r['folder']);
        $this->assertSame('enquiry', $r['basis']);
        $this->assertSame(1, $called);
        $this->to(['from' => 'pat.lee@gmail.com', 'subject' => 'Question', 'body' => $body]);
        $this->assertSame(1, $called, 'a contact never has their body read');
    }

    public function test_junk_rescue_client_mail_but_spam_stays(): void
    {
        $ctx = $this->ctx();
        $v = MailTidyClassifier::junkVerdict(['from' => 'Jodi <jodi@vanmgmt.ca>', 'subject' => 'Invoice question'], $ctx);
        $this->assertTrue($v['rescue']);
        $this->assertTrue($v['selected']);

        $v = MailTidyClassifier::junkVerdict(['from' => 'notify@payments.interac.ca', 'subject' => 'INTERAC e-Transfer'], $ctx);
        $this->assertTrue($v['rescue'], 'real Interac sender');

        // Spam: a fake Interac subject from a random domain, a crypto pitch, a newsletter.
        foreach ([
            ['from' => 'alerts@secure-interac-claim.ru', 'subject' => 'INTERAC e-Transfer: claim your deposit'],
            ['from' => 'win@bigprize.biz', 'subject' => 'You won!'],
            ['from' => 'x@gardenweekly.com', 'subject' => 'News', 'headers' => self::LIST],
            ['from' => 'Amy <amy@giftpro.co>', 'subject' => 'Custom engraved gifts for MOWOLOGY'],
        ] as $spam) {
            $this->assertFalse(MailTidyClassifier::junkVerdict($spam, $ctx)['rescue'], json_encode($spam));
        }

        // A bank sender that failed DKIM is spoofed — stays.
        $v = MailTidyClassifier::junkVerdict(['from' => 'alerts@td.com', 'subject' => 'Account locked',
            'headers' => "Authentication-Results: mx.icloud.com; dkim=fail header.d=td.com; spf=softfail\r\n"], $ctx);
        $this->assertFalse($v['rescue']);
        $v = MailTidyClassifier::junkVerdict(['from' => 'alerts@td.com', 'subject' => 'Your statement',
            'headers' => "Authentication-Results: mx.icloud.com; dkim=pass header.d=td.com; spf=pass\r\n"], $ctx);
        $this->assertTrue($v['rescue']);

        // A strong enquiry is rescued but left unticked for Tim to look at.
        $v = MailTidyClassifier::junkVerdict(['from' => 'maria.g@gmail.com', 'subject' => 'Quote for lawn mowing',
            'body' => 'Could you quote weekly lawn mowing and hedge trimming at 4512 Oak St?'], $ctx);
        $this->assertTrue($v['rescue']);
        $this->assertFalse($v['selected']);

        // Personal / software mail in Junk is not "work" — not rescued.
        $this->assertFalse(MailTidyClassifier::junkVerdict(['from' => 'no_reply@email.apple.com', 'subject' => 'Your receipt'], $ctx)['rescue']);
    }

    public function test_rows_build_the_plan_and_existing_folders_are_reused(): void
    {
        $plan = MailTidyClassifier::defaultPlan();
        $this->assertSame('Money', $plan['folders']['payments']['imap']);
        $this->assertSame('Newsletters &- Promos', $plan['folders']['newsletters']['imap']);
        $this->assertSame('clients', $plan['folders']['clients']['imap']);
        foreach (['payments', 'newsletters', 'personal', 'crm', 'clients', 'receipts', 'jobber', 'apple', 'arlo', 'wing_chun', 'london_drugs'] as $k) {
            $this->assertTrue($plan['folders'][$k]['existing'], $k . ' is an existing folder');
        }
        $plan = MailTidyClassifier::fromRows([
            ['rule_type' => 'folder', 'folder_key' => 'clients', 'match_value' => 'clients', 'label' => 'Clients', 'is_existing' => 1],
            ['rule_type' => 'domain', 'folder_key' => 'clients', 'match_value' => 'FSRcorp.com'],
            ['rule_type' => 'domain', 'folder_key' => 'gone', 'match_value' => 'gone.com'],
            ['rule_type' => 'setting', 'folder_key' => 'keep_tidy', 'match_value' => '1'],
            ['rule_type' => 'domain', 'folder_key' => 'clients', 'match_value' => 'off.com', 'is_active' => 0],
        ]);
        $this->assertTrue($plan['folders']['clients']['existing']);
        $this->assertSame(['fsrcorp.com' => 'clients', 'gone.com' => 'gone'], $plan['domain']);
        $this->assertSame('1', $plan['settings']['keep_tidy']);
        $this->assertSame('70', $plan['settings']['min_confidence'], 'defaults fill missing settings');
        $r = MailTidyClassifier::classify(['from' => 'a@gone.com', 'subject' => 'x'], [], $plan);
        $this->assertFalse($r['move'], 'a rule pointing at a folder not in the plan never moves');
    }

    public function test_helpers(): void
    {
        $this->assertTrue(MailTidyClassifier::domainMatches('a@estatements.vancity.com', 'vancity.com'));
        $this->assertFalse(MailTidyClassifier::domainMatches('a@notvancity.com', 'vancity.com'));
        $this->assertSame('Apple & Tech', MailTidyClassifier::displayFolder('Apple &- Tech'));
        $this->assertSame('Banking &- Tax', MailTidyClassifier::imapName('Banking & Tax'));
        $this->assertTrue(MailTidyClassifier::isPitch('Custom engraved gifts for MOWOLOGY', ''));
        $this->assertFalse(MailTidyClassifier::isPitch('Quote for lawn mowing', 'weekly mowing at 4512 Oak St'));
        $this->assertTrue(ImapWriter::isProtected('Sent Messages'));
        $this->assertTrue(ImapWriter::isProtected('Notes/New Folder'));
        $this->assertTrue(ImapWriter::isProtected('Deleted Items'));
        $this->assertTrue(ImapWriter::isProtected('Drafts'));
        $this->assertFalse(ImapWriter::isProtected('Junk'));
        $this->assertFalse(ImapWriter::isProtected('Money'));
    }
}
