<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Support/TestIdentity.php';
$__app = dirname(__DIR__, 3) . '/app';
require_once $__app . '/Services/Messaging/IdentityMail.php';
require_once $__app . '/Services/Messaging/SmsTemplates.php';
require_once $__app . '/Services/Copy/DraftSafety.php';
require_once $__app . '/Modules/Accounting/Services/BankGuidanceService.php';
require_once $__app . '/Modules/Accounting/Services/EtransferNudgeService.php';
require_once $__app . '/Modules/Expenses/Services/ReceiptBookkeeperService.php';
require_once $__app . '/Modules/ChiefOfStaff/Services/CharlieAskService.php';
require_once $__app . '/Modules/Sales/Services/SamFollowupService.php';
require_once $__app . '/Modules/Comms/Services/YuiRules.php';
require_once $__app . '/Modules/Marketing/Services/MiaWording.php';
require_once $__app . '/Modules/Reviews/Services/ReviewRequestService.php';
require_once $__app . '/Modules/Quotes/Services/QuoteService.php';
unset($__app);

/**
 * The same code runs for other businesses (Ittai founding businesses, 2026-10-22): the prompts, texts and
 * sender read BusinessIdentity. For Mowology the words are what they were; for another business nothing
 * says Mowology and no Mowology phone, address or website appears.
 */
class BusinessIdentityCopyTest extends TestCase
{
    protected function tearDown(): void
    {
        BusinessIdentity::reset();
        parent::tearDown();
    }

    /** Everything a business's copy is built from, as one string. */
    private function copy(): string
    {
        $vars = [
            '{first_name}' => 'Jane', '{owner}' => BusinessIdentity::get()['owner_name'] ?: 'the owner',
            '{phone}' => SamFollowupService::officePhone(), '{business}' => SamFollowupService::businessName(),
            '{service}' => 'lawn care', '{place}' => '1 Main St', '{total}' => '$100', '{sent_date}' => 'Oct 1',
            '{valid_until}' => 'Oct 31', '{count}' => '2', '{quote_list}' => '', '{delay_line}' => '',
        ];
        $out = [
            BankGuidanceService::systemPrompt(),
            ReceiptBookkeeperService::systemPrompt(),
            CharlieAskService::systemPrompt(),
            buildQuoteNotificationSmsText('QUO-1'),
            ReviewRequestService::smsText('Jane'),
            QuoteService::quoteSentSms('QUO-1'),
            MiaWording::sms('Jane', 'your lawn'),
            implode(' ', MiaWording::defaults('pm_quiet')),
            EtransferNudgeService::render('Jane', 10.0, null, false)['text'],
            EtransferNudgeService::autodepositAddress(),
            implode(' ', DraftSafety::own()),
        ];
        foreach (SamFollowupService::TEMPLATES as $t) {
            $out[] = SamFollowupService::fill(implode(' ', $t), $vars);
        }
        foreach (YuiRules::TEMPLATES as $t) {
            $out[] = SamFollowupService::fill(implode(' ', array_map('strval', $t)), $vars);
        }
        $out[] = SamFollowupService::fill(YuiRules::SMS_FALLBACK, $vars);
        return implode("\n", $out);
    }

