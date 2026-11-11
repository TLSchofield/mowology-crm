<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * "Team on this client" on the contact page: the "says they paid" detector, Penny's line
 * for it, item ordering / the 6-item cut, and the scope filter that keeps every head's
 * items about this contact only.
 */
class ContactTeamServiceTest extends TestCase
{
    // ── saysPaid ───────────────────────────────────────────────────────────

    /** @dataProvider paidProvider */
    public function testSaysPaid(string $text, bool $expected): void
    {
        $this->assertSame($expected, ContactTeamService::saysPaid($text), $text);
    }

    public static function paidProvider(): array
    {
        return [
            'Paid. Thanks!'                     => ['Paid. Thanks!', true],
            'paid lowercase'                    => ['paid', true],
            'I have paid the invoice'           => ['Hi Tim, I have paid the invoice.', true],
            'just paid'                         => ['Just paid it, thanks', true],
            'sent the e-transfer'               => ['Sent the e-transfer this morning', true],
            'sent the etransfer'                => ['I sent the etransfer', true],
            'e-transfer sent'                   => ['E-transfer sent!', true],
            'payment sent'                      => ['Payment sent.', true],
            'payment made'                      => ['Payment has been made', true],
            'e-transferred'                     => ['I e-transferred you the amount', true],
            'done'                              => ['Done', true],
            'done thanks'                       => ['Done, thanks!', true],
            'curly apostrophe paid'             => ['We’ve paid it', true],
            'question'                          => ['Has this been paid?', false],
            'did you get it'                    => ['I paid, did you get it?', false],
            'not paid'                          => ['It is not paid yet', false],
            "haven't paid"                      => ["I haven't paid yet, sorry", false],
            'unpaid'                            => ['Why does it say unpaid', false],
            'will pay'                          => ['I will pay on Friday', false],
            "'ll send"                          => ["I'll send the e-transfer tomorrow", false],
            'pay next week'                     => ['Can pay next week', false],
            'once paid'                         => ['Once it is paid I will let you know', false],
            'done in a longer sentence'         => ['The hedge looks done but the gate was left open', false],
            'empty'                             => ['', false],
            'unrelated'                         => ['Thanks for the great work on the lawn', false],
        ];
    }

    // ── Penny's paid-claim line ────────────────────────────────────────────

    private static function inv(int $id, string $no, float $bal, string $sent): array
    {
        return ['id' => $id, 'invoice_number' => $no, 'balance_due' => $bal, 'total' => $bal, 'due_date' => '2026-10-15',
                'issue_date' => substr($sent, 0, 10), 'sent_at' => $sent, 'status' => 'sent'];
    }

    public function testPaidClaimWithNoDepositSaysCheckETransfers(): void
    {
        $it = ContactTeamService::paidClaim('Alexandra', ['sent_at' => '2026-10-05 09:00:00', 'snippet' => 'Paid. Thanks!'],
            [self::inv(7, 'INV-2026-0412', 364.65, '2026-10-01 10:00:00')], [], [], 41);
        $this->assertSame('penny', $it['head']);
        $this->assertSame(ContactTeamService::G_MONEY, $it['group']);
        $this->assertSame("Alexandra says it's paid (\$364.65), but I can't see a matching deposit yet; check e-Transfers.", $it['text']);
        $this->assertSame('/crm/invoices/index.php#etransfers', $it['link']['url']);
    }

    public function testPaidClaimWithDepositLinksTheInvoiceToMatch(): void
    {
        $deps = [7 => [['tx_id' => 900, 'date' => '2026-10-04', 'amount' => 364.65, 'confidence' => 90]]];
        $it = ContactTeamService::paidClaim('Alexandra', ['sent_at' => '2026-10-05 09:00:00', 'snippet' => 'Paid'],
            [self::inv(7, 'INV-2026-0412', 364.65, '2026-10-01 10:00:00')], $deps, [], 41);
        $this->assertStringContainsString('$364.65 deposit on Oct 4 fits INV-2026-0412', $it['text']);
        $this->assertSame('Match deposit', $it['link']['label']);
        $this->assertSame('/crm/invoices/view.php?id=7', $it['link']['url']);
    }

