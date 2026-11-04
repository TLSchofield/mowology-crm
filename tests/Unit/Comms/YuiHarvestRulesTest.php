<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Comms/Services/UntrustedAsk.php';
require_once __DIR__ . '/../../../app/Modules/Comms/Services/ReplyClock.php';
require_once __DIR__ . '/../../../app/Modules/Comms/Services/UpsetSignal.php';
require_once __DIR__ . '/../../../app/Modules/Comms/Services/DraftChecks.php';

/**
 * Yui harvest (2026-11-03): hold for verification, the reply clock, staff and acknowledgements out of the client
 * inbox, the unhappy client, honest quotes, draft checks.
 */
class YuiHarvestRulesTest extends TestCase
{
    // A. hold for verification ────────────────────────────────────────────────

    /** @dataProvider asks */
    public function test_each_kind_of_ask_is_held(string $kind, string $body): void
    {
        $a = UntrustedAsk::detect('Quick one', $body, 'x@vendor-co.com', ['x@vendor.com']);
        $this->assertNotNull($a, $body);
        $this->assertSame($kind, $a['kind'], $body);
        $this->assertNotSame('', $a['quote']);
        $this->assertStringContainsString('No draft written and nothing changed', UntrustedAsk::text('Dave', $a));
        $this->assertStringContainsString('Call the number already on file', UntrustedAsk::text('Dave', $a));
    }

    public function asks(): array
    {
        return [
            ['bank', 'Hi Tim, our bank details have changed. Please use the new banking details below for the next payment.'],
            ['bank', 'We have changed our remit to address and account number, please update the payee before you pay.'],
            ['bank', 'Please send the payment to a new account, I will email the institution and transit number.'],
            ['urgent_pay', 'This is urgent, please wire the money today and send me the confirmation.'],
            ['urgent_pay', 'Can you buy 5 Apple gift cards for a client, I will pay you back.'],
            ['credential', 'Please send me your password so I can fix the portal.'],
            ['credential', 'Click this link to verify your account or it will be closed.'],
            ['credential', 'Your mailbox has been suspended, confirm your account now.'],
            ['signer', 'Please update the authorized signer on the contract, the new signatory is Mark.'],
            ['data_out', 'Send the client list to this new email address, mark@gmial.com, as soon as you can.'],
        ];
    }

    public function test_mentioning_is_not_asking_and_quoted_history_does_not_count(): void
    {
        $this->assertNull(UntrustedAsk::detect('Re: Reference?', "The password is invoice number: INV-2026-0378\n\nOn Sun, Jul 19, 2026 at 11:21 AM Tove wrote:", 'tove@gmail.com'));   // the live 2026-09-04 message
        $this->assertNull(UntrustedAsk::detect('Invoice', 'The PDF is password protected, the password is the invoice number.', 'a@b.com'));
        $this->assertNull(UntrustedAsk::detect('Thanks', "Thanks, I paid by e-transfer.\n\nOn Mon, Nov 2, 2026 at 9:00 AM Tim wrote:\n> Please send the payment to the new account details I sent you", 'a@b.com'));
        $this->assertNull(UntrustedAsk::detect('Quote', 'Can you hedge trim and clean up the beds on Tuesday? We pay by e-transfer. Gift for the neighbour: just a thank you.', 'a@b.com'));
        $this->assertNull(UntrustedAsk::detect('', '', ''));
        // The card quotes the ask and nothing else, bounded.
        $a = UntrustedAsk::detect('x', str_repeat('Lorem ipsum. ', 40) . 'Please change our bank details now.', 'a@b.com');
        $this->assertLessThanOrEqual(UntrustedAsk::QUOTE_CHARS, mb_strlen($a['quote']));
        $this->assertStringContainsString('bank details', $a['quote']);
    }

    public function test_lookalike_and_unknown_sender_domains_are_named(): void
    {
        $this->assertNull(UntrustedAsk::lookalike('Dave <dave@vancouvermgmt.com>', ['office@vancouvermgmt.com']));
        $this->assertStringContainsString('looks like vancouvermgmt.com', UntrustedAsk::lookalike('dave@vancouvermgrnt.com', ['office@vancouvermgmt.com']));
        $this->assertStringContainsString('not an address we have for them', UntrustedAsk::lookalike('dave@other-firm.org', ['office@vancouvermgmt.com']));
        $this->assertStringContainsString('not an address', UntrustedAsk::lookalike('joe@gmail.com', ['joe@hotmail.com']));   // two free domains are different mailboxes, still a different address
        $this->assertSame('we hold no address for them to compare', UntrustedAsk::lookalike('a@b.com', []));
        $this->assertSame('', UntrustedAsk::domain('no at sign'));
        $this->assertSame('x.com', UntrustedAsk::domain('"Q" <Q@X.com>'));
    }

