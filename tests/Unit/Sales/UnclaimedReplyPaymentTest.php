<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2026-10-07: Alexandra replied "Paid. Thanks!" to a payment reminder; Tim recorded the
 * payment, and Yui still said "answer them". A reply about an invoice is resolved once the
 * invoice is paid (or she claims payment and one was recorded since); a claim with nothing
 * recorded stays open and says so; a question stays open whatever the balance.
 */
class UnclaimedReplyPaymentTest extends TestCase
{
    private const NOW = '2026-10-07 12:00:00';
    private const ALEX = 855;
    private const KELLY = 900;

    private static function reply(int $cid, string $snippet, string $subject, string $at = '2026-10-06 09:30:00', string $name = 'Alexandra'): array
    {
        return [
            'message_key' => 'msg-' . $cid . '-' . md5($snippet . $at),
            'contact_id'  => $cid,
            'channel'     => 'email',
            'from_addr'   => 'person' . $cid . '@example.com',
            'subject'     => $subject,
            'snippet'     => $snippet,
            'sent_at'     => $at,
            'first_name'  => $name,
        ];
    }

    private static function invoice(int $cid, string $number, string $status, float $balance, ?string $paidAt = null, string $date = '2026-09-01'): array
    {
        return ['cid' => $cid, 'invoice_number' => $number, 'status' => $status, 'balance_due' => $balance,
                'invoice_date' => $date, 'last_paid_at' => $paidAt];
    }

    private static function run1(array $reply, array $invoices, array $outMsgs = []): array
    {
        return UnclaimedReplyService::unclaimed([$reply], [
            'invoices' => $invoices,
            'out_msgs' => $outMsgs ? [(int)$reply['contact_id'] => $outMsgs] : [],
        ], self::NOW);
    }

    // ── Alexandra: the live case ─────────────────────────────────────────

    public function test_paid_reply_to_reminder_is_resolved_once_the_invoice_is_paid(): void
    {
        $r = self::reply(self::ALEX, 'Paid. Thanks!', 'Re: Invoice INV-2026-0412 is 7 days past due');
        $inv = [self::invoice(self::ALEX, 'INV-2026-0412', 'paid', 0.0, '2026-10-06 15:12:00')];
        $this->assertSame([], self::run1($r, $inv));
        $this->assertSame('resolved', UnclaimedReplyService::paymentState(self::ALEX, $r, $inv)['state']);
    }

    public function test_invoice_billed_through_strata_is_found_by_number(): void
    {
        // Strata invoice: invoices.contact_id is someone else; found by its number (cid 0).
        $r = self::reply(self::ALEX, 'Paid. Thanks!', 'Re: Invoice INV-2026-0412: $210.00 due Oct 15');
        $this->assertSame([], self::run1($r, [self::invoice(0, 'INV-2026-0412', 'paid', 0.0, '2026-10-06 15:12:00')]));
    }

    public function test_reply_without_a_number_uses_the_reminder_it_answers(): void
    {
        $r = self::reply(self::ALEX, 'Paid. Thanks!', 'Re: A friendly reminder');
        $out = [['sent_at' => '2026-10-03 08:00:00', 'subject' => 'Invoice INV-2026-0412 is 3 days past due', 'snippet' => '']];
        $inv = [self::invoice(self::ALEX, 'INV-2026-0412', 'paid', 0.0, '2026-10-06 15:12:00'),
                self::invoice(self::ALEX, 'INV-2026-0300', 'sent', 99.0)];   // another open invoice — not the one she answered
        $this->assertSame([], self::run1($r, $inv, $out));
    }

    // ── Paid claim, nothing recorded: stays open and says so ─────────────

    public function test_paid_claim_with_no_payment_recorded_stays_open_and_says_so(): void
    {
        $r = self::reply(self::ALEX, 'Paid. Thanks!', 'Re: Invoice INV-2026-0412 is 7 days past due');
        $items = self::run1($r, [self::invoice(self::ALEX, 'INV-2026-0412', 'overdue', 210.0)]);
        $this->assertCount(1, $items);
        $this->assertSame('claimed_unpaid', $items[0]['payment']);
        $this->assertStringContainsString("Alexandra says it's paid — no payment recorded yet. Check e-Transfers.", $items[0]['text']);
        $this->assertStringContainsString('INV-2026-0412', $items[0]['text']);
        $this->assertSame('client', $items[0]['lane']);
    }