    public function testPaidClaimWithPendingETransfer(): void
    {
        $et = [['sender' => 'Alexandra Bee', 'active' => 364.65, 'lines' => []]];
        $it = ContactTeamService::paidClaim('Alexandra', ['sent_at' => '2026-10-05 09:00:00', 'snippet' => 'Paid'],
            [self::inv(7, 'INV-2026-0412', 364.65, '2026-10-01 10:00:00')], [], $et, 41);
        $this->assertStringContainsString('an e-Transfer from Alexandra Bee for $364.65 is waiting', $it['text']);
    }

    public function testPaidClaimAmountIsInvoicesSentBeforeTheMessage(): void
    {
        $open = [self::inv(7, 'INV-1', 364.65, '2026-10-01 10:00:00'), self::inv(8, 'INV-2', 100.00, '2026-10-06 10:00:00')];
        $it = ContactTeamService::paidClaim('Al', ['sent_at' => '2026-10-05 09:00:00', 'snippet' => 'Paid'], $open, [], [], 41);
        $this->assertStringContainsString('($364.65)', $it['text']);
    }

    public function testOpenLineOneAndMany(): void
    {
        $one = ContactTeamService::openLine([self::inv(7, 'INV-1', 364.65, '2026-09-01 10:00:00')], '2026-10-20', 41);
        $this->assertSame('INV-1 has $364.65 open, 5 days overdue.', $one['text']);
        $many = ContactTeamService::openLine([self::inv(7, 'INV-1', 100, '2026-09-01'), self::inv(8, 'INV-2', 50, '2026-09-02')], '2026-10-10', 41);
        $this->assertSame('2 invoices open, $150.00 in all; the oldest is due Oct 15.', $many['text']);
        $this->assertSame('/crm/invoices/statement.php?contact_id=41', $many['link']['url']);
    }

    // ── ordering + cut ─────────────────────────────────────────────────────

    public function testOrderYesThenMoneyThenRepliesThenRest(): void
    {
        $items = [
            ContactTeamService::item('mia', 'consent', ContactTeamService::G_INFO, 'consent', 'x', '/x', 3),
            ContactTeamService::item('otto', 'gaps', ContactTeamService::G_ACTION, 'gaps', 'x', '/x', 2),
            ContactTeamService::item('yui', 'client_reply', ContactTeamService::G_REPLY, 'reply', 'x', '/x', 2),
            ContactTeamService::item('penny', 'open_invoice', ContactTeamService::G_MONEY, 'money-2', 'x', '/x', 2),
            ContactTeamService::item('penny', 'paid_claim', ContactTeamService::G_MONEY, 'money-1', 'x', '/x', 1),
            ContactTeamService::item('sam', 'quote_reply', ContactTeamService::G_YES, 'yes', 'x', '/x', 1),
        ];
        $this->assertSame(['yes', 'money-1', 'money-2', 'reply', 'gaps', 'consent'], array_column(ContactTeamService::order($items), 'text'));
    }

    public function testOrderIsStableWithinAGroup(): void
    {
        $items = [];
        foreach (['a', 'b', 'c'] as $t) $items[] = ContactTeamService::item('otto', 'k', ContactTeamService::G_ACTION, $t, 'x', '/x', 2);
        $this->assertSame(['a', 'b', 'c'], array_column(ContactTeamService::order($items), 'text'));
    }

    public function testFinishCutsToSixAndCountsTheRest(): void
    {
        $items = [];
        for ($i = 0; $i < 9; $i++) $items[] = ContactTeamService::item('otto', 'k', ContactTeamService::G_ACTION, 't' . $i, 'x', '/x', 2);
        $r = ContactTeamService::finish($items, 6);
        $this->assertCount(6, $r['items']);
        $this->assertSame(3, $r['more']);
        $this->assertCount(3, $r['rest']);
        $this->assertSame(9, $r['total']);
    }

    public function testCharlieOnlyWhenNothingToActOn(): void
    {
        $info = [ContactTeamService::item('mia', 'consent', ContactTeamService::G_INFO, 'consent', 'x', '/x', 3)];
        $r = ContactTeamService::finish($info);
        $this->assertSame('charlie', $r['items'][0]['head']);
        $this->assertSame('Nothing needs you here.', $r['items'][0]['text']);
        $this->assertNull($r['items'][0]['link']);

        $acting = array_merge($info, [ContactTeamService::item('otto', 'gaps', ContactTeamService::G_ACTION, 'gaps', 'x', '/x')]);
        $this->assertNotContains('charlie', array_column(ContactTeamService::finish($acting)['items'], 'head'));
    }

