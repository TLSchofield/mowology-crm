<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Yui, the comms / client relations head — the pure rules: promises, account flags, renewal
 * windows, arrears picking the right person, the inbox lane, texts, drafts and her brief.
 */
class YuiRulesTest extends TestCase
{
    private const NOW = '2026-10-06 12:00:00';

    // ── Promises ─────────────────────────────────────────────────────────

    /** @dataProvider promiseCases */
    public function test_approvals_are_promises(string $s): void
    {
        $this->assertTrue(YuiRules::isPromise($s), $s);
    }

    public static function promiseCases(): array
    {
        return array_map(fn($s) => [$s], [
            'Council approved the quote',
            'Hi Tim, council approved the quote at last night\'s meeting. Thanks, Marianna',
            'The board has approved the fall cleanup.',
            'We approved your proposal for both buildings.',
            'Please go ahead with the hedges.',
            'Yes',
            'Owners approved it, you can go ahead.',
            'We accept the quote for 1450 Laburnum.',
            'I signed the contract this morning.',
            'Please proceed.',
        ]);
    }

    /** @dataProvider notPromiseCases */
    public function test_questions_negations_and_conditions_are_not(string $s): void
    {
        $this->assertFalse(YuiRules::isPromise($s), $s);
    }

    public static function notPromiseCases(): array
    {
        return array_map(fn($s) => [$s], [
            'Council has not approved the quote yet.',
            "Council hasn't approved the quote.",
            'Once council approved the quote I will let you know.',
            'If the owners approved it, when could you start?',
            'Should we go ahead?',
            'Before you go ahead, can you send photos?',
            'What does the quote include?',
            '',
        ]);
    }

    private static function msg(int $cid, string $at, string $snippet, array $extra = []): array
    {
        return $extra + ['message_key' => "m-{$cid}-{$at}", 'contact_id' => $cid, 'first_name' => 'Marianna', 'employer_company_id' => 0,
                         'channel' => 'email', 'from_addr' => 'm@strata.ca', 'subject' => 'Re: fall cleanup', 'snippet' => $snippet, 'sent_at' => $at];
    }

    public function test_an_approval_with_no_accepted_quote_after_it_is_a_promise(): void
    {
        $items = YuiRules::promises([self::msg(7, '2026-09-11 10:00:00', 'Council approved the quote')], [], self::NOW);
        $this->assertCount(1, $items);
        $this->assertSame('Marianna: "Council approved the quote" (Sep 11) — no accepted quote in the CRM', $items[0]['text']);
        $this->assertSame(1, $items[0]['priority']);
        $this->assertSame('promise', $items[0]['kind']);
        $this->assertMatchesRegularExpression('/^yui:promise:7:[0-9a-f]{12}$/', $items[0]['key']);
    }

    public function test_a_quote_accepted_after_the_message_keeps_the_promise(): void
    {
        $m = [self::msg(7, '2026-09-11 10:00:00', 'Council approved the quote', ['employer_company_id' => 40])];
        $after = fn(array $q) => YuiRules::promises($m, ['accepted' => [$q + ['contact_id' => 0, 'site_contact_id' => 0, 'company_id' => 0, 'pm_company_id' => 0]]], self::NOW);
        $this->assertSame([], $after(['contact_id' => 7, 'accepted_at' => '2026-09-12 09:00:00']));
        $this->assertSame([], $after(['site_contact_id' => 7, 'accepted_at' => '2026-09-12 09:00:00']));
        $this->assertSame([], $after(['pm_company_id' => 40, 'accepted_at' => '2026-09-12 09:00:00']));   // her firm's building
        $this->assertSame([], $after(['company_id' => 40, 'accepted_at' => '2026-09-11 10:00:00']));
        // Accepted BEFORE the message, or someone else's: still a promise.
        $this->assertCount(1, $after(['contact_id' => 7, 'accepted_at' => '2026-09-01 09:00:00']));
        $this->assertCount(1, $after(['contact_id' => 8, 'accepted_at' => '2026-09-12 09:00:00']));
    }