    public function test_mowologys_own_copy_is_what_it_was(): void
    {
        TestIdentity::mowology();
        $all = $this->copy();
        $this->assertStringContainsString('You are Penny, the bookkeeper for Mowology, a 3-person landscaping and property-maintenance business in Vancouver, BC (owner: Tim).', BankGuidanceService::systemPrompt());
        $this->assertStringContainsString('You are the bookkeeper for Mowology, a 3-person landscaping and property-maintenance business in Vancouver, BC (owner: Tim).', ReceiptBookkeeperService::systemPrompt());
        $this->assertStringContainsString('Chief of Staff for Mowology, a 3-person landscaping and property-maintenance business in Vancouver, BC (owner: Tim).', CharlieAskService::systemPrompt());
        $this->assertSame('Mowology: Your quote QUO-1 is ready. Check your email to view and accept it. Questions? Call (778) 846-9273.', buildQuoteNotificationSmsText('QUO-1'));
        $this->assertSame('Mowology: your quote QUO-1 is ready. Check your email (and junk folder). Questions? Call (778) 846-9273. Reply STOP to opt out.', QuoteService::quoteSentSms('QUO-1'));
        $this->assertSame('Hi Jane, thanks for having Mowology out. Check your email for a link to leave a Google review. Questions? Call (778) 846-9273', ReviewRequestService::smsText('Jane'));
        $this->assertSame('office@mowology.ca', EtransferNudgeService::autodepositAddress());
        $this->assertStringContainsString("Thank you,\nPenny\nMowology Landscaping\n(778) 846-9273", EtransferNudgeService::render('Jane', 10.0, null, false)['text']);
        $this->assertSame(['mowology.ca', 'office@mowology.ca', '778-846-9273', '(778) 846-9273', '7788469273'], DraftSafety::own());
        $this->assertSame([], DraftSafety::flags('Call (778) 846-9273 or see mowology.ca/quote or email office@mowology.ca'));
        $this->assertStringContainsString("it's Tim at Mowology", $all);
        $this->assertStringContainsString('Thanks,' . "\n" . 'Tim', implode(' ', MiaWording::defaults('pm_quiet')));
        $this->assertSame('Hi Jane, Tim from Mowology here. I sent you an email about your lawn. Check your email, or call (778) 846-9273.', MiaWording::sms('Jane', 'your lawn'));
        $this->assertSame('no-reply@mowology.ca', _idFromEmail());
        $this->assertSame('office@mowology.ca', _idReplyTo());
        $this->assertSame('Mowology', _idFromName());
        $this->assertSame('Mowology', _idFromName('  '));
        $this->assertSame('Custom', _idFromName('Custom'));
    }

    public function test_another_business_never_sees_mowology(): void
    {
        TestIdentity::greenleaf();
        $all = $this->copy();
        $this->assertStringNotContainsStringIgnoringCase('mowology', $all);
        $this->assertStringNotContainsString('778', $all);
        $this->assertStringNotContainsString('846-9273', $all);
        $this->assertStringContainsString('Greenleaf, a 2-person garden-care business in Burnaby, BC (owner: Claudia)', BankGuidanceService::systemPrompt());
        $this->assertStringContainsString('Greenleaf, a 2-person garden-care business in Burnaby, BC (owner: Claudia)', ReceiptBookkeeperService::systemPrompt());
        $this->assertStringContainsString('hello@greenleaf.ca', EtransferNudgeService::autodepositAddress());
        $this->assertSame('Greenleaf: Your quote QUO-1 is ready. Check your email to view and accept it. Questions? Call (604) 555-0142.', buildQuoteNotificationSmsText('QUO-1'));
        $this->assertSame(['greenleaf.ca', 'hello@greenleaf.ca', '604-555-0142', '(604) 555-0142', '6045550142'], DraftSafety::own());
        $this->assertSame('no-reply@greenleaf.ca', _idFromEmail());
        $this->assertSame('hello@greenleaf.ca', _idReplyTo());
        $this->assertSame('Greenleaf', _idFromName());
        // Mowology's address is no longer "ours" for this business: a draft carrying it is flagged.
        $this->assertArrayHasKey('email', DraftSafety::flags('write to office@mowology.ca'));
        $this->assertSame([], DraftSafety::flags('write to hello@greenleaf.ca or see greenleaf.ca/quote'));
    }

    public function test_a_business_with_no_phone_gets_texts_without_one(): void
    {
        TestIdentity::set(BusinessIdentity::from([], ['entered_name' => 'Acme'], [], []));
        $sms = buildQuoteNotificationSmsText('QUO-1');
        $this->assertSame('Acme: Your quote QUO-1 is ready. Check your email to view and accept it.', $sms);
        $this->assertSame('', _idSmsTail());
    }
}
