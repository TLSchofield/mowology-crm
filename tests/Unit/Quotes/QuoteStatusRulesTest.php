<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Support/TestIdentity.php';

/** Fable review 2026-10-12: a send never undoes the client's answer; accepting needs a live, sent quote. */
class QuoteStatusRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestIdentity::mowology();
    }

    protected function tearDown(): void
    {
        BusinessIdentity::reset();
        parent::tearDown();
    }

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

    public function test_owner_declines_only_an_unanswered_quote_and_only_a_decline_reopens(): void
    {
        foreach (['sent', 'viewed'] as $st) $this->assertTrue(QuoteService::canDecline($st), $st);
        foreach (['draft', 'accepted', 'declined', 'expired'] as $st) $this->assertFalse(QuoteService::canDecline($st), $st);
        $this->assertTrue(QuoteService::canReopen('declined'));
        foreach (['draft', 'sent', 'viewed', 'accepted', 'expired'] as $st) $this->assertFalse(QuoteService::canReopen($st), $st);
    }

    public function test_loss_reasons_are_the_five_the_owner_picks(): void
    {
        $this->assertSame(['price', 'timing', 'went_elsewhere', 'no_response', 'other'], array_keys(QuoteService::LOST_REASONS));
    }

    public function test_decline_and_reopen_write_through_one_path_and_send_nothing(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE quotes (id INTEGER PRIMARY KEY, status TEXT, lost_reason TEXT, pipeline_stage TEXT, probability INT, stage_entered_at TEXT, updated_at TEXT)");
        $db->exec("CREATE TABLE quote_requests (id INTEGER PRIMARY KEY, quote_id INT, status TEXT, updated_at TEXT)");
        $db->exec("INSERT INTO quotes (id, status) VALUES (1,'sent'),(2,'accepted'),(3,'viewed')");
        $db->exec("INSERT INTO quote_requests (id, quote_id, status) VALUES (1,1,'quoted')");
        $db->sqliteCreateFunction('NOW', fn() => '2026-11-10 10:00:00', 0);
        $svc = new QuoteService($db);

        $this->assertTrue($svc->decline(1, 'price'));
        $this->assertSame(['declined', 'price', 'closed_lost'], array_values($db->query("SELECT status, lost_reason, pipeline_stage FROM quotes WHERE id = 1")->fetch(PDO::FETCH_NUM)));
        $this->assertSame('declined', $db->query("SELECT status FROM quote_requests WHERE quote_id = 1")->fetchColumn());
        $this->assertFalse($svc->decline(1, 'price'), 'already declined');
        $this->assertFalse($svc->decline(2, 'price'), 'accepted stays accepted');
        $this->assertSame('accepted', $db->query("SELECT status FROM quotes WHERE id = 2")->fetchColumn());

        $this->assertTrue($svc->decline(3, 'made up'));
        $this->assertNull($db->query("SELECT lost_reason FROM quotes WHERE id = 3")->fetchColumn());

        $this->assertTrue($svc->reopen(1));
        $this->assertSame(['sent', null], array_values($db->query("SELECT status, lost_reason FROM quotes WHERE id = 1")->fetch(PDO::FETCH_NUM)));
        $this->assertFalse($svc->reopen(2), 'only a declined quote reopens');
    }
}
