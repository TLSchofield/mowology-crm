<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * e-Transfer memos that name invoices without the year (2026-11-05, "Penny can't see Alison Birdsall": memo
 * "Payment for invoices 0065, 0066, 0118 and 0440", $232.84 = four open $58.21 invoices). Synthetic names only.
 * Plan: docs/plans/2026-11-05-etransfer-memo-invoice-numbers.md.
 */
class EtransferNamedInvoicesTest extends TestCase
{
    private static function inv(int $id, string $no, float $owing, string $payer = 'Dana Fir'): array
    {
        return ['id' => $id, 'invoice_number' => $no, 'balance_due' => $owing, 'total' => $owing, 'status' => 'sent', 'payer_name' => $payer];
    }

    // ── Reading year-less numbers ──

    public function test_reads_the_four_numbers_after_invoices(): void
    {
        $this->assertSame([65, 66, 118, 440],
            EtransferInboxService::extractShortInvoiceNumbers('Payment for invoices 0065, 0066, 0118 and 0440. Thank you'));
    }

    public function test_reads_common_short_forms(): void
    {
        $this->assertSame([65], EtransferInboxService::extractShortInvoiceNumbers('inv #65'));
        $this->assertSame([118], EtransferInboxService::extractShortInvoiceNumbers('Invoice no. 118'));
        $this->assertSame([65, 66], EtransferInboxService::extractShortInvoiceNumbers('invoices #0065,#0066'));
        $this->assertSame([65], EtransferInboxService::extractShortInvoiceNumbers('INV-0065'));
    }

    public function test_never_reads_a_number_without_the_word_invoice(): void
    {
        $this->assertSame([], EtransferInboxService::extractShortInvoiceNumbers('2 cuts, call 604 555 1234'));
        $this->assertSame([], EtransferInboxService::extractShortInvoiceNumbers('1234 Main Street'));
        $this->assertSame([], EtransferInboxService::extractShortInvoiceNumbers('Payment for invoice 65.00'));
        $this->assertSame([], EtransferInboxService::extractShortInvoiceNumbers('invoice 11/05/2026'));
        $this->assertSame([65], EtransferInboxService::extractShortInvoiceNumbers('invoice 65. Thanks'));
    }

    public function test_leaves_numbers_with_their_year_to_the_full_reader(): void
    {
        $this->assertSame([], EtransferInboxService::extractShortInvoiceNumbers('INV-2026-0065'));
        $this->assertSame([], EtransferInboxService::extractShortInvoiceNumbers('Invs 2026-0251 and 0383'));
        $this->assertSame([], EtransferInboxService::extractShortInvoiceNumbers('invoice 2026'));
    }

    // ── Which invoice a short number means ──

    public function test_short_number_with_one_invoice_is_that_invoice(): void
    {
        $r = EtransferInboxService::pickShortMatch([self::inv(1, 'INV-2026-0065', 58.21)]);
        $this->assertSame('INV-2026-0065', $r['invoice_number']);
    }

    public function test_short_number_in_two_years_is_the_one_still_owing(): void
    {
        $r = EtransferInboxService::pickShortMatch([self::inv(1, 'INV-2025-0065', 0.0), self::inv(2, 'INV-2026-0065', 58.21)]);
        $this->assertSame('INV-2026-0065', $r['invoice_number']);
    }

    public function test_short_number_owing_in_two_years_is_not_guessed(): void
    {
        $this->assertNull(EtransferInboxService::pickShortMatch([self::inv(1, 'INV-2025-0065', 40.0), self::inv(2, 'INV-2026-0065', 58.21)]));
        $this->assertNull(EtransferInboxService::pickShortMatch([]));
    }

    // ── The suggestion ──

    public function test_four_named_invoices_that_add_up_are_each_paid_in_full(): void
    {
        $rows = [self::inv(1, 'INV-2026-0065', 58.21), self::inv(2, 'INV-2026-0066', 58.21),
                 self::inv(3, 'INV-2026-0118', 58.21), self::inv(4, 'INV-2026-0440', 58.21)];
        $n = EtransferInboxService::spreadNamed($rows, 232.84);

        $this->assertTrue($n['adds_up']);
        $this->assertCount(4, $n['lines']);
        foreach ($n['lines'] as $l) $this->assertSame(58.21, $l['apply_amount']);
        $this->assertSame('Dana Fir', $n['payer_name']);
        $this->assertEqualsWithDelta(0.0, $n['left'], 0.001);
    }

