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

    /** The medium match is the proposal: read() must not spread it oldest-first (strip review, 2026-11-10). */
    public function testExactMediumMatchIsTheProposal(): void
    {
        $et = ['matched_invoice_id' => 465, 'matched_balance' => '1890.00'];
        $this->assertTrue(EtransferDeskService::exactMatch($et, 'medium', 'INV-2026-0465', 1890.0));
        $this->assertFalse(EtransferDeskService::exactMatch($et, 'medium', 'INV-2026-0465', 1800.0), 'balance differs: a reading, not the proposal');
        $this->assertFalse(EtransferDeskService::exactMatch($et, 'high', 'INV-2026-0465', 1890.0), 'high is handled as a match already');
        $this->assertFalse(EtransferDeskService::exactMatch($et, 'medium', '', 1890.0), 'the matched invoice was cleared (paid by a part of this transfer)');
        $this->assertFalse(EtransferDeskService::exactMatch(['matched_balance' => '1890.00'], 'medium', 'INV-2026-0465', 1890.0));
        $src = (string)file_get_contents(__DIR__ . '/../../../app/Modules/Accounting/Services/EtransferDeskService.php');
        $this->assertStringContainsString('$exact = self::exactMatch($et, (string)$conf, $matchedNo, $active);', $src);
        $this->assertStringContainsString('if (!$duplicate && !$exact) {', $src, 'the spread is skipped for the exact match');
    }
}
