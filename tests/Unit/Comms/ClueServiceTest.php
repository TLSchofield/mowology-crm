<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The clues check — pure detectors and proposals, on the real strings from 2026-10-06/07
 * (STRATA PLAN BCS-2106 paying Alexandra Bee; Marianna Pandy, Monica Nicule and Jodi Peacock's
 * signatures) and the negatives that must never fire (postal codes, invoice numbers, sentences).
 */
class ClueServiceTest extends TestCase
{
    // ── Strata plan numbers ─────────────────────────────────────────────

    /** @dataProvider planCases */
    public function test_strata_plans_are_found(string $text, string $plan): void
    {
        $found = ClueService::strataPlans($text);
        $this->assertNotEmpty($found, $text);
        $this->assertSame($plan, $found[0]['plan'], $text);
    }

    public static function planCases(): array
    {
        return [
            'e-Transfer sender (BCS 2106)'  => ['STRATA PLAN BCS-2106', 'BCS 2106'],
            'plan glued to a name'          => ['VR738 Laburnum Heights', 'VR 738'],
            'VR plan with a dash'           => ['VR15-40', 'VR 15-40'],
            'LMS with a space'              => ['LMS 1234', 'LMS 1234'],
            'owners, no space'              => ['Owners Strata Plan EPS567', 'EPS 567'],
            'The Owners, Strata Plan'       => ['THE OWNERS, STRATA PLAN NW 2345', 'NW 2345'],
            'strata corp + #'               => ['Strata Corp #NES 88', 'NES 88'],
            'SP shorthand'                  => ['SP KAS 1901', 'KAS 1901'],
            'lowercase after a lead-in'     => ['payment from strata plan lms1234 for the fall', 'LMS 1234'],
            'NWS beats NW'                  => ['NWS 999', 'NWS 999'],
            'BCP / LMP'                     => ['Strata Plan BCP12345', 'BCP 12345'],
            'Interac sender, upper case'    => ['THE OWNERS STRATA PLAN VAS 2311', 'VAS 2311'],
            'bank line'                     => ['E-TRANSFER 105338947 STRATA PLAN BCS2106', 'BCS 2106'],
            'invoice number next to a plan' => ['INV-2026-0042 for LMS 1234', 'LMS 1234'],
        ];
    }

    /** @dataProvider notPlanCases */
    public function test_no_false_strata_plans(string $text): void
    {
        $this->assertSame([], ClueService::strataPlans($text), $text);
    }

    public static function notPlanCases(): array
    {
        return array_map(fn($s) => [$s], [
            'V6K 1Z3',
            'Vancouver BC V5R 2N4',
            'INV-2026-0042',
            'QUO-2026-0001',
            'JOB-2026-0117',
            '1234 NW 2nd Ave',
            'Interac e-Transfer from JOHN SMITH',
            'ALAN INGLIS',
            'nw 12 street',            // lower case, no lead-in
            'BCSA 2106',               // not a prefix
            'EPS567X',                 // glued letters
            'Ref 1290384 LMS2106A',
            'Call 604-555-0100',
        ]);
    }

    public function test_plan_key_and_billing_names_compare_loosely(): void
    {
        $this->assertSame(ClueService::planKey('BCS 2106'), ClueService::planKey('bcs-2106'));
        $this->assertSame('BCS 2106', ClueService::strataPlans('bcs 2106', true)[0]['plan']);
    }

    // ── Strata proposals ────────────────────────────────────────────────

    public function test_single_family_paid_by_a_strata_becomes_a_strata(): void
    {
        $p = ClueService::strataProposal(['id' => 843, 'address' => '2505 West 8th Avenue', 'property_type' => 'single_family', 'billing_entity_name' => null], 'BCS 2106');
        $this->assertSame('strata_plan', $p['kind']);
        $this->assertSame(['property_type' => 'strata', 'billing_entity_name' => 'BCS 2106'], $p['ops'][0]['set']);
        $this->assertSame(['property_type' => 'single_family', 'billing_entity_name' => null], $p['ops'][0]['before']);
        $this->assertSame('make 2505 W 8th a strata called BCS 2106?', $p['summary_tail']);
    }

