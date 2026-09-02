<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for EtransferInboxService::suggestFifoAllocation() — when a transfer
 * amount exceeds what any single one of the sender's open invoices can
 * absorb, suggest applying it oldest-invoice-first (standard AR practice),
 * topping each one up to its balance before moving to the next.
 */
class EtransferFifoAllocationTest extends TestCase
{
    private function serviceWithInvoices(array $rows): EtransferInboxService
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn($rows);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        return new EtransferInboxService($pdo);
    }

    public function test_spreads_across_invoices_oldest_first(): void
    {
        // Query already orders by date ASC — oldest first, as the DB would return it.
        $svc = $this->serviceWithInvoices([
            ['id' => 10, 'invoice_number' => 'INV-2026-0001', 'balance_due' => 300.00, 'payer_name' => '1355183 B.C. LTD.', 'order_date' => '2026-05-01'],
            ['id' => 11, 'invoice_number' => 'INV-2026-0050', 'balance_due' => 500.00, 'payer_name' => '1355183 B.C. LTD.', 'order_date' => '2026-06-01'],
            ['id' => 12, 'invoice_number' => 'INV-2026-0099', 'balance_due' => 900.00, 'payer_name' => '1355183 B.C. LTD.', 'order_date' => '2026-07-01'],
        ]);

        $spread = $svc->suggestFifoAllocation('1355183 B.C. LTD.', 1050.95);

        $this->assertCount(3, $spread);
        $this->assertSame('INV-2026-0001', $spread[0]['invoice_number']);
        $this->assertSame(300.00, $spread[0]['apply_amount']);
        $this->assertSame('INV-2026-0050', $spread[1]['invoice_number']);
        $this->assertSame(500.00, $spread[1]['apply_amount']);
        $this->assertSame('INV-2026-0099', $spread[2]['invoice_number']);
        $this->assertSame(250.95, $spread[2]['apply_amount']);
    }

    public function test_matches_sender_name_ignoring_punctuation_and_case(): void
    {
        $svc = $this->serviceWithInvoices([
            ['id' => 20, 'invoice_number' => 'INV-2026-0200', 'balance_due' => 50.00, 'payer_name' => '1355183 BC Ltd', 'order_date' => '2026-05-01'],
        ]);

        $spread = $svc->suggestFifoAllocation('1355183 B.C. LTD.', 50.00);

        $this->assertCount(1, $spread);
        $this->assertSame('INV-2026-0200', $spread[0]['invoice_number']);
    }

    public function test_matches_sender_name_missing_a_middle_name(): void
    {
        // A bank transfer's sender name is often shorter than the full legal
        // name on file (e.g. Interac profile "JOHN HUGHES" vs contact record
        // "John Ellen Hughes") — first+last word match must still find it.
        $svc = $this->serviceWithInvoices([
            ['id' => 50, 'invoice_number' => 'INV-2026-0500', 'balance_due' => 50.40, 'payer_name' => 'John Ellen Hughes', 'order_date' => '2026-05-01'],
        ]);

        $spread = $svc->suggestFifoAllocation('JOHN HUGHES', 50.40);

        $this->assertCount(1, $spread);
        $this->assertSame('INV-2026-0500', $spread[0]['invoice_number']);
    }

    public function test_single_word_name_does_not_loosely_match_unrelated_multiword_name(): void
    {
        $svc = $this->serviceWithInvoices([
            ['id' => 60, 'invoice_number' => 'INV-2026-0600', 'balance_due' => 50.00, 'payer_name' => 'Something Completely Different', 'order_date' => '2026-05-01'],
        ]);

        $this->assertSame([], $svc->suggestFifoAllocation('Something', 50.00));
    }

    public function test_ignores_invoices_belonging_to_a_different_payer(): void
    {
        $svc = $this->serviceWithInvoices([
            ['id' => 30, 'invoice_number' => 'INV-2026-0300', 'balance_due' => 100.00, 'payer_name' => 'Some Other Company', 'order_date' => '2026-05-01'],
        ]);

        $spread = $svc->suggestFifoAllocation('1355183 B.C. LTD.', 100.00);

        $this->assertSame([], $spread);
    }

    public function test_stops_once_amount_is_exhausted_leaving_later_invoices_untouched(): void
    {
        $svc = $this->serviceWithInvoices([
            ['id' => 40, 'invoice_number' => 'INV-2026-0400', 'balance_due' => 100.00, 'payer_name' => 'ACME', 'order_date' => '2026-05-01'],
            ['id' => 41, 'invoice_number' => 'INV-2026-0401', 'balance_due' => 200.00, 'payer_name' => 'ACME', 'order_date' => '2026-06-01'],
        ]);

        $spread = $svc->suggestFifoAllocation('ACME', 100.00);

        $this->assertCount(1, $spread);
        $this->assertSame('INV-2026-0400', $spread[0]['invoice_number']);
        $this->assertSame(100.00, $spread[0]['apply_amount']);
    }

    // ── suggestFifoAllocationByValue() — sender name matches nobody ────────
    // e.g. someone paying a friend's/relative's bill from their own account,
    // so the Interac sender name has nothing to do with whose invoice it is.

    public function test_value_fallback_finds_customer_whose_full_balance_matches_exactly(): void
    {
        $svc = $this->serviceWithInvoices([
            ['id' => 70, 'invoice_number' => 'INV-2026-0700', 'balance_due' => 100.00, 'payer_name' => 'Sandra Bertoia', 'order_date' => '2026-05-01'],
            ['id' => 71, 'invoice_number' => 'INV-2026-0701', 'balance_due' => 50.00, 'payer_name' => 'Sandra Bertoia', 'order_date' => '2026-06-01'],
            ['id' => 72, 'invoice_number' => 'INV-2026-0702', 'balance_due' => 999.00, 'payer_name' => 'Unrelated Payer', 'order_date' => '2026-05-01'],
        ]);

        $result = $svc->suggestFifoAllocationByValue(150.00);

        $this->assertNotNull($result);
        $this->assertSame('Sandra Bertoia', $result['payer_name']);
        $this->assertCount(2, $result['lines']);
        $this->assertSame('INV-2026-0700', $result['lines'][0]['invoice_number']);
        $this->assertSame('INV-2026-0701', $result['lines'][1]['invoice_number']);
    }

    public function test_value_fallback_refuses_to_guess_when_ambiguous(): void
    {
        // Two different customers each happen to owe exactly $200 total —
        // picking either would risk misapplying a payment to a stranger's account.
        $svc = $this->serviceWithInvoices([
            ['id' => 80, 'invoice_number' => 'INV-2026-0800', 'balance_due' => 200.00, 'payer_name' => 'Customer A', 'order_date' => '2026-05-01'],
            ['id' => 81, 'invoice_number' => 'INV-2026-0801', 'balance_due' => 200.00, 'payer_name' => 'Customer B', 'order_date' => '2026-05-01'],
        ]);

        $this->assertNull($svc->suggestFifoAllocationByValue(200.00));
    }

    public function test_value_fallback_returns_null_when_nothing_matches(): void
    {
        $svc = $this->serviceWithInvoices([
            ['id' => 90, 'invoice_number' => 'INV-2026-0900', 'balance_due' => 75.00, 'payer_name' => 'Customer C', 'order_date' => '2026-05-01'],
        ]);

        $this->assertNull($svc->suggestFifoAllocationByValue(999.99));
    }

    public function test_no_sender_name_returns_empty(): void
    {
        $svc = $this->serviceWithInvoices([]);
        $this->assertSame([], $svc->suggestFifoAllocation(null, 100.00));
        $this->assertSame([], $svc->suggestFifoAllocation('', 100.00));
    }

    public function test_zero_or_negative_amount_returns_empty(): void
    {
        $svc = $this->serviceWithInvoices([]);
        $this->assertSame([], $svc->suggestFifoAllocation('ACME', 0));
        $this->assertSame([], $svc->suggestFifoAllocation('ACME', -50));
    }

    // ── findLikelyDuplicatePayment() ────────────────────────────────────────
    //
    // Real case that motivated this: a Nov-Transfer email dated 2026-07-13
    // wasn't ingested until 2026-07-31 (poller didn't exist yet on the 13th),
    // but staff had already manually recorded that same $302.40 payment on
    // 2026-07-20 as 6 separate $50.40 e_transfer payments — so without this
    // check, suggestFifoAllocation() would spread the (already-spent) amount
    // across a different, unrelated set of that customer's open invoices.

    public function test_finds_same_day_batch_summing_to_transfer_amount(): void
    {
        $svc = $this->serviceWithInvoices([
            ['invoice_number' => 'INV-2026-0049', 'amount_paid' => 50.40, 'pay_day' => '2026-07-20', 'payer_name' => 'John Ellen Hughes'],
            ['invoice_number' => 'INV-2026-0116', 'amount_paid' => 50.40, 'pay_day' => '2026-07-20', 'payer_name' => 'John Ellen Hughes'],
            ['invoice_number' => 'INV-2026-0117', 'amount_paid' => 50.40, 'pay_day' => '2026-07-20', 'payer_name' => 'John Ellen Hughes'],
            ['invoice_number' => 'INV-2026-0134', 'amount_paid' => 50.40, 'pay_day' => '2026-07-20', 'payer_name' => 'John Ellen Hughes'],
            ['invoice_number' => 'INV-2026-0175', 'amount_paid' => 50.40, 'pay_day' => '2026-07-20', 'payer_name' => 'John Ellen Hughes'],
            ['invoice_number' => 'INV-2026-0249', 'amount_paid' => 50.40, 'pay_day' => '2026-07-20', 'payer_name' => 'John Ellen Hughes'],
        ]);

        $dup = $svc->findLikelyDuplicatePayment('JOHN HUGHES', 302.40, '2026-07-13 08:13:50');

        $this->assertNotNull($dup);
        $this->assertSame('2026-07-20', $dup['pay_date']);
        $this->assertSame(302.40, $dup['total']);
        $this->assertCount(6, $dup['invoice_numbers']);
    }

    public function test_no_duplicate_when_totals_dont_match(): void
    {
        $svc = $this->serviceWithInvoices([
            ['invoice_number' => 'INV-2026-0001', 'amount_paid' => 50.40, 'pay_day' => '2026-07-20', 'payer_name' => 'John Ellen Hughes'],
        ]);

        $this->assertNull($svc->findLikelyDuplicatePayment('JOHN HUGHES', 302.40, '2026-07-13'));
    }

    public function test_ignores_payment_far_before_the_transfer_email(): void
    {
        $svc = $this->serviceWithInvoices([
            ['invoice_number' => 'INV-2026-0001', 'amount_paid' => 100.00, 'pay_day' => '2026-01-01', 'payer_name' => 'ACME'],
        ]);

        $this->assertNull($svc->findLikelyDuplicatePayment('ACME', 100.00, '2026-07-13'));
    }

    public function test_ignores_payment_far_after_the_transfer_email(): void
    {
        $svc = $this->serviceWithInvoices([
            ['invoice_number' => 'INV-2026-0001', 'amount_paid' => 100.00, 'pay_day' => '2026-12-01', 'payer_name' => 'ACME'],
        ]);

        $this->assertNull($svc->findLikelyDuplicatePayment('ACME', 100.00, '2026-07-13'));
    }

    public function test_prefers_day_closest_to_email_date_when_multiple_days_match(): void
    {
        $svc = $this->serviceWithInvoices([
            // Two different days each happen to sum to $100 — must pick the nearer one.
            ['invoice_number' => 'INV-2026-0001', 'amount_paid' => 100.00, 'pay_day' => '2026-07-25', 'payer_name' => 'ACME'],
            ['invoice_number' => 'INV-2026-0002', 'amount_paid' => 100.00, 'pay_day' => '2026-08-10', 'payer_name' => 'ACME'],
        ]);

        $dup = $svc->findLikelyDuplicatePayment('ACME', 100.00, '2026-07-24');

        $this->assertNotNull($dup);
        $this->assertSame('2026-07-25', $dup['pay_date']);
    }
}