    public function test_promises_skip_what_the_inbox_or_sam_already_shows_and_handled_ones(): void
    {
        $m = self::msg(7, '2026-09-11 10:00:00', 'Council approved the quote');
        $this->assertSame([], YuiRules::promises([$m], ['skip_keys' => [$m['message_key']]], self::NOW));
        $this->assertSame([], YuiRules::promises([$m], ['skip_contacts' => [7]], self::NOW));
        $key = YuiRules::promises([$m], [], self::NOW)[0]['key'];
        $this->assertSame([], YuiRules::promises([$m], ['hidden' => [$key]], self::NOW));
        // Older than the window, and automated replies, never count.
        $this->assertSame([], YuiRules::promises([self::msg(7, '2026-07-01 10:00:00', 'Council approved the quote')], [], self::NOW));
        $this->assertSame([], YuiRules::promises([self::msg(7, '2026-09-11 10:00:00', 'Go ahead', ['from_addr' => 'noreply@portal.ca'])], [], self::NOW));
    }

    public function test_one_promise_per_contact_their_latest(): void
    {
        $items = YuiRules::promises([
            self::msg(7, '2026-09-11 10:00:00', 'Council approved the quote'),
            self::msg(7, '2026-09-20 10:00:00', 'Please go ahead with both'),
        ], [], self::NOW);
        $this->assertCount(1, $items);
        $this->assertSame('2026-09-20 10:00:00', $items[0]['at']);
    }

    // ── Accounts ─────────────────────────────────────────────────────────

    private static function firm(array $over = []): array
    {
        return $over + ['id' => 12, 'name' => 'Vancouver Management', 'type' => 'property_manager', 'primary_contact_id' => 1, 'billing_contact_id' => 2,
                        'billing_email' => '', 'contacts' => [1, 2],
                        'people' => [1 => ['first_name' => 'Alena', 'email' => 'alena@vm.ca'], 2 => ['first_name' => 'Jodi', 'email' => 'jodi@vm.ca']]];
    }

    private static function flags(array $items): array
    {
        return array_map(fn($i) => $i['flag'] . ':' . substr($i['key'], strrpos($i['key'], ':') + 1), $items);
    }

    public function test_a_complete_firm_raises_nothing(): void
    {
        $this->assertSame([], YuiRules::accountFlags([self::firm()], [], []));
    }

    public function test_firm_with_no_contacts(): void
    {
        $items = YuiRules::accountFlags([self::firm(['contacts' => [], 'people' => [], 'primary_contact_id' => 0, 'billing_contact_id' => 0])], [], []);
        $this->assertSame(['no_contacts:12'], self::flags($items));
        $this->assertSame('Vancouver Management has no contacts in the CRM', $items[0]['text']);
        $this->assertSame('/crm/companies/view.php?id=12', $items[0]['url']);
        $this->assertSame(3, $items[0]['priority']);
    }

    public function test_firm_with_people_but_no_primary(): void
    {
        $items = YuiRules::accountFlags([self::firm(['primary_contact_id' => 0])], [], []);
        $this->assertSame(['no_primary:12'], self::flags($items));
        // A primary id that points at a deleted contact is no primary either.
        $items = YuiRules::accountFlags([self::firm(['primary_contact_id' => 99])], [], []);
        $this->assertSame(['no_primary:12'], self::flags($items));
    }

    public function test_primary_or_billing_contact_without_email(): void
    {
        $f = self::firm();
        $f['people'][1]['email'] = '';
        $f['people'][2]['email'] = 'not-an-email';
        $items = YuiRules::accountFlags([$f], [], []);
        $this->assertSame(['primary_no_email:12', 'billing_no_email:12'], self::flags($items));
        $this->assertSame("Vancouver Management's primary contact, Alena, has no email", $items[0]['text']);
        // A company billing email covers the billing person.
        $f['billing_email'] = 'ap@vm.ca';
        $this->assertSame(['primary_no_email:12'], self::flags(YuiRules::accountFlags([$f], [], [])));
    }