    public function test_strata_with_the_same_plan_needs_nothing(): void
    {
        $this->assertNull(ClueService::strataProposal(['id' => 1, 'address' => 'x', 'property_type' => 'strata', 'billing_entity_name' => 'BCS 2106'], 'BCS 2106'));
        $this->assertNull(ClueService::strataProposal(['id' => 1, 'address' => 'x', 'property_type' => 'strata', 'billing_entity_name' => 'Strata Plan BCS-2106'], 'BCS 2106'));
        // A strata billed under a name without a plan number is left alone.
        $this->assertNull(ClueService::strataProposal(['id' => 1, 'address' => 'x', 'property_type' => 'strata', 'billing_entity_name' => 'The Owners of Laburnum Heights'], 'VR 738'));
    }

    public function test_billing_name_with_another_plan_is_a_mismatch(): void
    {
        $p = ClueService::strataProposal(['id' => 9, 'address' => '1450 Laburnum Street', 'property_type' => 'strata', 'billing_entity_name' => 'VR 738'], 'BCS 2106');
        $this->assertSame('strata_mismatch', $p['kind']);
        $this->assertSame('VR 738', $p['was']);
        $this->assertSame(['billing_entity_name' => 'BCS 2106'], $p['ops'][0]['set']);
        $this->assertStringContainsString('is billed as VR 738', $p['summary_tail']);
    }

    public function test_strata_without_a_billing_name_gets_one(): void
    {
        $p = ClueService::strataProposal(['id' => 9, 'address' => '100 Main Street', 'property_type' => 'strata', 'billing_entity_name' => ''], 'LMS 1234');
        $this->assertSame(['billing_entity_name' => 'LMS 1234'], $p['ops'][0]['set']);
        $this->assertSame('bill 100 Main as LMS 1234?', $p['summary_tail']);
    }

    public function test_short_address(): void
    {
        $this->assertSame('2505 W 8th', ClueService::shortAddress('2505 West 8th Avenue'));
        $this->assertSame('1450 Laburnum', ClueService::shortAddress('1450 Laburnum Street, Vancouver'));
        $this->assertSame('Broadway', ClueService::shortAddress('Broadway'));
    }

    // ── Job titles in signatures ────────────────────────────────────────

    public function test_marianna_strata_manager_at_quay_pacific(): void
    {
        $r = ClueService::detectRole("Marianna Pandy\nStrata Manager, Quay Pacific Property Management Ltd.\n#200-1234 Main St\nT: 604-555-0100", 'Marianna Pandy');
        $this->assertSame('property_manager', $r['role']);
        $this->assertSame('Strata Manager', $r['title']);
        $this->assertSame('Quay Pacific Property Management Ltd.', $r['company']);
        $this->assertFalse($r['billing']);
    }

    public function test_monica_managing_agent_for_property_and_strata(): void
    {
        $r = ClueService::detectRole("Monica Nicule\nManaging Agent for Property and Strata, MacDonald Commercial Real Estate Services\n604-555-0101", 'Monica Nicule');
        $this->assertSame('property_manager', $r['role']);
        $this->assertSame('MacDonald Commercial Real Estate Services', $r['company']);
    }

    public function test_jodi_accountant_company_on_next_line(): void
    {
        $r = ClueService::detectRole("Jodi Peacock\nAccountant\nVancouver Management Ltd.\nF: 604-555-0000", 'Jodi Peacock');
        $this->assertSame('billing_contact', $r['role']);
        $this->assertTrue($r['billing']);
        $this->assertSame('Vancouver Management Ltd.', $r['company']);
    }

