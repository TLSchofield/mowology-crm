<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Services/Messaging/TemplateRenderer.php';

/**
 * Mia's drafts: Tim's voice, honest tokens, carrier-safe texts, and learning his wording.
 */
class MiaWordingTest extends TestCase
{
    private function vars(): array
    {
        return [
            'first_name' => 'Jane', 'place' => '1234 Oak Street', 'company' => '', 'service' => 'Fall cleanup',
            'service_lower' => 'fall cleanup', 'last_when' => 'last October', 'season_line' => MiaWording::seasonLine(10),
            'prior_visits' => '', 'recent_visits' => '',
        ];
    }

    public function test_every_default_template_follows_the_house_rules(): void
    {
        $vars = $this->vars() + ['company' => 'Pacific Quorum', 'prior_visits' => '14', 'recent_visits' => '2',
            'referral_link' => 'https://mowology.ca/quote?referral_code=ABCD2345', 'reward_line' => "we'll book you in for a free aeration, on us.",
            'consent_until' => 'March 2027'];
        foreach (MiaWording::KINDS as $kind) {
            $t = MiaWording::defaults($kind);
            $body = MiaWording::render($t['body'], $vars);
            $this->assertStringStartsWith('Hi Jane,', $body, $kind);
            $this->assertStringEndsWith("Thanks,\nTim", $body, $kind);
            $this->assertStringNotContainsString('!', $body . $t['subject'], "$kind: no exclamation marks");
            $this->assertDoesNotMatchRegularExpression('/just checking in|touching base|we miss you|circling back|elevate|exceptional|thrilled/i', $body, $kind);
            $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $body, "$kind: no leftover tokens");
            $words = str_word_count(preg_replace('~https?://\S+~', '', $body));
            $this->assertLessThanOrEqual(120, $words, "$kind is $words words");
        }
    }

    public function test_unknown_tokens_never_reach_a_customer(): void
    {
        $this->assertSame('Hi Jane, about .', MiaWording::render('Hi {first_name}, about {mystery}.', ['first_name' => 'Jane']));
    }

    public function test_the_season_line_follows_the_month(): void
    {
        $this->assertStringContainsString('Spring', MiaWording::seasonLine(4));
        $this->assertStringContainsString('Summer', MiaWording::seasonLine(7));
        $this->assertStringContainsString('Fall', MiaWording::seasonLine(10));
        $this->assertStringContainsString('Winter', MiaWording::seasonLine(1));
        $this->assertStringContainsString('Winter', MiaWording::seasonLine(12));
    }

    public function test_when_reads_like_a_person(): void
    {
        $today = new DateTimeImmutable('2026-10-05');
        $this->assertSame('last October', MiaWording::when('2025-10-20', $today));
        $this->assertSame('in March', MiaWording::when('2026-03-02 09:00:00', $today));
        $this->assertSame('in June 2024', MiaWording::when('2024-06-01', $today));
        $this->assertSame('a while ago', MiaWording::when(null, $today));
    }

    public function test_the_text_points_to_the_email_and_never_carries_a_link(): void
    {
        $t = MiaWording::sms('Jane', 'fall cleanup this year');
        $this->assertSame([], MiaWording::smsProblems($t));
        $this->assertStringContainsString('(778) 846-9273', $t);
        // A long topic falls back to the short version, still valid.
        $short = MiaWording::sms('Bartholomew-Alexander', str_repeat('very long topic ', 10));
        $this->assertSame([], MiaWording::smsProblems($short));
        $this->assertLessThanOrEqual(160, strlen($short));
    }

    public function test_carrier_rules_catch_links_domains_length_and_missing_phone(): void
    {
        $ok = 'Hi Jane, check your email, or call (778) 846-9273.';
        $this->assertSame([], MiaWording::smsProblems($ok));
        $this->assertContains('has a link or web address', MiaWording::smsProblems($ok . ' mowology.ca'));
        $this->assertContains('has a link or web address', MiaWording::smsProblems($ok . ' https://x'));
        $this->assertContains('longer than 160 characters', MiaWording::smsProblems($ok . str_repeat(' word', 30)));
        $this->assertContains('missing (778) 846-9273', MiaWording::smsProblems('Hi Jane, check your email.'));
        $this->assertContains('should tell them to check their email', MiaWording::smsProblems('Hi, call (778) 846-9273.'));
        $this->assertContains('has special characters', MiaWording::smsProblems($ok . ' 🌿'));
    }

    public function test_tims_edit_becomes_a_reusable_template(): void
    {
        $sent = "Hi Jane,\n\nI was driving past 1234 Oak Street and thought of your fall cleanup last October.\n\nWant it again? Just reply.\n\nThanks,\nTim";
        $t = MiaWording::learnTemplate('seasonal', $sent, $this->vars());
        $this->assertSame("Hi {first_name},\n\nI was driving past {place} and thought of your {service_lower} {last_when}.\n\nWant it again? Just reply.\n\nThanks,\nTim", $t);
        // …and it renders for the next customer.
        $next = MiaWording::render($t, ['first_name' => 'Raj', 'place' => '9 Elm Ave', 'service_lower' => 'hedge trimming', 'last_when' => 'last September']);
        $this->assertStringContainsString('Hi Raj,', $next);
        $this->assertStringContainsString('9 Elm Ave', $next);
    }

    public function test_a_message_that_isnt_personal_or_lost_its_link_is_not_learned(): void
    {
        $this->assertNull(MiaWording::learnTemplate('reconnect', "Hello,\n\nCall us.\n\nTim", $this->vars()));
        $vars = $this->vars() + ['referral_link' => 'https://mowology.ca/quote?referral_code=ABCD2345'];
        $this->assertNull(MiaWording::learnTemplate('referral', "Hi Jane,\n\nTell your friends.\n\nTim", $vars));
    }

    public function test_names_are_swapped_as_whole_words_only(): void
    {
        $vars = ['first_name' => 'Ann'];
        $this->assertSame('Hi {first_name}, your Annual visit.', MiaWording::tokenise('Hi Ann, your Annual visit.', $vars));
    }

    public function test_the_consent_ask_keeps_its_confirm_link_marker(): void
    {
        $t = MiaWording::defaults('consent_ask');
        $this->assertStringContainsString(MiaWording::CONFIRM_MARK, $t['body']);
        $sent = MiaWording::render($t['body'], ['first_name' => 'Jane', 'place' => '1234 Oak Street', 'consent_until' => 'March 2027']);
        $this->assertNotNull(MiaWording::learnTemplate('consent_ask', $sent, $this->vars() + ['consent_until' => 'March 2027']));
        $this->assertNull(MiaWording::learnTemplate('consent_ask', str_replace(MiaWording::CONFIRM_MARK, '', $sent), $this->vars()));
    }

    public function test_a_marketing_email_refuses_to_render_without_sender_or_unsubscribe(): void
    {
        $co = ['name' => 'Mowology Landscaping', 'address' => '2845 West 15th Ave, Vancouver, BC'];
        $unsub = 'https://mowology.ca/unsubscribe.php?email=a%40x.com&token=abc&sid=0';
        $html = MiaWording::compose("Hi Jane,\n\nThanks,\nTim", $unsub, $co);
        $this->assertNotNull($html);
        $this->assertStringContainsString('Unsubscribe', $html);
        $this->assertStringContainsString('2845 West 15th Ave', $html);
        $this->assertNull(MiaWording::compose('Hi', '', $co), 'no unsubscribe link');
        $this->assertNull(MiaWording::compose('Hi', 'https://mowology.ca/', $co), 'not an unsubscribe link');
        $this->assertNull(MiaWording::compose('Hi', $unsub, ['name' => 'Mowology', 'address' => '']), 'no postal address');
    }

    public function test_email_html_escapes_and_links(): void
    {
        $h = MiaWording::toHtml("Hi <b>Jane</b>,\n\nYour link:\nhttps://mowology.ca/quote?referral_code=AB");
        $this->assertStringContainsString('&lt;b&gt;Jane&lt;/b&gt;', $h);
        $this->assertStringContainsString('<a href="https://mowology.ca/quote?referral_code=AB">', $h);
        $this->assertSame(2, substr_count($h, '<p '));
    }
}