    // B / C. the clock, acknowledgements, staff ───────────────────────────────

    public function test_weekdays_and_the_three_stages(): void
    {
        $this->assertSame(0, ReplyClock::weekdays('2026-11-03 09:00:00', '2026-11-03 17:00:00'));
        $this->assertSame(1, ReplyClock::weekdays('2026-10-30 17:00:00', '2026-11-02 09:00:00'));   // Friday evening → Monday: one weekday
        $this->assertSame(3, ReplyClock::weekdays('2026-10-28 09:00:00', '2026-11-02 09:00:00'));   // Wed → Mon: Thu, Fri, Mon
        $this->assertSame('fresh', ReplyClock::stage('2026-11-02 15:27:17', '2026-11-02 18:00:00')['stage']);
        $w = ReplyClock::stage('2026-11-02 15:27:17', '2026-11-03 09:00:00');
        $this->assertSame(['waiting', 1, 2], [$w['stage'], $w['days'], $w['priority']]);
        $this->assertSame(' — waiting 1 day', $w['suffix']);
        $l = ReplyClock::stage('2026-10-27 10:13:52', '2026-11-03 16:00:00');                       // Tony: 7 calendar days
        $this->assertSame(['late', 5, 1], [$l['stage'], $l['days'], $l['priority']]);
        $this->assertSame(' — they have waited 5 days', $l['suffix']);
    }

    public function test_acknowledgements_need_nothing_unless_we_asked_a_question(): void
    {
        foreach (['All right', 'Ok', 'k', 'Thanks!', 'Sounds good 👍', 'ok thanks', 'Got it.', 'Will do', 'thank you so much'] as $t) {
            $this->assertTrue(ReplyClock::ackOnly($t, 'See you Tuesday.'), $t);
        }
        $this->assertFalse(ReplyClock::ackOnly('All right', 'Shall we start the 12th?'));          // an answer to our question
        $this->assertTrue(ReplyClock::ackOnly('All right', null));
        $this->assertTrue(ReplyClock::ackOnly('👍', 'See you Tuesday.'));                               // live: John's thumbs-up waited 7 days
        $this->assertTrue(ReplyClock::ackOnly('❤️ 👍', null));
        $this->assertFalse(ReplyClock::ackOnly('👍', 'Is Thursday ok?'));
        $this->assertFalse(ReplyClock::ackOnly('?', null));
        foreach (['Can you come Tuesday?', 'ok what about the hedge', 'Hi Tim,', 'I\'ll be 5 mins', 'ok 2pm', 'Not ok', 'No', '', 'thanks, one more thing: the gate code is 4411'] as $t) {
            $this->assertFalse(ReplyClock::ackOnly($t, null), $t);
        }
    }

    public function test_staff_are_recognised_by_email_or_phone(): void
    {
        $e = ['nigel@mowology.ca' => true];
        $p = ['6045551234' => true];
        $this->assertTrue(ReplyClock::isStaff(['email' => 'Nigel@Mowology.ca'], $e, $p));
        $this->assertTrue(ReplyClock::isStaff(['phone' => '(604) 555-1234'], $e, $p));
        $this->assertTrue(ReplyClock::isStaff(['mobile' => '+1 604-555-1234'], $e, $p));
        $this->assertFalse(ReplyClock::isStaff(['email' => 'joe@gmail.com', 'phone' => '604 555 9999'], $e, $p));
        $this->assertFalse(ReplyClock::isStaff(['phone' => '555-1234'], $e, $p));                 // seven digits is not a number to match on
        $this->assertSame('', ReplyClock::phoneKey(null));
    }

    // D. unhappy ──────────────────────────────────────────────────────────────

    public function test_complaints_and_repeats_are_unhappy_and_a_seasonal_cancel_is_not(): void
    {
        foreach (['That is unacceptable', 'I was not happy with you this summer.', 'I have hired a new gardener!', 'the guys never came', 'Same as last time when it didn\'t show',
                  'I am still waiting for your response', 'this is the third time I have asked', 'No one came on Tuesday', 'I want a refund'] as $t) {
            $this->assertNotNull(UpsetSignal::cue($t), $t);
        }
        foreach (['Can you please cancel our recurring grass cutting? We are done for the season. Thanks for all your work - see you next year!', 'All good, thanks', 'Do you want me to cancel and re send there?', 'Can we move Tuesday to Wednesday?'] as $t) {
            $this->assertNull(UpsetSignal::cue($t), $t);
        }
        $this->assertTrue(UpsetSignal::assess('Hello?', 2, 2)['upset']);                           // two messages since we wrote, the first two weekdays ago
        $this->assertSame('2 messages since we last wrote, the first 2 weekdays ago', UpsetSignal::assess('Hello?', 2, 2)['why']);
        $this->assertFalse(UpsetSignal::assess('Japanese Skimmia', 2, 0)['upset']);                // two texts in a row are one conversation
        $this->assertFalse(UpsetSignal::assess('Hello?', 1, 3)['upset']);
        $this->assertStringContainsString('“unacceptable”', UpsetSignal::assess('That is unacceptable', 1)['why']);
        $rule = UpsetSignal::draftRule('Tim');
        $this->assertStringContainsString('only an acknowledgement', $rule);
        $this->assertStringContainsString('Do NOT offer a fix', $rule);
        $this->assertStringContainsString('under 60 words', $rule);
    }