    /** @dataProvider titleCases */
    public function test_titles_map_to_roles(string $sig, string $role, ?string $company): void
    {
        $r = ClueService::detectRole($sig);
        $this->assertNotNull($r, $sig);
        $this->assertSame($role, $r['role'], $sig);
        $this->assertSame($company, $r['company'], $sig);
    }

    public static function titleCases(): array
    {
        return [
            ["Bob Jones\nTreasurer, Strata Council\nThe Owners, Strata Plan VR 738", 'strata_rep', 'The Owners, Strata Plan VR 738'],
            ["Ann Lee\nCouncil President\nStrata Plan LMS 1234", 'strata_rep', 'Strata Plan LMS 1234'],
            ["Accounts Payable | FirstService Residential", 'billing_contact', 'FirstService Residential'],
            ["Sam Wu\nSenior Property Manager at Rancho Management Services", 'property_manager', 'Rancho Management Services'],
            ["Dave Kim\nOwner | Kim's Plumbing", 'owner', "Kim's Plumbing"],
            ["Pat\nSite Supervisor\n604-555-0123", 'site_supervisor', null],
            ["Lee\nBookkeeper - Associa Ltd.", 'billing_contact', 'Associa Ltd.'],
        ];
    }

    /** @dataProvider notTitleCases */
    public function test_sentences_are_not_titles(string $sig): void
    {
        $this->assertNull(ClueService::detectRole($sig), $sig);
    }

    public static function notTitleCases(): array
    {
        return array_map(fn($s) => [$s], [
            'Our accountant will send the cheque next week.',
            'I am the accountant for the strata',
            'Please ask the property manager about it',
            'The Owners, Strata Plan LMS 1234',
            "Thanks\nMarianna",
            'Owners meeting is Tuesday',
        ]);
    }

    public function test_company_cleaning_rejects_addresses_and_phones(): void
    {
        $this->assertNull(ClueService::cleanCompany('#200-1234 Main St'));
        $this->assertNull(ClueService::cleanCompany('T: 604-555-0100'));
        $this->assertNull(ClueService::cleanCompany('mpandy@quaypacific.com'));
        $this->assertNull(ClueService::cleanCompany('www.quaypacific.com'));
        $this->assertNull(ClueService::cleanCompany('1234 West Broadway'));
        $this->assertSame('1355183 B.C. Ltd.', ClueService::cleanCompany('1355183 B.C. Ltd.'));
    }

    // ── Company matching ────────────────────────────────────────────────

    public function test_company_matching(): void
    {
        $cos = [
            ['id' => 1, 'company_name' => 'Quay Pacific Property Management'],
            ['id' => 2, 'company_name' => 'Vancouver Management Ltd'],
            ['id' => 3, 'company_name' => 'MacDonald Commercial'],
            ['id' => 4, 'company_name' => 'Vancouver Landscaping'],
        ];
        $this->assertSame(1, ClueService::matchCompany('Quay Pacific Property Management Ltd.', $cos)['id']);
        $this->assertSame(100, ClueService::matchCompany('Quay Pacific Property Management Ltd.', $cos)['score']);
        $this->assertSame(2, ClueService::matchCompany('Vancouver Management', $cos)['id']);
        $this->assertSame(3, ClueService::matchCompany('MacDonald Commercial Real Estate Services', $cos)['id']);
        $this->assertSame(1, ClueService::matchCompany('Quay Pacfic Property Managment', $cos)['id']);   // typos
        $this->assertNull(ClueService::matchCompany('Vancouver', $cos));                                // one word never matches loosely
        $this->assertNull(ClueService::matchCompany('FirstService Residential', $cos));
    }

    // ── Role proposals ──────────────────────────────────────────────────

    private function marianna(array $over = []): array
    {
        return $over + ['id' => 501, 'first_name' => 'Marianna', 'last_name' => 'Pandy', 'email' => 'mpandy@quaypacific.com',
                        'phone' => '', 'mobile' => '', 'contact_role' => null, 'employer_company_id' => null];
    }