    public function test_paid_claim_with_payment_recorded_after_reply_is_resolved_even_if_a_balance_remains(): void
    {
        // Partial: her e-Transfer covered most of it and was recorded after her reply.
        $r = self::reply(self::ALEX, 'E-transfer sent', 'Re: Invoice INV-2026-0412 is 7 days past due');
        $inv = [self::invoice(self::ALEX, 'INV-2026-0412', 'partial', 10.50, '2026-10-06 16:00:00')];
        $this->assertSame([], self::run1($r, $inv));
    }

    public function test_payment_recorded_before_the_reply_day_does_not_count_as_hers(): void
    {
        $r = self::reply(self::ALEX, 'Done', 'Re: Invoice INV-2026-0412 is 7 days past due');
        $inv = [self::invoice(self::ALEX, 'INV-2026-0412', 'partial', 10.50, '2026-09-20 10:00:00')];
        $this->assertSame('claimed_unpaid', self::run1($r, $inv)[0]['payment']);
    }

    // ── Unpaid, not a claim: an ordinary open reply ──────────────────────

    public function test_unpaid_invoice_non_claim_stays_open_as_before(): void
    {
        $r = self::reply(self::ALEX, 'We are waiting on council to sign off on this one.', 'Re: Invoice INV-2026-0412 is 7 days past due');
        $items = self::run1($r, [self::invoice(self::ALEX, 'INV-2026-0412', 'overdue', 210.0)]);
        $this->assertCount(1, $items);
        $this->assertSame('open', $items[0]['payment']);
        $this->assertStringEndsWith('Answer them.', $items[0]['text']);
    }

    // ── Kelly: a question stays open, paid or not ────────────────────────

    public function test_question_about_the_total_stays_open_even_when_paid(): void
    {
        $r = self::reply(self::KELLY, 'How much is the total for the year including the fall cleanup', 'Re: Invoice INV-2026-0501', '2026-10-05 10:00:00', 'Kelly');
        $items = self::run1($r, [self::invoice(self::KELLY, 'INV-2026-0501', 'paid', 0.0, '2026-10-05 12:00:00')]);
        $this->assertCount(1, $items);
        $this->assertStringContainsString('Kelly replied', $items[0]['text']);
        $this->assertStringEndsWith('Answer them.', $items[0]['text']);
    }

    public function test_unrelated_reply_with_no_invoice_is_untouched(): void
    {
        $r = self::reply(self::KELLY, 'Can you also look at the hedge on the east side', 'Re: Spring visit', '2026-10-05 10:00:00', 'Kelly');
        $items = self::run1($r, [self::invoice(self::KELLY, 'INV-2026-0501', 'paid', 0.0, '2026-10-05 12:00:00')]);
        $this->assertCount(1, $items);
        $this->assertNull($items[0]['payment']);
        $this->assertNull(UnclaimedReplyService::paymentState(self::KELLY, $r, [self::invoice(self::KELLY, 'INV-2026-0501', 'paid', 0.0)]));
    }

    public function test_money_reply_without_number_falls_back_to_contacts_open_invoices(): void
    {
        $r = self::reply(self::ALEX, 'Thank you, payment made', 'Re: Your statement');
        $paid = [self::invoice(self::ALEX, 'INV-2026-0412', 'paid', 0.0, '2026-10-06 15:12:00')];
        $this->assertSame([], self::run1($r, $paid));
        $owing = [self::invoice(self::ALEX, 'INV-2026-0412', 'sent', 210.0)];
        $this->assertSame('claimed_unpaid', self::run1($r, $owing)[0]['payment']);
    }

    public function test_thanks_only_is_resolved_once_paid(): void
    {
        $r = self::reply(self::ALEX, 'Thanks!', 'Re: Invoice INV-2026-0412 is due today');
        $this->assertSame([], self::run1($r, [self::invoice(self::ALEX, 'INV-2026-0412', 'paid', 0.0, '2026-10-07 09:00:00')]));
    }

    // ── Pieces ───────────────────────────────────────────────────────────

    public function test_invoice_refs_normalise(): void
    {
        $this->assertSame(['INV-2026-0412', 'INV-2026-0096'],
            UnclaimedReplyService::invoiceRefs('Re: INV-2026-0412 and invoice 2026-96 (QUO-2026-0001 is a quote)'));
    }

    /** @dataProvider claims */
    public function test_payment_claims(string $s, bool $want): void
    {
        $this->assertSame($want, UnclaimedReplyService::isPaymentClaim($s), $s);
    }

    public static function claims(): array
    {
        return [
            ['Paid. Thanks!', true],
            ['Sent', true],
            ['E-transfer sent', true],
            ['Payment made, thank you', true],
            ['Done', true],
            ['Have you received it? I paid yesterday', false],
            ["I haven't paid yet", false],
            ['Will send it tomorrow', false],
            ['How much is the total', false],
        ];
    }
}
