<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * FieldAskService — "Ask first" on field recommendations (migration 1180).
 *
 * The pieces that decide who gets an email, whether they may get it, who may send it and
 * what it says are pure and tested here. send() itself does DB writes and real email, so
 * (as for FieldRecommendationService) it is not run end to end.
 */
class FieldAskServiceTest extends TestCase
{
    private const NOW = '2026-10-06 15:00:00';

    private function person(int $id, string $first, ?string $email, string $role): array
    {
        return ['contact_id' => $id, 'first_name' => $first, 'name' => $first . ' X', 'email' => $email, 'role' => $role];
    }

    /** Cambridge Apartments, the owner's own example: Gaby on site, Darren signs off. */
    private function cambridge(array $over = []): array
    {
        return array_merge([
            'id'                     => 9,
            'recommended_product_id' => 12,
            'product_name'           => 'Fall Clean Up',
            'field_label'            => null,
            'field_ask_pitch'        => null,
            'place'                  => 'Cambridge Apartments',
            'created_at'             => '2026-10-06 09:40:00',
            'ask_person'             => $this->person(31, 'Gaby', 'gaby@example.com', 'onsite'),
            'billing_person'         => $this->person(4, 'Darren', 'darren@example.com', 'site'),
        ], $over);
    }

    private function render(array $ctx, string $owner = 'Tim'): array
    {
        $vars = FieldAskService::vars($ctx, $owner, self::NOW);
        return [
            FieldAskService::fill(FieldAskService::DEFAULT_SUBJECT, $vars),
            FieldAskService::fill(FieldAskService::DEFAULT_BODY, $vars),
            $vars,
        ];
    }

    // ── Who may send ─────────────────────────────────────────────────────────

    public function test_admin_can_always_send(): void
    {
        $this->assertTrue(FieldAskService::decideCanSend('admin', null));
        $this->assertTrue(FieldAskService::decideCanSend('admin', ['jobs.view']));
    }

    public function test_billing_edit_permission_can_send(): void
    {
        $this->assertTrue(FieldAskService::decideCanSend('user', ['jobs.view', 'billing.edit']));
        $this->assertTrue(FieldAskService::decideCanSend('user', ['*']));
    }

    public function test_crew_without_billing_edit_cannot_send(): void
    {
        $this->assertFalse(FieldAskService::decideCanSend('staff', ['jobs.view', 'photos.upload']));
        $this->assertFalse(FieldAskService::decideCanSend('user', null));
        $this->assertFalse(FieldAskService::decideCanSend('', []));
    }

    public function test_rbac_permissions_beat_the_legacy_role(): void
    {
        // A manager whose RBAC roles leave out billing.edit cannot send.
        $this->assertFalse(FieldAskService::decideCanSend('manager', ['jobs.view']));
    }

    public function test_legacy_manager_with_no_rbac_rows_can_send(): void
    {
        $this->assertTrue(FieldAskService::decideCanSend('manager', null));
        $this->assertTrue(FieldAskService::decideCanSend('Manager', []));
    }

    public function test_can_send_reads_rbac_for_a_jwt_user(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn(['schedule.view', 'billing.edit']);
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $this->assertTrue(FieldAskService::canSend($db, ['id' => 3, 'role' => 'user']));
    }

    public function test_can_send_falls_back_to_role_when_rbac_is_missing(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willThrowException(new PDOException('no table'));

        $this->assertTrue(FieldAskService::canSend($db, ['id' => 3, 'role' => 'manager']));
        $this->assertFalse(FieldAskService::canSend($db, ['id' => 5, 'role' => 'staff']));
    }

    // ── Who gets it ──────────────────────────────────────────────────────────

    public function test_onsite_contact_with_email_is_asked(): void
    {
        $onsite = $this->person(31, 'Gaby', 'gaby@example.com', 'onsite');
        $site = $this->person(4, 'Darren', 'darren@example.com', 'site');
        $this->assertSame(31, FieldAskService::pickAskRecipient($onsite, $site)['contact_id']);
    }

    public function test_onsite_contact_without_email_falls_back_to_site_contact(): void
    {
        $onsite = $this->person(31, 'Gaby', null, 'onsite');
        $site = $this->person(4, 'Darren', 'darren@example.com', 'site');
        $this->assertSame(4, FieldAskService::pickAskRecipient($onsite, $site)['contact_id']);
        $this->assertSame(4, FieldAskService::pickAskRecipient(null, $site)['contact_id']);
    }

    public function test_nobody_with_an_email_means_nobody_to_ask(): void
    {
        $this->assertNull(FieldAskService::pickAskRecipient($this->person(31, 'Gaby', null, 'onsite'), $this->person(4, 'D', '', 'site')));
        $this->assertNull(FieldAskService::pickAskRecipient(null, null));
    }

    public function test_place_name_prefers_the_property_name(): void
    {
        $this->assertSame('Cambridge Apartments', FieldAskService::placeName(['property_name' => 'Cambridge Apartments', 'address' => '1 Main']));
        $this->assertSame('1 Main St', FieldAskService::placeName(['property_name' => '', 'address' => '1 Main St']));
        $this->assertSame('your property', FieldAskService::placeName([]));
    }

