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

    /** Replay 2026-11-04: "PST 7.0000 %" / "TOTAL" / "CAD $" / "0.53" / "11.53" — the payment line settles which is the total. */
    public function testPaymentLineSettlesStackedTotal(): void
    {
        $parsed = parseReceiptText("16&Macdonld Twn Pnty\n2026-08-12\nGST 5.0000 % 0.50\nPST 7.0000 %\nTOTAL\nCAD $\n0.53\n11.53\nQTY PRICE\nAMOUNT\n3 0.07\n0.21\nDebit Purchase $ 11.53\nInterac");

        $this->assertSame('11.53', $parsed['total']);
        $this->assertSame('0.50', $parsed['gst']);
    }

    /** A payment line that is nowhere near the TOTAL label's amounts never overrides a printed total. */
    public function testPaymentLineDoesNotOverrideUnrelatedTotal(): void
    {
        $parsed = parseReceiptText("SHOP\n2026-08-12\nGST 5% 1.00\nTOTAL\n21.00\nGift card\n5.00\nDebit Purchase $ 16.00");

        $this->assertSame('21.00', $parsed['total']);
    }

    /** Zero-rated items at a store that itemises tax: no GST line means no GST, not a 5% guess. */
    public function testNoGstGuessWhenTaxIsItemised(): void
    {
        $parsed = parseReceiptText("16&Macdonld Twn Pnty\nGSTV:\nSee GST #\n2025-06-03\nOTR WATER 500ML\n1 4.49\n4.49\nTOTAL,\nCAD $\n5.69\nDebit Purchase $ 5.69\nInterac");

        $this->assertSame('5.69', $parsed['total']);
        $this->assertSame('0.00', $parsed['gst']);
    }

    /** A garbled rate line is still a tax line: keep the old estimate rather than claim no GST. */
    public function testGarbledRateLineKeepsEstimate(): void
    {
        $parsed = parseReceiptText("16&Macdonld Twn Pnty\nSee GST #\n2026-10-20\n9:8888 % 8:18\nTOTAL CAD $ 22.75\nDebit Purchase $ 22.75");

        $this->assertNotSame('0.00', $parsed['gst']);
    }

    /** No legend at all: the 5% estimate is unchanged. */
    public function testEstimateUnchangedWithoutLegend(): void
    {
        $parsed = parseReceiptText("SHOP\n2026-10-20\nTOTAL 21.00");

        $this->assertSame('1.00', $parsed['gst']);
    }

    /** Codex review: a split tender proves one tender amount, not the total. */
    public function testSplitTenderPaymentLineIsNotTheTotal(): void
    {
        $parsed = parseReceiptText("SHOP\n2026-11-04\nITEM 21.00\nDebit Purchase $ 16.00\nGift Card $ 5.00");

        $this->assertNotSame('16.00', $parsed['total']);
    }

    /** Codex review: a "G - GST" legend with a G-flagged item means GST was charged. */
    public function testLegendWithGstFlaggedItemKeepsGst(): void
    {
        $parsed = parseReceiptText("SHOP\n2026-11-04\nWIDGET 10.00 G\nG - GST\nTOTAL 10.50");

        $this->assertNotSame('0.00', $parsed['gst']);
    }

    /** Codex review: 15% HST is 13.04% of the total and must not be rejected as implausible. */
    public function testFifteenPercentHstIsAccepted(): void
    {
        $parsed = parseReceiptText("SHOP\n2026-11-04\nSUBTOTAL\nTOTAL\nGST/HST\nCAD $\n100.00\n115.00\n15.00\nDebit Purchase $ 115.00");

        $this->assertSame('115.00', $parsed['total']);
        $this->assertNotSame('5.48', $parsed['gst']);
    }
}