    // E. honest quotes ────────────────────────────────────────────────────────

    public function test_the_quote_is_what_they_said_not_the_greeting(): void
    {
        require_once __DIR__ . '/../../../app/Modules/Sales/Services/UnclaimedReplyService.php';
        $this->assertSame('Can you also do the front hedge?', UnclaimedReplyService::quote("Hi Tim,\n\nCan you also do the front hedge?\n\nThanks,\nJoe"));
        $this->assertSame('Trimming looks great', UnclaimedReplyService::quote("Hello,\nTrimming looks great\n> older stuff"));
        $this->assertSame('Hi Tim,', UnclaimedReplyService::quote('Hi Tim,'));                      // a greeting alone is better than nothing
        $this->assertSame('All right', UnclaimedReplyService::quote('All right'));
        $this->assertSame('', UnclaimedReplyService::quote("> quoted only\nmore"));
    }

    // F. draft checks ─────────────────────────────────────────────────────────

    public function test_a_draft_that_invents_a_price_a_date_or_runs_long_is_flagged(): void
    {
        $conv = 'Can you do the front hedge again? Last time it was $120.';
        $ok = "Hi Joe,\n\nYes, the front hedge again. Same $120 as last time.\n\nThanks,\nTim";
        $this->assertSame([], DraftChecks::check($ok, $conv));
        $bad = "Hi Joe,\n\nI can do it Thursday for $180, or November 12.\n\nThanks,\nTim";
        $f = DraftChecks::check($bad, $conv);
        $this->assertCount(2, $f);
        $this->assertStringContainsString('$180 — not in the conversation', $f[0]);
        $this->assertStringContainsString('promises thursday, november 12 — check you can keep it', $f[1]);
        $this->assertSame([], DraftChecks::check("Hi Joe,\n\nSorry, that is not right. I will call you today.\n\nThanks,\nTim", 'the guys never came'));
        $this->assertNotSame([], DraftChecks::check("Hi Joe,\n\nWe will be there tomorrow.\n\nThanks,\nTim", 'when can you come?'));
        $long = "Hi Joe,\n\n" . implode(' ', array_fill(0, 130, 'word')) . "\n\nThanks,\nTim";
        $this->assertStringContainsString('130 words — longer than 120', DraftChecks::check($long, '')[0]);
        $this->assertSame('Yes, the front hedge.', DraftChecks::body("Hi Joe,\n\nYes, the front hedge.\n\nThanks,\nTim"));
        $this->assertSame(3, DraftChecks::words(" a b  c "));
    }

    // desk ranking ────────────────────────────────────────────────────────────

    public function test_rank_and_headline_put_holds_then_unhappy_then_the_late_first(): void
    {
        require_once __DIR__ . '/../../../app/Modules/Comms/Services/YuiRules.php';
        $hold = ['section' => 'inbox', 'hold' => true, 'priority' => 1];
        $upset = ['section' => 'inbox', 'upset' => true, 'priority' => 1];
        $yes = ['section' => 'inbox', 'yes' => true, 'priority' => 1];
        $late = ['section' => 'inbox', 'priority' => 1];
        $plain = ['section' => 'inbox', 'priority' => 2];
        $ranks = array_map([YuiRules::class, 'rank'], [$hold, $upset, $yes, $late, $plain]);
        $this->assertSame([-1, 0, 0, 1, 2], $ranks);
        $this->assertSame('1 message asks for something only a phone call can confirm — held, no draft', YuiRules::headline(['inbox' => [$hold, $plain]]));
        $this->assertSame('2 messages ask for something only a phone call can confirm — held, no draft', YuiRules::headline(['inbox' => [$hold, $hold]]));
        $this->assertSame('1 unhappy client — a call, not a note', YuiRules::headline(['inbox' => [$upset, $plain]]));
        $this->assertSame('1 client reply is waiting on an answer', YuiRules::headline(['inbox' => [$plain]]));
    }
}
