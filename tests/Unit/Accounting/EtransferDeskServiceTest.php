<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's sentence for a pending e-Transfer.
 */
class EtransferDeskServiceTest extends TestCase
{
    public function test_she_says_what_the_transfer_is_and_what_she_learned(): void
    {
        $lines = [['invoice_number' => 'INV-2026-0423', 'apply_amount' => 364.65]];
        $this->assertSame("STRATA PLAN BCS-2106 paid \$364.65. You've recorded STRATA PLAN BCS-2106 paying Alexandra Bee's invoices before, so oldest first it covers INV-2026-0423.",
            EtransferDeskService::say('learned', 'STRATA PLAN BCS-2106', 364.65, 0, $lines, 'Alexandra Bee', null, ''));
        $this->assertStringContainsString('dismiss it rather than record it twice',
            EtransferDeskService::say('duplicate', 'Kam Singh', 66.15, 0, [], null, ['pay_date' => '2026-09-01', 'invoice_numbers' => ['INV-2026-0375']], ''));
        $this->assertStringContainsString('Type the invoice number', EtransferDeskService::say('none', 'Bob', 10, 0, [], null, null, ''));
    }
}
