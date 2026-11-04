<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Services/Receipts/ReceiptParser.php';

final class ReceiptParserSplitTotalTest extends TestCase
{
    public function testSplitTotalWithCurrencyMarker(): void
    {
        $parsed = parseReceiptText("16&Macdonld Twn Pnty\n2026-11-04\nGST 5.0000 % 0.47\nPST 7.0000 % 0.49\nTOTAL\nCAD $\n17.49\nDebit Purchase $ 17.49\nInterac");

        $this->assertSame('17.49', $parsed['total']);
        $this->assertSame('0.47', $parsed['gst']);
        $this->assertSame('0.49', $parsed['pst']);
    }

    public function testSplitTotalAllowsCadPOcrNoise(): void
    {
        $parsed = parseReceiptText("16&Macdonld Twn Pnty\n2026-11-04\nTOTAL\nCAD P\n11.25\nDebit Purchase $ 11.25\nInterac");

        $this->assertSame('11.25', $parsed['total']);
    }

    public function testStackedTaxLabelsAndAmountAboveTotal(): void
    {
        $parsed = parseReceiptText("16&Macdonld Twn Pnty\n2026-11-04\n1 Deposit ($0.10)\nGST 5.0000 %\nPST 7.0000 %\n0.64\n0.56\n14.49\nTOTAL CAD $\nDebit Purchase $ 14.49\nInterac");

        $this->assertSame('14.49', $parsed['total']);
        $this->assertSame('0.64', $parsed['gst']);
        $this->assertSame('0.56', $parsed['pst']);
        $this->assertSame('13.29', $parsed['subtotal']);
    }

    public function testPaymentLineProvidesTotalWithoutTotalLabel(): void
    {
        $parsed = parseReceiptText("16&Macdonld Twn Pnty\n2026-11-04\nDebit Purchase $ 14.49\nInterac");

        $this->assertSame('14.49', $parsed['total']);
    }

    public function testExistingTotalWinsOverCashAndChangeLines(): void
    {
        $parsed = parseReceiptText("16&Macdonld Twn Pnty\n2026-11-04\nTOTAL 20.00\nChange $ 5.00\nCash $ 25.00");

        $this->assertSame('20.00', $parsed['total']);
    }

    public function testStackedLabelsWithTooFewAmountsAreNotPaired(): void
    {
        $lines = [
            'GST 5.0000 %',
            'PST 7.0000 %',
            '0.64',
            'TOTAL 14.49',
        ];

        $this->assertSame(['gst' => null, 'pst' => null], extractStackedTaxAmounts($lines));
    }

    public function testAmountAboveTotalRequiresMatchingPaymentLine(): void
    {
        $lines = ['16&Macdonld Twn Pnty', '12.34', 'TOTAL CAD $', 'Interac'];

        $this->assertNull(extractTotal(implode("\n", $lines), $lines));
    }

    public function testColumnFallbackRejectsImplausiblyLargeGst(): void
    {
        $parsed = extractColumnSeparatedAmounts([
            'GST',
            '5.00',
            'Debit Purchase $ 20.00',
        ]);

        $this->assertNull($parsed['gst']);
    }

    public function testColumnFallbackDoesNotTakeAmountAboveTaxLabel(): void
    {
        $parsed = extractColumnSeparatedAmounts([
            '9.99',
            'GST',
            '0.50',
        ]);

        $this->assertSame('0.50', $parsed['gst']);
    }
}
