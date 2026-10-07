<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sam claims every customer reply no other net caught (2026-10-06: Gaby's "Yes" to a
 * hand-sent fall-cleanup email surfaced nowhere).
 */
class UnclaimedReplyServiceTest extends TestCase
{
    private const NOW = '2026-10-06 12:00:00';

    private static function reply(int $cid, string $at, string $snippet = 'Yes', array $extra = []): array
    {
        return $extra + [
            'message_key' => 'msg-' . $cid . '-' . $at,
            'contact_id'  => $cid,
            'channel'     => 'email',
            'from_addr'   => 'person' . $cid . '@example.com',
            'subject'     => 'Re: Cambridge Apartments: fall cleanup',
            'snippet'     => $snippet,
            'sent_at'     => $at,
            'first_name'  => 'Gaby',
        ];
    }

    // ── isYes ────────────────────────────────────────────────────────────

    /** @dataProvider yesCases */
    public function test_short_affirmatives_are_a_yes(string $s): void
    {
        $this->assertTrue(UnclaimedReplyService::isYes($s), $s);
    }

    public static function yesCases(): array
    {
        return array_map(fn($s) => [$s], [
            'Yes',
            'yes',
            'Yes!',
            'Yes.',
            'Yes please',
            'Yes please, go ahead with all five.',
            'Yes please. Can you start Monday?',
            'Please do it',
            'Please do it.',
            'Hi Tim Please do it. Thank you very much! Best Colleen',
            "Hi Tim\nPlease do it.\nThank you very much!\nBest\nColleen",
            'Hi Tim, yes please',
            'Hey there, sounds good',
            'Go ahead',
            'Go ahead thanks',
            'Sounds good',
            'Sounds great, thanks Tim',
            "Let's do it",
            "Let\u{2019}s do it!",
            'Lets do it',
            'Book it',
            'Book us in please',
            'Approved',
            'Ok',
            'OK thanks',
            'Okay',
            'Okay, go for it',
            'Sure',
            'Perfect, thank you',
            'Thanks Tim. Yes please.',
            "Thanks Tim. If you could take a look at the lawn and do what's needed that would be great",
            'Could you please go ahead with the cleanup',
            'Good morning Tim, please proceed.',
        ]);
    }

    /** @dataProvider notYesCases */
    public function test_questions_refusals_and_hedges_are_not_a_yes(string $s): void
    {
        $this->assertFalse(UnclaimedReplyService::isYes($s), $s);
    }

    public static function notYesCases(): array
    {
        return array_map(fn($s) => [$s], [
            '',
            'No',
            'No thanks',
            'No, not this year',
            'Nope',
            'Not right now, thanks',
            'Yes?',
            'Ok?',
            'Yes but can you do it in spring?',
            "Yes, but I'd like to wait until November.",
            "Okay, I don't think we need it",
            'Sounds good however hold off for now',
            'Can you come next week?',
            'How much would that be?',
            'What does the fall cleanup include?',
            'Could you do it on Tuesday? That would be great',
            'If you could send me a quote that would be great',
            'Thanks',
            'Thank you!',
            'Thanks Tim.',
            'Hi Tim',
            'Who is this?',
            'Got it, I will check with the strata council and get back to you.',
            'Yes, ' . str_repeat('we have a few questions about the scope and the timing of the work and the gate code. ', 3),
            str_repeat('Some long preamble about the building. ', 6) . 'Yes please.',
        ]);
    }

    // ── Automated mail ───────────────────────────────────────────────────

    public function test_machines_are_not_customers(): void
    {
        $this->assertTrue(UnclaimedReplyService::isAutomated('no-reply@strata.ca', 'Your request', 'Thanks'));
        $this->assertTrue(UnclaimedReplyService::isAutomated('"Bank" <noreply@bank.ca>', 'x', 'x'));
        $this->assertTrue(UnclaimedReplyService::isAutomated('MAILER-DAEMON@mail.example.com', 'x', 'x'));
        $this->assertTrue(UnclaimedReplyService::isAutomated('notifications@portal.ca', 'x', 'x'));
        $this->assertTrue(UnclaimedReplyService::isAutomated('gaby@cambridge.ca', 'Automatic reply: fall cleanup', 'x'));
        $this->assertTrue(UnclaimedReplyService::isAutomated('gaby@cambridge.ca', 'Out of Office: fall cleanup', 'x'));
        $this->assertTrue(UnclaimedReplyService::isAutomated('gaby@cambridge.ca', 'Re: fall cleanup', 'I am currently out of the office until Monday.'));
        $this->assertTrue(UnclaimedReplyService::isAutomated('gaby@cambridge.ca', 'Re: x', "I'll respond to your message when I return on the 12th."));
        $this->assertFalse(UnclaimedReplyService::isAutomated('gaby@cambridge.ca', 'Re: Cambridge Apartments: fall cleanup', 'Yes'));
        $this->assertFalse(UnclaimedReplyService::isAutomated('6045551234', '', 'Sounds good'));
    }

    // ── Grouping and the item ────────────────────────────────────────────