    public function test_pm_building_with_no_quote_contact_or_strata_rep(): void
    {
        $props = [
            ['id' => 31, 'address' => '1450 Laburnum St', 'firm_id' => 12, 'firm_name' => 'Vancouver Management', 'has_quote_contact' => false, 'has_strata_rep' => false],
            ['id' => 32, 'address' => '200 Oak St', 'firm_id' => 12, 'firm_name' => 'Vancouver Management', 'has_quote_contact' => true, 'has_strata_rep' => false],
            ['id' => 33, 'address' => '9 Elm St', 'firm_id' => 12, 'firm_name' => 'Vancouver Management', 'has_quote_contact' => false, 'has_strata_rep' => true],
        ];
        $items = YuiRules::accountFlags([], $props, []);
        $this->assertSame(['pm_no_contact:31'], self::flags($items));
        $this->assertSame('1450 Laburnum St (managed by Vancouver Management) has no quote contact or strata rep', $items[0]['text']);
        $this->assertSame('/crm/properties/view.php?id=31', $items[0]['url']);
    }

    public function test_active_contract_holder_still_a_lead(): void
    {
        $items = YuiRules::accountFlags([], [], [['contact_id' => 5, 'name' => 'Ron Smith', 'contract_id' => 3, 'contract_number' => 'CTR-2026-0004']]);
        $this->assertSame(['lead_contract:5'], self::flags($items));
        $this->assertSame('Ron Smith has an active contract (CTR-2026-0004) but is still marked a lead', $items[0]['text']);
    }

    public function test_handled_account_flags_stay_hidden(): void
    {
        $items = YuiRules::accountFlags([self::firm(['primary_contact_id' => 0])], [], [], ['yui:account:no_primary:12']);
        $this->assertSame([], $items);
    }

    // ── Who to write to ──────────────────────────────────────────────────

    private static function person(int $id, string $first, string $email, string $role = '', string $why = '', bool $billing = false): array
    {
        return ['id' => $id, 'first_name' => $first, 'last_name' => 'X', 'email' => $email, 'phone' => '', 'role' => $role, 'why' => $why, 'billing' => $billing];
    }

    public function test_arrears_go_to_the_property_manager_first(): void
    {
        $to = YuiRules::pickContact([
            self::person(1, 'Alena', 'alena@vm.ca', 'property_manager', 'property manager'),
            self::person(2, 'Bob', 'bob@vm.ca', '', 'company primary'),
            self::person(3, 'Jodi', 'jodi@vm.ca', 'billing_contact', 'billing contact', true),
        ]);
        $this->assertSame(1, $to['contact_id']);
        $this->assertSame('property manager', $to['why']);
    }

    public function test_an_accountant_is_skipped_unless_they_are_the_billing_contact(): void
    {
        // The PM slot holds the accountant (role billing_contact) → skipped; company primary wins.
        $to = YuiRules::pickContact([
            self::person(3, 'Jodi', 'jodi@vm.ca', 'billing_contact', 'property manager'),
            self::person(2, 'Bob', 'bob@vm.ca', '', 'company primary'),
        ]);
        $this->assertSame(2, $to['contact_id']);
        // Nobody else: the accountant IS the billing contact → allowed.
        $to = YuiRules::pickContact([
            self::person(3, 'Jodi', 'jodi@vm.ca', 'billing_contact', 'property manager'),
            self::person(3, 'Jodi', 'jodi@vm.ca', 'billing_contact', 'billing contact', true),
        ]);
        $this->assertNull($to, 'the same person was already seen and skipped — first mention wins');
        $to = YuiRules::pickContact([self::person(3, 'Jodi', 'jodi@vm.ca', 'billing_contact', 'billing contact', true)]);
        $this->assertSame(3, $to['contact_id']);
    }

