<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A Stripe fee line (split out of a payout) is never offered as a receipt's bank line (Tim, 2026-10-15). */
class StripeFeeLineTest extends TestCase
{
    public function testKnowsAFeeLine(): void
    {
        $this->assertTrue(BankImportService::isStripeFeeLine('Stripe fees — payout po_1TZKeWPe8as9gGnWkz27z4Te (6 invoices: INV-1)', 'po_1TZKeWPe8as9gGnWkz27z4Te'));
        $this->assertFalse(BankImportService::isStripeFeeLine('Point of sale CHV43016 16TH', null));
        $this->assertFalse(BankImportService::isStripeFeeLine('Stripe fees — payout po_x', null), 'needs the payout reference too');
        $this->assertFalse(BankImportService::isStripeFeeLine('STRIPE monthly', 'po_x'), 'a real Stripe charge on the card is a purchase');
    }
}