    public function test_one_item_per_contact_about_their_latest_reply(): void
    {
        $items = UnclaimedReplyService::unclaimed([
            self::reply(5, '2026-10-01 09:00:00', 'Can you send photos?'),
            self::reply(5, '2026-10-05 16:20:00', 'Yes'),
            self::reply(9, '2026-10-04 10:00:00', 'When are you coming?', ['first_name' => 'Ron Smith']),
        ], [], self::NOW);

        $this->assertCount(2, $items);
        $this->assertSame(5, $items[0]['contact_id']);            // the yes ranks first
        $this->assertSame(1, $items[0]['priority']);
        // Not about a quote → Yui's lane (client_reply), never Sam's.
        $this->assertSame('client_reply', $items[0]['kind']);
        $this->assertSame('client', $items[0]['lane']);
        $this->assertSame('2026-10-05', $items[0]['since']);
        $this->assertSame(UnclaimedReplyService::key(5, 'msg-5-2026-10-05 16:20:00', 'client'), $items[0]['key']);
        $this->assertMatchesRegularExpression('/^yui:reply:5:[0-9a-f]{12}$/', $items[0]['key']);
        $this->assertSame('Gaby replied "Yes" to "Cambridge Apartments: fall cleanup". That\'s a yes. Answer them.', $items[0]['text']);
        $this->assertSame('/crm/clients_appstack.php?action=view_contact&id=5', $items[0]['url']);
        $this->assertSame(2, $items[1]['priority']);
        $this->assertSame('Ron replied "When are you coming?" to "Cambridge Apartments: fall cleanup". Answer them.', $items[1]['text']);
    }

    public function test_text_never_shows_an_email_address_or_phone_number(): void
    {
        $items = UnclaimedReplyService::unclaimed([
            self::reply(5, '2026-10-05 16:20:00', "Call me on 604-555-1234 or write gaby@cambridge.ca\nthanks",
                ['first_name' => 'gaby@cambridge.ca', 'subject' => 'Re: RE: quote for jim@x.com']),
        ], [], self::NOW);
        $t = $items[0]['text'];
        $this->assertStringNotContainsString('@', $t);
        $this->assertDoesNotMatchRegularExpression('/\d{3}.\d{3}.\d{4}/', $t);
        $this->assertStringStartsWith('A customer replied', $t);
    }

    public function test_long_first_lines_are_cut_to_about_sixty_characters(): void
    {
        $q = UnclaimedReplyService::quote("\n" . str_repeat('word ', 30) . "\nsecond line");
        $this->assertLessThanOrEqual(UnclaimedReplyService::QUOTE_CHARS, mb_strlen($q));
        $this->assertStringEndsWith('…', $q);
    }

    public function test_a_text_says_texted_and_has_no_subject(): void
    {
        $items = UnclaimedReplyService::unclaimed([self::reply(5, '2026-10-05 16:20:00', 'Sounds good', ['channel' => 'sms', 'subject' => null])], [], self::NOW);
        $this->assertSame('Gaby texted "Sounds good". That\'s a yes. Answer them.', $items[0]['text']);
        $this->assertSame('sms', $items[0]['channel']);
    }

    public function test_old_unknown_and_automated_replies_are_left_out(): void
    {
        $items = UnclaimedReplyService::unclaimed([
            self::reply(5, '2026-09-20 09:00:00'),                                     // older than 14 days
            self::reply(0, '2026-10-05 09:00:00'),                                     // no contact
            self::reply(6, '2026-10-05 09:00:00', 'x', ['subject' => 'Automatic reply: fall cleanup']),
            self::reply(7, '2026-10-05 09:00:00', 'x', ['from_addr' => 'noreply@portal.ca']),
        ], [], self::NOW);
        $this->assertSame([], $items);
    }

    public function test_an_automated_reply_does_not_hide_the_persons_earlier_reply(): void
    {
        $items = UnclaimedReplyService::unclaimed([
            self::reply(5, '2026-10-04 09:00:00', 'Yes please'),
            self::reply(5, '2026-10-05 09:00:00', 'I am currently out of the office.'),
        ], [], self::NOW);
        $this->assertCount(1, $items);
        $this->assertSame('2026-10-04 09:00:00', $items[0]['at']);
    }

    // ── Other nets ───────────────────────────────────────────────────────

    public function test_replies_another_net_already_shows_are_left_out(): void
    {
        $replies = [self::reply(5, '2026-10-05 09:00:00'), self::reply(6, '2026-10-05 09:00:00'), self::reply(7, '2026-10-05 09:00:00'),
                    self::reply(8, '2026-10-05 09:00:00')];
        $items = UnclaimedReplyService::unclaimed($replies, [
            'sam'  => [5],                                                      // Sam's quote queue: "they replied"
            'mia'  => [6],                                                      // Mia's campaign_reply
            'asks' => [['contacts' => [99, 7], 'asked_at' => '2026-10-03 09:00:00']],   // Field Ask-first (billing contact replied)
        ], self::NOW);
        $this->assertSame([8], array_column($items, 'contact_id'));
    }