    public function test_role_and_employer_for_a_known_firm(): void
    {
        $role = ClueService::detectRole("Marianna Pandy\nStrata Manager, Quay Pacific Property Management Ltd.", 'Marianna Pandy');
        $match = ['id' => 7, 'name' => 'Quay Pacific Property Management', 'score' => 100];
        $p = ClueService::roleProposal($this->marianna(), $role, $match);
        $this->assertCount(1, $p['ops']);
        $this->assertSame(['contact_role' => 'property_manager', 'employer_company_id' => 7], $p['ops'][0]['set']);
        $this->assertSame('Marianna Pandy signs “Strata Manager, Quay Pacific Property Management Ltd.” — set as property manager and link to Quay Pacific Property Management?', $p['summary']);
    }

    public function test_unknown_firm_is_created_first(): void
    {
        $role = ClueService::detectRole("Monica Nicule\nManaging Agent for Property and Strata, MacDonald Commercial Real Estate Services");
        $p = ClueService::roleProposal($this->marianna(['id' => 502, 'first_name' => 'Monica', 'last_name' => 'Nicule', 'email' => '']), $role, null);
        $this->assertSame('create_company', $p['ops'][0]['op']);
        $this->assertSame('MacDonald Commercial Real Estate Services', $p['ops'][0]['name']);
        $this->assertSame('property_manager', $p['ops'][0]['type']);
        $this->assertSame('@company', $p['ops'][1]['set']['employer_company_id']);
        $this->assertStringContainsString('company:new', $p['pattern']);
        $this->assertStringContainsString('add MacDonald Commercial Real Estate Services as a company', $p['summary']);
    }

    public function test_accountant_is_billing_contact_not_quote_signer(): void
    {
        $role = ClueService::detectRole("Jodi Peacock\nAccountant\nVancouver Management Ltd.");
        $jodi = $this->marianna(['id' => 503, 'first_name' => 'Jodi', 'last_name' => 'Peacock']);
        $p = ClueService::roleProposal($jodi, $role, ['id' => 2, 'name' => 'Vancouver Management', 'score' => 100], ['id' => 2, 'billing_contact_id' => null]);
        $this->assertSame('Jodi Peacock is the accountant at Vancouver Management — billing contact, not the quote signer. Set as billing contact?', $p['summary']);
        $this->assertSame(['billing_contact_id' => 503], $p['ops'][0]['set']);      // the firm's billing contact was empty
        $this->assertSame('billing_contact', $p['ops'][1]['set']['contact_role']);
    }

    public function test_nothing_to_suggest_when_already_on_file(): void
    {
        $role = ClueService::detectRole("Marianna Pandy\nStrata Manager, Quay Pacific Property Management Ltd.");
        $p = ClueService::roleProposal($this->marianna(['contact_role' => 'property_manager', 'employer_company_id' => 7]), $role, ['id' => 7, 'name' => 'Quay Pacific', 'score' => 100]);
        $this->assertNull($p);
    }

    public function test_quote_signer_flag_and_fix(): void
    {
        $role = ClueService::detectRole("Jodi Peacock\nAccountant\nVancouver Management Ltd.");
        $jodi = $this->marianna(['id' => 503, 'first_name' => 'Jodi', 'last_name' => 'Peacock']);
        $p = ClueService::quoteSignerProposal($jodi, $role, [['id' => 40, 'address' => '1200 West 10th Avenue']], [], [['id' => 9, 'quote_number' => 'QUO-2026-0142']]);
        $this->assertSame(['quote_contact_id' => null], $p['ops'][0]['set']);
        $this->assertSame(['quote_contact_id' => 503], $p['ops'][0]['before']);
        $this->assertStringContainsString('Also the Send-to on QUO-2026-0142', $p['summary']);

        $flag = ClueService::quoteSignerProposal($jodi, $role, [], [], [['id' => 9, 'quote_number' => 'QUO-2026-0142']]);
        $this->assertSame([], $flag['ops']);
        $this->assertStringStartsWith('Jodi Peacock (accountant) is the Send-to on QUO-2026-0142', $flag['summary']);

        $this->assertNull(ClueService::quoteSignerProposal($jodi, $role, [], [], []));
        $pm = ClueService::detectRole("Strata Manager, Quay Pacific");
        $this->assertNull(ClueService::quoteSignerProposal($jodi, $pm, [['id' => 40, 'address' => 'x']], [], []));
    }