    public function test_someone_with_an_email_beats_someone_without(): void
    {
        $to = YuiRules::pickContact([self::person(1, 'Alena', '', '', 'property manager'), self::person(2, 'Bob', 'bob@vm.ca', '', 'company primary')]);
        $this->assertSame(2, $to['contact_id']);
        $to = YuiRules::pickContact([self::person(1, 'Alena', '', '', 'property manager')]);
        $this->assertSame(1, $to['contact_id']);
        $this->assertSame('', $to['email']);
        $this->assertNull(YuiRules::pickContact([]));
    }

    public function test_arrears_one_conversation_per_payer_over_sixty_days(): void
    {
        $to = YuiRules::pickContact([self::person(1, 'Alena', 'alena@vm.ca', 'property_manager', 'property manager')]);
        $items = YuiRules::arrears([
            ['payer' => 'company:12', 'name' => 'Strata NW123', 'place' => '1450 Laburnum St', 'to' => $to, 'invoices' => [
                ['id' => 1, 'number' => 'INV-2026-0101', 'balance' => 1200.0, 'due_date' => '2026-07-01'],
                ['id' => 2, 'number' => 'INV-2026-0140', 'balance' => 400.0, 'due_date' => '2026-07-20'],
                ['id' => 3, 'number' => 'INV-2026-0300', 'balance' => 900.0, 'due_date' => '2026-09-20'],   // only 16 days
            ]],
            ['payer' => 'contact:9', 'name' => 'Ron Smith', 'place' => '9 Elm St', 'to' => null, 'invoices' => [
                ['id' => 4, 'number' => 'INV-2026-0200', 'balance' => 80.0, 'due_date' => '2026-09-01'],      // 35 days
            ]],
        ], self::NOW);
        $this->assertCount(1, $items);
        $a = $items[0];
        $this->assertSame('yui:arrears:company-12', $a['key']);
        $this->assertSame(1600.0, $a['total']);
        $this->assertSame(1600.0, $a['value']);
        $this->assertSame('2026-07-01', $a['oldest_due']);
        $this->assertSame(2, $a['priority']);
        $this->assertSame('Strata NW123 owes $1,600 on 2 invoices, oldest 97 days overdue — a note to Alena (property manager)', $a['text']);
        $d = YuiRules::draft($a, 'Tim');
        $this->assertStringContainsString('invoices INV-2026-0101 and INV-2026-0140 are still showing as unpaid', $d['body']);
        $this->assertStringContainsString('$1,600', $d['body']);
        $this->assertStringStartsWith('Hi Alena,', $d['body']);
        $this->assertSame([], YuiRules::smsProblems($d['sms']));
    }

    // ── Renewals & check-ins ─────────────────────────────────────────────

    public function test_contracts_ending_within_sixty_days(): void
    {
        $rows = [
            ['id' => 1, 'contract_number' => 'CTR-1', 'end_date' => '2026-10-10', 'address' => '1 A St', 'auto_renew' => 0],
            ['id' => 2, 'contract_number' => 'CTR-2', 'end_date' => '2026-12-05', 'address' => '2 B St', 'auto_renew' => 1],   // 60 days
            ['id' => 3, 'contract_number' => 'CTR-3', 'end_date' => '2026-12-06', 'address' => '3 C St', 'auto_renew' => 0],   // 61 days
            ['id' => 4, 'contract_number' => 'CTR-4', 'end_date' => '2026-10-01', 'address' => '4 D St', 'auto_renew' => 0],   // already ended
        ];
        $items = YuiRules::endingContracts($rows, self::NOW);
        $this->assertSame(['yui:renewal:ending:1', 'yui:renewal:ending:2'], array_column($items, 'key'));
        $this->assertSame(2, $items[0]['priority']);   // within 14 days
        $this->assertSame(3, $items[1]['priority']);
        $this->assertSame('Contract CTR-1 at 1 A St ends Oct 10 (4 days) — ask about renewing', $items[0]['text']);
        $this->assertStringContainsString('renews on its own', $items[1]['text']);
    }

