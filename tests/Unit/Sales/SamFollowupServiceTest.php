<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sam's follow-ups: drafts in Tim's voice, texts the carriers will deliver, and learning
 * from Tim's edits.
 */
class SamFollowupServiceTest extends TestCase
{
    private function card(array $extra = []): array
    {
        return $extra + [
            'key' => 'c7', 'kind' => 'stale', 'template' => 'first_nudge', 'contact_id' => 7, 'name' => 'Jodi Peacock', 'first_name' => 'Jodi',
            'amount' => 1579.6, 'valid_until' => '2026-11-03',
            'quotes' => [
                ['id' => 69, 'number' => 'QUO-2026-0069', 'title' => '', 'service' => 'Snow removal', 'address' => '2025 West 42nd Avenue', 'amount' => 1024.28, 'sent_at' => '2026-10-04 10:00:00', 'valid_until' => '2026-11-03', 'views' => 0],
                ['id' => 68, 'number' => 'QUO-2026-0068', 'title' => '', 'service' => 'Snow removal', 'address' => '1650 West 13th Avenue', 'amount' => 555.32, 'sent_at' => '2026-10-04 10:00:00', 'valid_until' => '2026-11-03', 'views' => 0],
            ],
            'thread' => [],
        ];
    }

    public function test_every_text_template_fits_carrier_rules_even_with_a_long_name(): void
    {
        $vars = SamFollowupService::vars($this->card(['first_name' => 'Christopher-Alexander']), 'Tim');
        foreach (SamFollowupService::TEMPLATES as $key => [$subject, $body, $sms]) {
            $text = SamFollowupService::fill($sms, $vars);
            $this->assertSame([], SamFollowupService::smsProblems($text), "{$key}: {$text}");
            $this->assertStringContainsString('(778) 846-9273', $text, $key);
        }
    }

    public function test_email_templates_sound_like_tim(): void
    {
        foreach (SamFollowupService::TEMPLATES as $key => [$subject, $body]) {
            $this->assertStringNotContainsString('!', $subject . $body, "{$key}: no exclamation marks");
            $this->assertStringNotContainsStringIgnoringCase('just checking in', $body, $key);
            $this->assertStringEndsWith("Thanks,\n{owner}", $body, $key);
        }
    }

    public function test_texts_with_links_special_characters_or_too_long_are_refused(): void
    {
        $this->assertNotEmpty(SamFollowupService::smsProblems('See mowology.ca/quote'));
        $this->assertNotEmpty(SamFollowupService::smsProblems('Go to https://x.co'));
        $this->assertNotEmpty(SamFollowupService::smsProblems('Thanks 🙂'));
        $this->assertNotEmpty(SamFollowupService::smsProblems(str_repeat('a', 161)));
        $this->assertSame([], SamFollowupService::smsProblems('Hi Jo, call (778) 846-9273. Details are in your email.'));
    }

    public function test_a_property_manager_draft_lists_each_site_and_the_total(): void
    {
        $vars = SamFollowupService::vars($this->card(['template' => 'multi']), 'Tim');
        $body = SamFollowupService::fill(SamFollowupService::TEMPLATES['multi'][1], $vars);
        $this->assertStringContainsString('the 2 quotes', $body);
        $this->assertStringContainsString('- QUO-2026-0069 for 2025 West 42nd Avenue ($1,024)', $body);
        $this->assertStringContainsString("Together that's \$1,580", $body);
        $this->assertStringContainsString('winter routes', $body, 'snow quotes say why now, once');
        $this->assertStringNotContainsString("\n\n\n", $body);
    }

    public function test_a_landscaping_quote_has_no_winter_line(): void
    {
        $card = $this->card();
        $card['quotes'] = [$card['quotes'][0]];
        $card['quotes'][0]['service'] = 'Hedge trimming';
        $body = SamFollowupService::fill(SamFollowupService::TEMPLATES['first_nudge'][1], SamFollowupService::vars($card, 'Tim'));
        $this->assertStringNotContainsString('winter', $body);
        $this->assertStringContainsString('hedge trimming quote for 2025 West 42nd Avenue', $body);
    }

    public function test_tims_edit_becomes_a_reusable_draft_for_the_next_customer(): void
    {
        $vars = SamFollowupService::vars($this->card(), 'Tim');
        $edited = "Hi Jodi,\n\nWanted to make sure the snow removal quotes for 2025 West 42nd Avenue reached you. Total is \$1,580.\n\nThanks,\nTim";
        $learned = SamFollowupService::unfill($edited, $vars);
        $this->assertSame("Hi {first_name},\n\nWanted to make sure the {service} quotes for {place} reached you. Total is {total}.\n\nThanks,\n{owner}", $learned);

        $other = $this->card(['first_name' => 'Ron', 'amount' => 900.0]);
        $other['quotes'] = [['id' => 1, 'number' => 'QUO-2026-0001', 'title' => '', 'service' => 'Salting', 'address' => '7 Cypress St', 'amount' => 900.0, 'sent_at' => '2026-10-01', 'valid_until' => null, 'views' => 0]];
        $this->assertStringStartsWith('Hi Ron,', SamFollowupService::fill($learned, SamFollowupService::vars($other, 'Tim')));
    }

    public function test_only_real_changes_count_as_an_edit(): void
    {
        $this->assertFalse(SamFollowupService::isEdited("Hi Jo,\n\nThanks", "Hi Jo,\n\n Thanks  "));
        $this->assertTrue(SamFollowupService::isEdited('Hi Jo, thanks', 'Hi Jo, thank you'));
        $this->assertFalse(SamFollowupService::isEdited('', 'anything'), 'no suggestion → nothing to learn');
    }

    public function test_the_claude_prompt_carries_the_house_rules_the_facts_and_the_thread(): void
    {
        $card = $this->card(['kind' => 'replied', 'thread' => [['dir' => 'inbound', 'snippet' => 'Does this include salting?', 'at' => '2026-10-10 10:00:00']]]);
        $p = SamFollowupService::replyPrompt($card, 'Tim', ["Hi Ann,\n\nDone.\n\nThanks,\nTim"]);
        $this->assertStringContainsString('Mowology copy rules', $p['system']);
        $this->assertStringContainsString('never invent prices', $p['system']);
        $this->assertStringContainsString('CUSTOMER (2026-10-10 10:00:00): Does this include salting?', $p['user']);
        $this->assertStringContainsString('QUO-2026-0069', $p['user']);
        $this->assertStringContainsString('How Tim writes', $p['user']);
    }
}