    public function testItemCarriesHeadNameAndRole(): void
    {
        $it = ContactTeamService::item('penny', 'k', 1, 'text', 'Open', '/x');
        $this->assertSame(['Penny', 'Bookkeeper'], [$it['name'], $it['role']]);
        $this->assertSame(['label' => 'Open', 'url' => '/x'], $it['link']);
    }

    // ── scope ──────────────────────────────────────────────────────────────

    private static function scope(): array
    {
        return ['contact' => 41, 'properties' => [5], 'companies' => [12], 'quotes' => [300], 'invoices' => [7], 'contracts' => [60], 'plans' => [80]];
    }

    public function testInScopeMatchesTheContactAndWhatIsTheirs(): void
    {
        $s = self::scope();
        $this->assertTrue(ContactTeamService::inScope(['contact_id' => 41], $s));
        $this->assertTrue(ContactTeamService::inScope(['url' => '/crm/clients_appstack.php?action=view_contact&id=41'], $s));
        $this->assertTrue(ContactTeamService::inScope(['url' => '/crm/properties/view.php?id=5'], $s));
        $this->assertTrue(ContactTeamService::inScope(['url' => '/crm/companies/view.php?id=12'], $s));
        $this->assertTrue(ContactTeamService::inScope(['url' => '/crm/contracts/view.php?id=60'], $s));
        $this->assertTrue(ContactTeamService::inScope(['url' => '/crm/invoices/index.php?status=overdue', 'invoices' => [['id' => 7]]], $s));
        $this->assertTrue(ContactTeamService::inScope(['url' => '/crm/companies/view.php?id=99', 'to' => ['contact_id' => 41]], $s));
    }

    public function testInScopeRejectsOtherContactsThings(): void
    {
        $s = self::scope();
        $this->assertFalse(ContactTeamService::inScope(['contact_id' => 42], $s));
        $this->assertFalse(ContactTeamService::inScope(['url' => '/crm/clients_appstack.php?action=view_contact&id=410'], $s));
        $this->assertFalse(ContactTeamService::inScope(['url' => '/crm/properties/view.php?id=6'], $s));
        $this->assertFalse(ContactTeamService::inScope(['url' => '/crm/invoices/index.php?status=overdue', 'invoices' => [['id' => 8]]], $s));
        $this->assertFalse(ContactTeamService::inScope(['key' => 'mia:campaign_reply:3:41'], $s), 'keys are not parsed — only ids and urls');
    }

    // ── words ──────────────────────────────────────────────────────────────

    public function testStageLine(): void
    {
        $this->assertSame('Client since Mar 2024.', ContactTeamService::stageLine('client', '2024-03-12'));
        $this->assertSame('Client since Mar 2024.', ContactTeamService::stageLine('lead', '2024-03-12'), 'an accepted quote outranks a stale stage');
        $this->assertSame('Marked lost. A new quote would reopen them.', ContactTeamService::stageLine('lost', '2024-03-12'));
        $this->assertSame('Still a lead. Nothing accepted yet.', ContactTeamService::stageLine('lead', null));
    }

    public function testReviewLine(): void
    {
        $this->assertSame('They left us a Google review.', ContactTeamService::reviewLine(['has_reviewed' => 1], 4, false));
        $this->assertSame('Review due: 3 visits done, never asked. The next finished visit asks.', ContactTeamService::reviewLine([], 3, true));
        $this->assertNull(ContactTeamService::reviewLine([], 0, true));
        $this->assertStringStartsWith('Asked for a review Sep 2 (1 of 3)', ContactTeamService::reviewLine(['review_request_sent_at' => '2026-09-02 10:00:00', 'review_request_sent_count' => 1], 3, false));
    }

    public function testConsentLine(): void
    {
        $this->assertSame('I can email them (express); no texts.', ContactTeamService::consentLine(['ok' => true, 'type' => 'express', 'expires_at' => null], false, true));
        $this->assertSame("I can't email them: unsubscribed; texts are fine.", ContactTeamService::consentLine(['ok' => false, 'reason' => 'unsubscribed'], true, true));
        $this->assertSame('No email on file; no texts.', ContactTeamService::consentLine(null, false, false));
    }
}