    public function test_season_dates(): void
    {
        $this->assertSame(['start' => '2026-11-01', 'days' => 26], YuiRules::season('2026-10-06 09:00:00'));
        $this->assertSame('2025-11-01', YuiRules::season('2026-02-10')['start']);
        $this->assertLessThan(0, YuiRules::season('2026-02-10')['days']);
        $this->assertSame('2026-11-01', YuiRules::season('2026-11-15')['start']);
    }

    private static function ct(int $id, int $pid, string $status, string $start, ?string $end, string $title = 'Winter salting'): array
    {
        return ['id' => $id, 'contract_number' => 'CTR-' . $id, 'property_id' => $pid, 'address' => $pid . ' Main St', 'title' => $title, 'service' => '',
                'status' => $status, 'start_date' => $start, 'end_date' => $end, 'to' => null];
    }

    public function test_last_winters_salt_customers_with_nothing_this_winter(): void
    {
        $contracts = [
            self::ct(1, 10, 'expired', '2025-11-01', '2026-03-31'),                     // gap → flag
            self::ct(2, 20, 'expired', '2025-11-01', '2026-03-31'),
            self::ct(3, 20, 'active', '2026-11-01', '2027-03-31'),                      // renewed already
            self::ct(4, 30, 'expired', '2025-11-01', '2026-03-31', 'Lawn care'),        // not seasonal
            self::ct(5, 40, 'cancelled', '2025-11-01', '2026-03-31'),                   // left on purpose
            self::ct(6, 50, 'expired', '2025-11-01', '2026-03-31', 'Snow removal'),     // quoted already (Sam)
            self::ct(7, 60, 'expired', '2024-11-01', '2025-03-31'),                     // two winters ago
        ];
        $items = YuiRules::seasonGaps($contracts, [50], self::NOW);
        $this->assertSame(['yui:renewal:season:10:2026'], array_column($items, 'key'));
        $this->assertSame("Salt & snow at 10 Main St: last winter's contract (CTR-1), nothing for this winter yet — the season starts Nov 1", $items[0]['text']);
        // Outside the 45 days before the season: nothing.
        $this->assertSame([], YuiRules::seasonGaps($contracts, [], '2026-09-01 09:00:00'));
        $this->assertSame([], YuiRules::seasonGaps($contracts, [], '2026-12-01 09:00:00'));
    }

    public function test_seasonal_work_is_recognised(): void
    {
        $this->assertTrue(YuiRules::isSeasonal('Winter salting 2025/26'));
        $this->assertTrue(YuiRules::isSeasonal('snow_removal'));
        $this->assertTrue(YuiRules::isSeasonal('De-icing'));
        $this->assertFalse(YuiRules::isSeasonal('Spring cleanup'));
        $this->assertFalse(YuiRules::isSeasonal('Basalt patio'));
    }

    public function test_quiet_firms_get_a_check_in_only_when_the_log_reaches_back(): void
    {
        $firms = [
            ['id' => 12, 'name' => 'Vancouver Management', 'last_out' => '2026-05-01 10:00:00', 'to' => null],
            ['id' => 13, 'name' => 'Crest Realty', 'last_out' => '2026-09-01 10:00:00', 'to' => null],
            ['id' => 14, 'name' => 'Strata NW123', 'last_out' => null, 'to' => null],
        ];
        $items = YuiRules::checkins($firms, true, self::NOW);
        $this->assertSame(['yui:checkin:14', 'yui:checkin:12'], array_column($items, 'key'));
        $this->assertSame('Nothing sent to Vancouver Management since May 1 — time for a check-in', $items[1]['text']);
        $this->assertSame([], YuiRules::checkins($firms, false, self::NOW));
        $this->assertSame(['yui:checkin:14'], array_column(YuiRules::checkins($firms, true, self::NOW, ['yui:checkin:12']), 'key'));
    }

    // ── Texts and drafts ─────────────────────────────────────────────────

