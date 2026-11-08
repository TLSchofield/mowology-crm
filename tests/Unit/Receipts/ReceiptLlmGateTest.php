<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Services/Receipts/ReceiptParser.php';
require_once dirname(__DIR__, 3) . '/app/Services/Receipts/ReceiptLlmExtractor.php';

/** The AI line-item gate against what the receipt prints (docs/plans/2026-11-06-receipt-reading-fixes-round-2.md, fix 2). */
final class ReceiptLlmGateTest extends TestCase
{
    // Invented, shaped like the Shell receipt the rules read as 35.23.
    private const SHELL = "TRANSACTION RECORD\nSHELL\nTax Description\nQty\nAmount\nB SHELL ROTELLA DEF\n1\n\$36.99\nSub Total \$36.99\n5.0% GST tax on\n\$36.99\n\$1.85\nTOTAL\n\$41.43";

    public function testItemsMatchingThePrintedSubtotalPassWhenTheRulesMisreadIt(): void
    {
        $items = [['name' => 'B SHELL ROTELLA DEF', 'line_total' => '36.99']];
        $this->assertNull(validateLlmLineItems($items, self::SHELL, 35.23));
    }

    public function testASumPrintedOnlyAsAnItemStillFails(): void
    {
        // 36.99 is printed, but not under a subtotal label: no.
        $text = "SHOP\nWIDGET 36.99\nSUBTOTAL 40.00\nGST 2.00\nTOTAL 42.00";
        $this->assertSame('sum_mismatch', validateLlmLineItems([['name' => 'WIDGET', 'line_total' => '36.99']], $text, 40.00));
    }

    public function testADepartmentLineIsNotASubtotal(): void
    {
        // Fuel plus store items: only the fuel row would sum to the printed FUEL SALES — not enough.
        $text = "CHEVRON\nREGULAR 30.000L 36.99\nCOFFEE 2.49\nFUEL SALES 36.99\nSUBTOTAL 39.48\nGST 1.97\nTOTAL 41.45";
        $this->assertSame('sum_mismatch', validateLlmLineItems([['name' => 'REGULAR', 'line_total' => '36.99']], $text, 39.48));
    }

    public function testNoTaxReceiptMatchesItsPrintedTotal(): void
    {
        $text = "CITY OF\nVANCOUVER\n10 - Green Waste\n\$41.00\nTotal Fee:\n\$41.00";
        $this->assertTrue(receiptLlmSumPrinted(41.00, $text));
        $this->assertFalse(receiptLlmSumPrinted(41.00, $text . "\nGST 1.95"));
    }

    public function testPrintedDeposits(): void
    {
        $this->assertSame(0.30, receiptLlmPrintedDeposits("COCA COLA 500ML 8.00\n2 Deposit 0.20\nDEPOSIT \$0.10\nTOTAL 13.54"));
        $this->assertSame(0.0, receiptLlmPrintedDeposits("Deposit: \$0.00\nTOTAL DEPOSIT 5.00"));
        $this->assertSame(0.0, receiptLlmPrintedDeposits("NO DEPOSITS HERE 4.00"));
    }
}