    // ── CASL ─────────────────────────────────────────────────────────────────

    public function test_unsubscribed_always_blocks(): void
    {
        $this->assertFalse(FieldAskService::decideConsent(true, true, ['ok' => true, 'type' => 'express'], true)['ok']);
    }

    public function test_company_managed_property_is_a_b2b_note(): void
    {
        $c = FieldAskService::decideConsent(false, true, ['ok' => false, 'reason' => 'no consent on record'], false);
        $this->assertTrue($c['ok']);
        $this->assertSame('b2b', $c['basis']);
    }

    public function test_homeowner_follows_the_consent_ledger(): void
    {
        $yes = FieldAskService::decideConsent(false, false, ['ok' => true, 'type' => 'implied', 'reason' => 'implied consent until 2027-08-01'], null);
        $this->assertTrue($yes['ok']);
        $this->assertSame('implied', $yes['basis']);

        $no = FieldAskService::decideConsent(false, false, ['ok' => false, 'reason' => 'implied consent expired'], true);
        $this->assertFalse($no['ok'], 'the ledger wins over the older contact columns');
        $this->assertStringContainsString('implied consent expired', $no['reason']);
    }

    public function test_without_a_ledger_the_crm_consent_dates_decide(): void
    {
        $this->assertTrue(FieldAskService::decideConsent(false, false, null, true)['ok']);
        $this->assertFalse(FieldAskService::decideConsent(false, false, null, false)['ok']);
        $this->assertFalse(FieldAskService::decideConsent(false, false, null, null)['ok']);
    }

    // ── Wording ──────────────────────────────────────────────────────────────

    public function test_fall_cleanup_matches_the_owners_approved_email(): void
    {
        [$subject, $body] = $this->render($this->cambridge());

        $this->assertSame('Cambridge Apartments: fall cleanup (photos from today)', $subject);
        $this->assertStringStartsWith("Hi Gaby,\n\nWe were at Cambridge Apartments this morning and took a few photos of the beds. They're attached.", $body);
        $this->assertStringContainsString("They're ready for their fall cleanup: we trim and prune the shrubs", $body);
        $this->assertStringContainsString('Just reply "yes" and I\'ll send the quote to Darren for sign-off and book a date with you.', $body);
        $this->assertStringEndsWith("Thanks,\nTim\nMowology · (778) 846-9273", $body);
    }

    public function test_aeration_has_its_own_wording(): void
    {
        [$subject, $body] = $this->render($this->cambridge(['product_name' => 'Aeration']));
        $this->assertSame('Cambridge Apartments: lawn aeration (photos from today)', $subject);
        $this->assertStringContainsString('photos of the lawn', $body);
        $this->assertStringContainsString('we pull small plugs out of the lawn', $body);
    }