    /** The trap: the old path matched the FIRST invoice and pre-filled it with the whole $232.84. */
    public function test_no_single_line_carries_the_whole_transfer(): void
    {
        $rows = [self::inv(1, 'INV-2026-0065', 58.21), self::inv(2, 'INV-2026-0066', 58.21)];
        $n = EtransferInboxService::spreadNamed($rows, 116.42);
        $this->assertSame([58.21, 58.21], array_column($n['lines'], 'apply_amount'));
    }

    public function test_named_invoices_that_do_not_add_up_say_what_is_left(): void
    {
        $n = EtransferInboxService::spreadNamed([self::inv(1, 'INV-2026-0065', 58.21), self::inv(2, 'INV-2026-0066', 58.21)], 150.00);
        $this->assertFalse($n['adds_up']);
        $this->assertEqualsWithDelta(33.58, $n['left'], 0.001);
        $this->assertSame([58.21, 58.21], array_column($n['lines'], 'apply_amount'));

        $short = EtransferInboxService::spreadNamed([self::inv(1, 'INV-2026-0065', 58.21), self::inv(2, 'INV-2026-0066', 58.21)], 100.00);
        $this->assertFalse($short['adds_up']);
        $this->assertSame([58.21, 41.79], array_column($short['lines'], 'apply_amount'));
    }

    public function test_an_already_paid_named_invoice_is_listed_not_paid_again(): void
    {
        $n = EtransferInboxService::spreadNamed([self::inv(1, 'INV-2026-0065', 0.0), self::inv(2, 'INV-2026-0066', 58.21),
                                                 self::inv(3, 'INV-2026-0118', 58.21)], 116.42);
        $this->assertTrue($n['adds_up']);
        $this->assertSame(['INV-2026-0066', 'INV-2026-0118'], array_column($n['lines'], 'invoice_number'));
        $this->assertSame(['INV-2026-0065'], $n['paid']);
    }

    public function test_one_open_invoice_is_left_to_the_single_invoice_path(): void
    {
        $this->assertNull(EtransferInboxService::spreadNamed([self::inv(1, 'INV-2026-0065', 58.21)], 58.21));
        $this->assertNull(EtransferInboxService::spreadNamed([], 58.21));
    }

    public function test_an_unclear_number_is_never_green(): void
    {
        $n = EtransferInboxService::spreadNamed([self::inv(1, 'INV-2026-0065', 58.21)], 58.21, ['0066']);
        $this->assertFalse($n['adds_up']);
        $this->assertSame(['0066'], $n['unclear']);
    }

    public function test_two_payers_name_no_payer(): void
    {
        $n = EtransferInboxService::spreadNamed([self::inv(1, 'INV-2026-0065', 10.0, 'Dana Fir'), self::inv(2, 'INV-2026-0066', 10.0, 'Lee Oak')], 20.0);
        $this->assertNull($n['payer_name']);
    }

    // ── Through the books (mocked): Alison's memo resolves by lookup ──

    public function test_named_invoices_resolves_short_numbers_by_lookup(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);   // no full-year numbers in this memo
        $stmt->method('fetchAll')->willReturnOnConsecutiveCalls(
            [self::inv(1, 'INV-2026-0065', 58.21)], [self::inv(2, 'INV-2026-0066', 58.21)],
            [self::inv(3, 'INV-2026-0118', 58.21)], [self::inv(4, 'INV-2026-0440', 58.21)]
        );
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $n = (new EtransferInboxService($pdo))->namedInvoices('Payment for invoices 0065, 0066, 0118 and 0440.', 232.84);
        $this->assertTrue($n['adds_up']);
        $this->assertSame(['INV-2026-0065', 'INV-2026-0066', 'INV-2026-0118', 'INV-2026-0440'], array_column($n['lines'], 'invoice_number'));
    }

    // ── Penny's wording ──

    public function test_penny_says_who_sent_it_and_who_it_pays_for(): void
    {
        $rows = [self::inv(1, 'INV-2026-0065', 58.21), self::inv(2, 'INV-2026-0066', 58.21)];
        $say = EtransferDeskService::sayNamed('SAM BIRCH', 116.42, EtransferInboxService::spreadNamed($rows, 116.42));
        $this->assertStringContainsString('the 2 invoices named in the memo (Dana Fir)', $say);
        $this->assertStringContainsString('Sent by SAM BIRCH, not Dana Fir', $say);
    }

    public function test_penny_says_what_does_not_add_up(): void
    {
        $rows = [self::inv(1, 'INV-2026-0065', 58.21), self::inv(2, 'INV-2026-0066', 58.21)];
        $say = EtransferDeskService::sayNamed('Dana Fir', 150.00, EtransferInboxService::spreadNamed($rows, 150.00));
        $this->assertStringContainsString('come to $116.42', $say);
        $this->assertStringContainsString('$33.58 more than they owe', $say);
    }
}
