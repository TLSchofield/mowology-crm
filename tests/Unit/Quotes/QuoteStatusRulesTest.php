<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Fable review 2026-10-12: a send never undoes the client's answer; accepting needs a live, sent quote. */
class QuoteStatusRulesTest extends TestCase
{
    public function test_resend_keeps_the_answer(): void
    {
        foreach (['draft', 'sent', 'viewed'] as $s) $this->assertSame('sent', QuoteService::statusAfterSend($s));
        foreach (['accepted', 'declined', 'expired'] as $s) $this->assertSame($s, QuoteService::statusAfterSend($s));
    }

    public function test_verbal_yes_only_once_and_not_over_a_decline(): void
    {
        $this->assertTrue(QuoteService::canApproveVerbally('sent'));
        $this->assertTrue(QuoteService::canApproveVerbally('expired'));
        $this->assertFalse(QuoteService::canApproveVerbally('accepted'));
        $this->assertFalse(QuoteService::canApproveVerbally('declined'));
    }

    public function test_online_accept_needs_sent_and_in_date(): void
    {
        $today = new DateTimeImmutable('2026-10-12');
        $this->assertTrue(QuoteService::canAcceptOnline('sent', '2026-10-12', $today));
        $this->assertTrue(QuoteService::canAcceptOnline('viewed', null, $today));
        $this->assertFalse(QuoteService::canAcceptOnline('sent', '2026-10-11', $today));
        foreach (['draft', 'accepted', 'declined', 'expired'] as $s) $this->assertFalse(QuoteService::canAcceptOnline($s, '2027-01-01', $today), $s);
    }

    public function test_quote_text_follows_the_carrier_rules(): void
    {
        $sms = QuoteService::quoteSentSms('QUO-2026-0123');
        $this->assertSame([], SamFollowupService::smsProblems($sms));
        $this->assertStringContainsString('(778) 846-9273', $sms);
        $this->assertStringContainsString('email', $sms);
    }

    public function test_answer_nonce_is_bound_to_quote_and_token(): void
    {
        $k = str_repeat('k', 40);
        $n = QuoteService::acceptNonce(5, 'tok', $k);
        $this->assertNotSame('', $n);
        $this->assertNotSame($n, QuoteService::acceptNonce(6, 'tok', $k));
        $this->assertNotSame($n, QuoteService::acceptNonce(5, 'tok2', $k));
        $this->assertSame('', QuoteService::acceptNonce(5, 'tok', ''));
    }

    public function test_post_accept_note_tells_the_office(): void
    {
        $this->assertStringContainsString('not in the job yet', QuoteApprovalService::postAcceptNote('Aeration', 120.0));
        $this->assertStringContainsString('$120.00', QuoteApprovalService::postAcceptNote('Aeration', 120.0));
        $this->assertStringContainsString('removed', QuoteApprovalService::postAcceptNote('Aeration', 0, true));
    }
}