    // ── Details in the signature ────────────────────────────────────────

    public function test_contact_details(): void
    {
        $d = ClueService::contactDetails("T: 604-555-0100 | C: (604) 555-0199\nF: 604-555-0000\nOffice (778) 846-9273\nmpandy@QuayPacific.com\ntim@mowology.ca");
        $this->assertSame(['6045550100', '6045550199'], array_column($d['phones'], 'digits'));
        $this->assertFalse($d['phones'][0]['mobile']);
        $this->assertTrue($d['phones'][1]['mobile']);
        $this->assertSame(['mpandy@quaypacific.com'], $d['emails']);
    }

    public function test_details_fill_only_empty_fields(): void
    {
        $d = ClueService::contactDetails("Monica Nicule\n604-555-0101\nC: 604-555-0102\nmonica@macdonaldcommercial.ca");
        $monica = ['id' => 502, 'first_name' => 'Monica', 'last_name' => 'Nicule', 'email' => '', 'phone' => '', 'mobile' => ''];
        $p = ClueService::detailsProposal($monica, $d);
        $this->assertSame(['phone' => '604-555-0101', 'mobile' => '604-555-0102', 'email' => 'monica@macdonaldcommercial.ca'], $p['ops'][0]['set']);
        $this->assertSame('details:phone+email', $p['pattern']);

        $full = ['id' => 502, 'first_name' => 'M', 'last_name' => 'N', 'email' => 'other@x.ca', 'phone' => '(604) 555-0101', 'mobile' => '604 555 9999'];
        $this->assertNull(ClueService::detailsProposal($full, $d));     // known number, phone + mobile + email all taken

        // An unlabelled number never lands in mobile (texts go there), even when phone is taken.
        $landline = ClueService::contactDetails("Monica Nicule\n604-555-0101");
        $this->assertNull(ClueService::detailsProposal(['phone' => '604-999-0000', 'mobile' => ''] + $monica, $landline));
    }

    // ── Signatures at ingest ────────────────────────────────────────────

    public function test_signature_after_sign_off_survives_the_quoted_history(): void
    {
        $body = "Hi Tim,\n\nCouncil approved the quote. Please book it in.\n\nThanks,\nMarianna Pandy\nStrata Manager, Quay Pacific Property Management Ltd.\nT: 604-555-0100\n\nOn Mon, Oct 5, 2026 at 9:00 AM Tim <office@mowology.ca> wrote:\n> Here is the quote";
        $this->assertSame("Marianna Pandy\nStrata Manager, Quay Pacific Property Management Ltd.\nT: 604-555-0100", SalesInboxService::signature($body));
    }

    public function test_signature_dash_dash_and_plain_block(): void
    {
        $this->assertSame("Jodi Peacock\nAccountant", SalesInboxService::signature("Paid today.\n-- \nJodi Peacock\nAccountant"));
        $this->assertSame("Monica Nicule\nManaging Agent", SalesInboxService::signature("Please send the invoice.\n\nMonica Nicule\nManaging Agent"));
        $this->assertSame('', SalesInboxService::signature('ok'));
        $this->assertLessThanOrEqual(300, mb_strlen(SalesInboxService::signature("Hi\n\nThanks,\n" . str_repeat("Long disclaimer line here\n", 40))));
    }

    // ── Hashing, staleness, learning, brief ─────────────────────────────

