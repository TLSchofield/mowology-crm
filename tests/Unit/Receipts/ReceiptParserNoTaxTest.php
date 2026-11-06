<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Services/Receipts/ReceiptParser.php';

/** A receipt that names no tax charged none: no 5% guess (City of Vancouver landfill, Tim 2026-11-06). */
final class ReceiptParserNoTaxTest extends TestCase
{
    private const LANDFILL = "CITY OF\nVANCOUVER\nDate: 04/07/25\nNet: 345 kg\nMaterial\n10 - Green Waste\n\$40.71\n0.35 tonne @ \$118.00/tonne\nTotal Fee:\n\$41.00\nTotal Tendered:\n\$41.00\nDebit Card\n\$41.00";

    public function testNoTaxWordMeansNoGst(): void
    {
        $p = parseReceiptText(self::LANDFILL);
        $this->assertSame('41.00', $p['total']);
        $this->assertSame('0.00', $p['gst']);
        $this->assertSame('41.00', $p['subtotal']);
        $this->assertSame('exempt', $p['gst_source']);
        $this->assertArrayNotHasKey('gst_estimated', $p);
    }

    public function testAReceiptThatNamesGstStillGetsTheGuess(): void
    {
        // "GST incl." names GST without printing an amount: the 5% split stays.
        $p = parseReceiptText("ESSO\n2026-10-02\nDiesel 40.000 L\nTOTAL \$63.00\nGST incl.\nDebit \$63.00");
        $this->assertSame('63.00', $p['total']);
        $this->assertSame('3.00', $p['gst']);
    }

    public function testTaxWords(): void
    {
        $this->assertTrue(receiptPrintsNoTax(["TAXI 89", "Fare 21.75", "TOTAL 21.75"]));
        $this->assertFalse(receiptPrintsNoTax(["GST# 123456789 RT0001"]));
        $this->assertFalse(receiptPrintsNoTax(["Tax", "1.05"]));
        $this->assertFalse(receiptPrintsNoTax(["G.S.T. 1.20"]));
        $this->assertFalse(receiptPrintsNoTax(["PST 7%"]));
    }
}
