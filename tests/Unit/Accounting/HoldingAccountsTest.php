<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Holding accounts (migration 1364): payments wait in 1015/1016 until the bank shows the money. */
class HoldingAccountsTest extends TestCase
{
    public function test_payments_go_to_chequing_until_the_switch_then_to_holding(): void
    {
        $this->assertSame('1010', LedgerService::paymentAccount('stripe_payment', '2026-05-01', null), 'switch off');
        $this->assertSame('1010', LedgerService::paymentAccount('payment', '2026-02-24', '2026-02-25'), 'before the switch date');
        $this->assertSame('1015', LedgerService::paymentAccount('stripe_payment', '2026-02-25', '2026-02-25'));
        $this->assertSame('1016', LedgerService::paymentAccount('payment_allocation', '2026-05-01', '2026-02-25'));
        $this->assertSame('1016', LedgerService::paymentAccount('client_credit', '2026-10-14', '2026-02-25'));
        $this->assertSame('1015', LedgerService::paymentAccount('payment', '2026-05-01', '2026-02-25', 'stripe'), 'an invoice paid by Stripe');
        $this->assertSame('1016', LedgerService::paymentAccount('payment', '2026-05-01', '2026-02-25', 'cheque'));
    }

    public function test_our_stripe_is_told_apart_from_jobber_payments(): void
    {
        $this->assertTrue(LedgerSyncService::isStripeText('PREAUTHORIZED CREDIT (STRIPE)'));
        $this->assertFalse(LedgerSyncService::isStripeText('PREAUTHORIZED CREDIT (JOBBER - BY STRIPE)'));
        $this->assertTrue(LedgerSyncService::isStripeFeeLine('Stripe fees — payout po_1TTX (3 invoices)'));
        $this->assertTrue(LedgerSyncService::isStripeFeeLine('Payment processing fee — PREAUTHORIZED CREDIT (STRIPE)'));
        $this->assertFalse(LedgerSyncService::isStripeFeeLine('Payment processing fee — PREAUTHORIZED CREDIT (JOBBER - BY STRIPE)'));
        $this->assertFalse(LedgerSyncService::isStripeFeeLine('Point of sale SHELL'));
    }
}