    public function test_hash_ignores_before_values(): void
    {
        $a = [['op' => 'update', 'table' => 'properties', 'id' => 1, 'set' => ['property_type' => 'strata'], 'before' => ['property_type' => 'condo']]];
        $b = [['op' => 'update', 'table' => 'properties', 'id' => 1, 'set' => ['property_type' => 'strata'], 'before' => ['property_type' => 'single_family']]];
        $this->assertSame(ClueService::opsHash($a), ClueService::opsHash($b));
    }

    public function test_stale_when_done_by_hand_or_changed(): void
    {
        $ops = [['op' => 'update', 'table' => 'properties', 'id' => 843, 'set' => ['property_type' => 'strata', 'billing_entity_name' => 'BCS 2106'],
                 'before' => ['property_type' => 'single_family', 'billing_entity_name' => null]]];
        $this->assertFalse(ClueService::isStale($ops, ['properties:843' => ['property_type' => 'single_family', 'billing_entity_name' => null]]));
        $this->assertTrue(ClueService::isStale($ops, ['properties:843' => ['property_type' => 'strata', 'billing_entity_name' => 'BCS 2106']]));
        $this->assertTrue(ClueService::isStale($ops, ['properties:843' => ['property_type' => 'condo', 'billing_entity_name' => null]]));
        $this->assertTrue(ClueService::isStale($ops, []));
        $this->assertFalse(ClueService::isStale([], []));     // a flag (no ops) waits for Tim
    }

    public function test_patterns_tim_keeps_rejecting_go_quiet(): void
    {
        $this->assertFalse(ClueService::patternMuted(0, 2));
        $this->assertTrue(ClueService::patternMuted(0, 3));
        $this->assertTrue(ClueService::patternMuted(1, 3));
        $this->assertFalse(ClueService::patternMuted(2, 3));
        $this->assertFalse(ClueService::patternMuted(9, 4));
    }

    public function test_brief_items_and_merge(): void
    {
        $item = ClueService::briefItem(['id' => 12, 'kind' => 'strata_plan', 'summary' => 'Payment came from Strata Plan BCS 2106 — make 2505 W 8th a strata called BCS 2106?', 'source_at' => '2026-10-06 09:12:00']);
        $this->assertSame(['key' => 'clue:12', 'kind' => 'clue:strata_plan', 'priority' => 2,
                           'text' => 'Payment came from Strata Plan BCS 2106 — make 2505 W 8th a strata called BCS 2106?',
                           'url' => ClueService::CARD_URL, 'value' => null, 'since' => '2026-10-06'], $item);
        $b = ClueService::mergeBrief(['head' => 'penny', 'headline' => 'x', 'items' => [['key' => 'a']], 'count' => 1], [$item]);
        $this->assertSame(2, $b['count']);
        $this->assertSame('clue:12', $b['items'][1]['key']);
        $this->assertSame(['head' => 'yui', 'items' => [], 'count' => 0], ClueService::mergeBrief(['head' => 'yui', 'items' => [], 'count' => 0], []));
    }

    public function test_card_row_buttons(): void
    {
        $row = ['id' => 3, 'kind' => 'quote_signer', 'owner' => 'yui', 'summary' => 's', 'evidence' => 'e', 'source' => 'email', 'source_at' => null,
                'subject_type' => 'contact', 'subject_id' => 503, 'change' => ['ops' => [], 'quotes' => [['id' => 9, 'quote_number' => 'QUO-2026-0142']]]];
        $c = ClueService::forCard($row);
        $this->assertSame('Got it', $c['apply']);
        $this->assertSame('/crm/quotes/view.php?id=9', $c['url']);
        $row['change'] = ['ops' => [['op' => 'update']]];
        $row['subject_type'] = 'property';
        $row['subject_id'] = 843;
        $this->assertSame('Apply', ClueService::forCard($row)['apply']);
        $this->assertSame('/crm/properties/view.php?id=843', ClueService::forCard($row)['url']);
    }
}