    public function test_texts_follow_the_carrier_rules(): void
    {
        $ok = "Hi Alena, it's Tim at Mowology. I sent you an email. Check your email or call (778) 846-9273.";
        $this->assertSame([], YuiRules::smsProblems($ok));
        $this->assertNotEmpty(YuiRules::smsProblems('Hi Alena, see mowology.ca or call (778) 846-9273. Check your email.'));
        $this->assertNotEmpty(YuiRules::smsProblems("Hi Alena, it's Tim. Check your email."));               // no phone
        $this->assertNotEmpty(YuiRules::smsProblems("Hi Alena, call (778) 846-9273."));                      // no "email"
        $this->assertNotEmpty(YuiRules::smsProblems(str_repeat('a', 140) . ' email (778) 846-9273'));        // too long
    }

    public function test_every_template_text_passes_the_rules(): void
    {
        $item = ['template' => '', 'to' => ['first_name' => 'Alexandrina', 'name' => 'Alexandrina Q'], 'place' => '1450 Laburnum Street, Vancouver',
                 'end_date' => '2026-11-30', 'season_start' => '2026-11-01', 'invoices' => [['number' => 'INV-1']], 'total' => 10, 'oldest_due' => '2026-07-01'];
        foreach (array_keys(YuiRules::TEMPLATES) as $t) {
            $d = YuiRules::draft(['template' => $t] + $item, 'Tim');
            $this->assertSame([], YuiRules::smsProblems($d['sms']), $t . ': ' . $d['sms']);
            $this->assertStringNotContainsString('{', $d['body'] . $d['subject'], $t);
            $this->assertStringNotContainsString('!', $d['body'], $t);
            $this->assertStringNotContainsStringIgnoringCase('just checking in', $d['body'], $t);
        }
    }

    public function test_a_learned_edit_becomes_the_next_draft_except_for_replies(): void
    {
        $item = ['template' => 'checkin', 'to' => ['first_name' => 'Alena', 'name' => 'Alena R'], 'place' => 'Vancouver Management'];
        $d = YuiRules::draft($item, 'Tim', "Hi {first_name},\n\nMy way.\n\n{owner}");
        $this->assertSame('learned', $d['drafted_by']);
        $this->assertSame("Hi Alena,\n\nMy way.\n\nTim", $d['body']);
        $r = YuiRules::draft(['template' => 'reply', 'subject' => 'Re: Gate code', 'to' => ['first_name' => 'Gaby', 'name' => 'Gaby S']], 'Tim', 'ignored');
        $this->assertSame('template', $r['drafted_by']);
        $this->assertSame('Re: Gate code', $r['subject']);
    }

    public function test_learning_from_an_edit_keeps_grammar_words_alone(): void
    {
        $item = ['template' => 'arrears', 'to' => ['first_name' => 'Alena', 'name' => 'Alena M'], 'place' => '1450 Laburnum St',
                 'invoices' => [['number' => 'INV-1'], ['number' => 'INV-2']], 'total' => 1600.0, 'oldest_due' => '2026-07-01'];
        $body = "Hi Alena,\n\nWe are tidying up. invoices INV-1 and INV-2 are open for 1450 Laburnum St ($1,600). Are they paid?\n\nThanks,\nTim";
        $l = YuiRules::learnFrom($body, $item, 'Tim');
        $this->assertSame("Hi {first_name},\n\nWe are tidying up. {invoice_list} {is_are} open for {place} ({total}). Are they paid?\n\nThanks,\n{owner}", $l);
        // Filled for one invoice, it still reads right.
        $one = ['invoices' => [['number' => 'INV-9']], 'total' => 80.0] + $item;
        $this->assertStringContainsString('invoice INV-9 is open', YuiRules::draft($one, 'Tim', $l)['body']);
    }

    public function test_reply_prompt_carries_the_rules_and_the_thread_oldest_first(): void
    {
        $p = YuiRules::replyPrompt(['name' => 'Gaby', 'to' => ['first_name' => 'Gaby']], [
            ['direction' => 'inbound', 'channel' => 'email', 'snippet' => 'Yes', 'sent_at' => '2026-10-05 16:00:00'],
            ['direction' => 'outbound', 'channel' => 'email', 'snippet' => 'Fall cleanup next week?', 'sent_at' => '2026-10-04 09:00:00'],
        ], 'Tim', []);
        $this->assertStringContainsString("Start with 'Hi Gaby,'", $p['system']);
        $this->assertStringContainsString('EXISTING client', $p['system']);
        $this->assertLessThan(strpos($p['user'], 'CLIENT (2026-10-05'), strpos($p['user'], 'US (2026-10-04'));
    }