    public function test_an_ask_sent_after_the_reply_does_not_cover_it(): void
    {
        $items = UnclaimedReplyService::unclaimed([self::reply(7, '2026-10-02 09:00:00')],
            ['asks' => [['contacts' => [7], 'asked_at' => '2026-10-03 09:00:00']]], self::NOW);
        $this->assertCount(1, $items);
    }

    // ── Answered after ───────────────────────────────────────────────────

    public function test_an_email_or_text_out_after_the_reply_answers_it(): void
    {
        $r = [self::reply(5, '2026-10-05 09:00:00')];
        $this->assertSame([], UnclaimedReplyService::unclaimed($r, ['outbound' => [5 => '2026-10-05 10:00:00']], self::NOW));
        $this->assertCount(1, UnclaimedReplyService::unclaimed($r, ['outbound' => [5 => '2026-10-04 10:00:00']], self::NOW));
        $this->assertCount(1, UnclaimedReplyService::unclaimed($r, ['outbound' => [6 => '2026-10-06 10:00:00']], self::NOW));
    }

    public function test_a_quote_made_after_the_reply_answers_it(): void
    {
        $r = [self::reply(5, '2026-10-05 09:00:00')];
        $this->assertSame([], UnclaimedReplyService::unclaimed($r, ['quotes' => [['contact_id' => 5, 'site_contact_id' => null, 'created_at' => '2026-10-05 11:00:00']]], self::NOW));
        $this->assertSame([], UnclaimedReplyService::unclaimed($r, ['quotes' => [['contact_id' => 2, 'site_contact_id' => 5, 'created_at' => '2026-10-06 08:00:00']]], self::NOW));
        $this->assertCount(1, UnclaimedReplyService::unclaimed($r, ['quotes' => [['contact_id' => 5, 'site_contact_id' => null, 'created_at' => '2026-10-01 11:00:00']]], self::NOW));
    }

    // ── Lanes: Sam keeps replies about a quote, Yui gets the rest ────────

    public function test_a_reply_about_a_quote_is_sams_and_everything_else_is_yuis(): void
    {
        $items = UnclaimedReplyService::unclaimed([
            self::reply(5, '2026-10-05 09:00:00', 'Yes', ['subject' => 'Re: Your fall cleanup quote QUO-2026-0144']),
            self::reply(6, '2026-10-05 09:00:00', 'Can you send a revised estimate?', ['subject' => 'Re: hedges']),
            self::reply(7, '2026-10-05 09:00:00', 'Thanks, the lawn looks great', ['subject' => 'Re: Cambridge Apartments: fall cleanup']),
        ], [], self::NOW);
        $by = array_column($items, null, 'contact_id');
        $this->assertSame('quote', $by[5]['lane']);
        $this->assertSame('quote_reply', $by[5]['kind']);
        $this->assertStringStartsWith('sam:reply:5:', $by[5]['key']);
        $this->assertSame('quote', $by[6]['lane']);
        $this->assertSame('client', $by[7]['lane']);
        $this->assertSame('client_reply', $by[7]['kind']);
        $this->assertStringStartsWith('yui:reply:7:', $by[7]['key']);
        // Never "unclaimed_reply" any more — one source, one owner.
        $this->assertNotContains('unclaimed_reply', array_column($items, 'kind'));
    }

    public function test_about_quote(): void
    {
        $this->assertTrue(UnclaimedReplyService::aboutQuote('Re: Quote for 4180 Oak Ct', ''));
        $this->assertTrue(UnclaimedReplyService::aboutQuote('', 'Council approved the quote'));
        $this->assertTrue(UnclaimedReplyService::aboutQuote('QUO-2026-0012', ''));
        $this->assertTrue(UnclaimedReplyService::aboutQuote('', 'Could you send a proposal?'));
        $this->assertFalse(UnclaimedReplyService::aboutQuote('Re: fall cleanup', 'Yes please'));
        $this->assertFalse(UnclaimedReplyService::aboutQuote('Gate code', 'The code is 1234, as quoting goes'));
    }

    public function test_handled_under_either_prefix_hides_the_reply(): void
    {
        $r = [self::reply(5, '2026-10-05 09:00:00')];   // client lane
        $mk = 'msg-5-2026-10-05 09:00:00';
        $this->assertSame([], UnclaimedReplyService::unclaimed($r, ['hidden' => [UnclaimedReplyService::key(5, $mk, 'client')]], self::NOW));
        $this->assertSame([], UnclaimedReplyService::unclaimed($r, ['hidden' => [UnclaimedReplyService::key(5, $mk, 'quote')]], self::NOW));
    }

    public function test_handled_or_snoozed_in_charlie_hides_that_reply_only(): void
    {
        $r = [self::reply(5, '2026-10-05 09:00:00')];
        $key = UnclaimedReplyService::key(5, 'msg-5-2026-10-05 09:00:00');
        $this->assertSame([], UnclaimedReplyService::unclaimed($r, ['hidden' => [$key]], self::NOW));
        // A newer reply is a new key: it comes back.
        $r[] = self::reply(5, '2026-10-06 08:00:00', 'One more thing');
        $this->assertCount(1, UnclaimedReplyService::unclaimed($r, ['hidden' => [$key]], self::NOW));
    }
}
