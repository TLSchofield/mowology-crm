<?php

use PHPUnit\Framework\TestCase;

/**
 * Autopay eligibility (Fable review 2026-10-12). AutopayService re-runs this rule inside the
 * transaction that holds the invoice row lock, right before it inserts the 'pending' attempt —
 * so an open attempt found under the lock must block a second charge.
 */
class AutopayEligibilityTest extends TestCase
{
    private function invoice(array $over = []): array
    {
        return array_merge([
            'invoice_status'           => 'sent',
            'balance_due'              => '120.00',
            'stripe_payment_method_id' => 'pm_123',
            'autopay_override'         => null,
            'autopay_enabled'          => 1,
        ], $over);
    }

    public function test_an_enrolled_unpaid_invoice_may_be_charged(): void
    {
        $this->assertNull(AutopayEligibility::blockReason($this->invoice(), false));
        foreach (['viewed', 'partial', 'overdue'] as $st) {
            $this->assertNull(AutopayEligibility::blockReason($this->invoice(['invoice_status' => $st]), false));
        }
    }

    public function test_an_open_attempt_blocks_a_second_charge(): void
    {
        $this->assertSame(
            'A charge for this invoice is already pending or succeeded',
            AutopayEligibility::blockReason($this->invoice(), true)
        );
    }

    public function test_paid_draft_and_missing_invoices_are_never_charged(): void
    {
        $this->assertSame('Invoice not found', AutopayEligibility::blockReason(null, false));
        foreach (['paid', 'draft', 'cancelled', 'void'] as $st) {
            $this->assertStringStartsWith('Invoice not in payable status',
                AutopayEligibility::blockReason($this->invoice(['invoice_status' => $st]), false));
        }
        $this->assertSame('No balance due', AutopayEligibility::blockReason($this->invoice(['balance_due' => '0.00']), false));
    }

    public function test_no_card_or_autopay_off_blocks(): void
    {
        $this->assertSame('No payment method on file',
            AutopayEligibility::blockReason($this->invoice(['stripe_payment_method_id' => null]), false));
        $this->assertSame('Autopay not enabled for this invoice',
            AutopayEligibility::blockReason($this->invoice(['autopay_enabled' => 0]), false));
    }

    public function test_the_plan_override_wins_over_the_payer_default(): void
    {
        $this->assertNotNull(AutopayEligibility::blockReason($this->invoice(['autopay_override' => 0]), false));
        $this->assertNull(AutopayEligibility::blockReason(
            $this->invoice(['autopay_override' => 1, 'autopay_enabled' => 0]), false));
    }

    public function test_each_attempt_has_its_own_idempotency_key(): void
    {
        $this->assertSame('autopay-42-1', AutopayEligibility::idempotencyKey(42, 1));
        $this->assertNotSame(AutopayEligibility::idempotencyKey(42, 1), AutopayEligibility::idempotencyKey(42, 2));
    }
}