    // ── Inbox and the brief ──────────────────────────────────────────────

    public function test_inbox_takes_only_the_client_lane_yeses_first(): void
    {
        $u = [
            ['key' => 'sam:reply:1:a', 'lane' => 'quote', 'priority' => 1, 'at' => '2026-10-05 10:00:00', 'yes' => true],
            ['key' => 'yui:reply:2:b', 'lane' => 'client', 'priority' => 2, 'at' => '2026-10-06 10:00:00', 'yes' => false],
            ['key' => 'yui:reply:3:c', 'lane' => 'client', 'priority' => 1, 'at' => '2026-10-01 10:00:00', 'yes' => true],
        ];
        $this->assertSame(['yui:reply:3:c', 'yui:reply:2:b'], array_column(YuiRules::inbox($u), 'key'));
    }

    public function test_brief_orders_yes_and_promises_then_arrears_inbox_accounts_renewals(): void
    {
        $it = fn(string $key, string $section, int $p, array $x = []) => $x + ['key' => $key, 'kind' => 'k', 'section' => $section, 'value' => null,
                                                                               'since' => null, 'text' => $key, 'url' => '/x', 'priority' => $p];
        $b = YuiRules::brief([
            'inbox'    => [$it('yui:reply:3:c', 'inbox', 1, ['yes' => true]), $it('yui:reply:2:b', 'inbox', 2)],
            'promises' => [$it('yui:promise:7:d', 'promises', 1)],
            'accounts' => [$it('yui:account:no_primary:12', 'accounts', 3)],
            'renewals' => [$it('yui:checkin:14', 'renewals', 3)],
            'arrears'  => [$it('yui:arrears:company-12', 'arrears', 2, ['total' => 1600.0])],
        ]);
        $this->assertSame('yui', $b['head']);
        $this->assertSame(['yui:reply:3:c', 'yui:promise:7:d', 'yui:arrears:company-12', 'yui:reply:2:b', 'yui:account:no_primary:12', 'yui:checkin:14'],
            array_column($b['items'], 'key'));
        $this->assertSame(6, $b['count']);
        $keys = array_keys($b['items'][0]);
        sort($keys);   // the contract fields, plus 'yes' when the item is a clear yes (Charlie ranks those first)
        $this->assertSame(array_values(array_intersect(['kind', 'key', 'priority', 'since', 'text', 'url', 'value', 'yes'], $keys)), $keys);
        $this->assertEmpty(array_diff(['key', 'kind', 'value', 'since', 'text', 'url', 'priority'], $keys));
        $this->assertSame('1 client said yes · 1 approval with no accepted quote', $b['headline']);
        // Charlie normalises Yui's kinds under her name.
        $this->assertSame('yui:k', CharlieRankService::normalize($b['items'][0], 'yui')['kind']);
    }

    public function test_headline_when_quiet(): void
    {
        $this->assertSame('All quiet with existing clients', YuiRules::headline([]));
    }

    // ── Badges ───────────────────────────────────────────────────────────

    public function test_badges(): void
    {
        $b = YuiBadgeService::compute([true, true, true, true, true, false], 3);
        $this->assertSame(['words'], array_column($b['earned'], 'key'));
        $this->assertSame('on_it', $b['next']['key']);   // 6/10 beats tidy 3/10
        $none = YuiBadgeService::compute([], 0);
        $this->assertSame([], $none['earned']);
        $all = YuiBadgeService::compute(array_fill(0, 10, true), 10);
        $this->assertSame(['words', 'on_it', 'tidy'], array_column($all['earned'], 'key'));
        $this->assertNull($all['next']);
    }
}