    public function test_drafts_follow_the_house_rules(): void
    {
        foreach (['Fall Clean Up', 'Aeration', 'Hedge Trimming'] as $name) {
            [$subject, $body] = $this->render($this->cambridge(['product_name' => $name]));
            $text = $subject . "\n" . $body;
            $this->assertStringNotContainsString('$', $text, "$name: no price in an ask");
            $this->assertStringNotContainsString('!', $text, "$name: no exclamation marks");
            $this->assertStringNotContainsStringIgnoringCase('just checking in', $text);
            $this->assertSame(1, substr_count($body, '?'), "$name: one question, one ask");
            $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $text, "$name: every placeholder filled");
        }
    }

    public function test_same_person_gets_the_quote_themselves(): void
    {
        $darren = $this->person(4, 'Darren', 'darren@example.com', 'site');
        [, $body] = $this->render($this->cambridge(['ask_person' => $darren, 'billing_person' => $darren]));
        $this->assertStringContainsString("I'll send you the quote and book a date.", $body);
        $this->assertStringNotContainsString('sign-off', $body);
    }

    public function test_product_pitch_overrides_the_built_in_one(): void
    {
        [, $body] = $this->render($this->cambridge(['field_ask_pitch' => 'The hedges have pushed out over the path.']));
        $this->assertStringContainsString('The hedges have pushed out over the path.', $body);
        $this->assertStringNotContainsString('trim and prune', $body);
    }

    public function test_when_phrase_follows_the_clock(): void
    {
        $this->assertSame(['this morning', 'today'], FieldAskService::whenPhrase('2026-10-06 08:10:00', self::NOW));
        $this->assertSame(['this afternoon', 'today'], FieldAskService::whenPhrase('2026-10-06 13:10:00', self::NOW));
        $this->assertSame(['yesterday', 'yesterday'], FieldAskService::whenPhrase('2026-10-05 13:10:00', self::NOW));
        $this->assertSame(['on Oct 2', 'Oct 2'], FieldAskService::whenPhrase('2026-10-02 13:10:00', self::NOW));
    }

    public function test_service_key_matches_label_or_name(): void
    {
        $this->assertSame('fall_cleanup', FieldAskService::serviceKey(['product_name' => 'Fall Clean Up']));
        $this->assertSame('fall_cleanup', FieldAskService::serviceKey(['field_label' => 'Fall cleanup', 'product_name' => 'Seasonal']));
        $this->assertSame('aeration', FieldAskService::serviceKey(['name' => 'Core Aeration']));
        $this->assertNull(FieldAskService::serviceKey(['product_name' => 'Hedge Trimming']));
    }

    // ── Learning from edits ──────────────────────────────────────────────────

    public function test_edit_is_learned_with_placeholders_and_reused_for_the_next_customer(): void
    {
        [$subject, $body, $vars] = $this->render($this->cambridge());
        $edited = str_replace('Would you like us to go ahead?', 'Shall we book it in before the rain sets in?', $body);
        $this->assertTrue(FieldAskService::isEdited($body, $edited));

        $learned = FieldAskService::unfill($edited, $vars);
        $this->assertStringContainsString('Hi {first_name},', $learned);
        $this->assertStringContainsString('We were at {place} {when}', $learned);
        $this->assertStringContainsString('{next_step}', $learned);
        $this->assertStringNotContainsString('Gaby', $learned);
        $this->assertStringNotContainsString('Darren', $learned);

        // Next customer, same service: Tim's sentence, their details.
        $next = $this->cambridge([
            'place'          => 'Oak Court',
            'created_at'     => '2026-10-06 14:00:00',
            'ask_person'     => $this->person(77, 'Priya', 'p@example.com', 'onsite'),
            'billing_person' => $this->person(78, 'Sam', 's@example.com', 'site'),
        ]);
        $nextBody = FieldAskService::fill($learned, FieldAskService::vars($next, 'Tim', self::NOW));
        $this->assertStringStartsWith('Hi Priya,', $nextBody);
        $this->assertStringContainsString('We were at Oak Court this afternoon', $nextBody);
        $this->assertStringContainsString('Shall we book it in before the rain sets in?', $nextBody);
        $this->assertStringContainsString('send the quote to Sam for sign-off', $nextBody);
    }

    public function test_whitespace_only_changes_are_not_edits(): void
    {
        $this->assertFalse(FieldAskService::isEdited("Hi Gaby,\n\nThanks", "Hi Gaby,\n\n Thanks  "));
    }

    // ── Photos ───────────────────────────────────────────────────────────────

    public function test_at_most_four_photos_under_the_size_cap(): void
    {
        $c = fn($n, $size) => ['path' => "/p/$n.jpg", 'size' => $size];
        $picked = FieldAskService::pickAttachments([$c(1, 300000), $c(2, 300000), $c(3, 0), $c(4, 300000), $c(5, 300000), $c(6, 300000)], 4, 6291456);
        $this->assertSame(['/p/1.jpg', '/p/2.jpg', '/p/4.jpg', '/p/5.jpg'], array_column($picked, 'path'));

        $big = FieldAskService::pickAttachments([$c(1, 4000000), $c(2, 4000000), $c(3, 1000000)], 4, 6291456);
        $this->assertSame(['/p/1.jpg', '/p/3.jpg'], array_column($big, 'path'), 'skips a photo that would break the cap');
    }

    // ── The email ────────────────────────────────────────────────────────────

    public function test_email_is_a_plain_letter_with_the_casl_footer(): void
    {
        $html = FieldAskService::emailHtml("Hi Gaby,\n\nLine one <b>.\n\nThanks,\nTim",
            ['name' => 'Mowology Landscaping', 'address' => '2845 West 15th Ave, Vancouver, BC'],
            'https://mowology.ca/unsubscribe.php?email=g&token=t', 'Cambridge Apartments');

        $this->assertStringContainsString('<p style="margin:0 0 14px">Hi Gaby,</p>', $html);
        $this->assertStringContainsString('Line one &lt;b&gt;.', $html, 'the sender\'s text is escaped');
        $this->assertStringContainsString('Thanks,<br />' . "\n" . 'Tim', $html);
        $this->assertStringContainsString('Mowology Landscaping · 2845 West 15th Ave, Vancouver, BC', $html);
        $this->assertStringContainsString('href="https://mowology.ca/unsubscribe.php?email=g&amp;token=t"', $html);
        $this->assertStringContainsString('we look after Cambridge Apartments', $html);
        $this->assertStringNotContainsString('<table', $html, 'not the branded wrapper');
    }

    // ── Sam ──────────────────────────────────────────────────────────────────

    public function test_a_reply_after_the_ask_is_a_reply(): void
    {
        $this->assertSame('replied', FieldAskService::classifyAsk('2026-10-06 09:00:00', '2026-10-06 11:30:00', self::NOW));
    }

    public function test_an_older_message_is_not_a_reply_to_this_ask(): void
    {
        $this->assertSame('waiting', FieldAskService::classifyAsk('2026-10-06 09:00:00', '2026-10-01 11:30:00', self::NOW));
    }

    public function test_no_reply_after_seven_days_is_silent(): void
    {
        $this->assertSame('waiting', FieldAskService::classifyAsk('2026-10-01 09:00:00', null, self::NOW));
        $this->assertSame('silent', FieldAskService::classifyAsk('2026-09-28 09:00:00', null, self::NOW));
    }
}
