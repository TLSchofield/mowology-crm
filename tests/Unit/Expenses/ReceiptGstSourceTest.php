<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptParser.php';

class ReceiptGstSourceTest extends TestCase
{
    public function testPrintedGstIsTaggedPrinted(): void
    {
        $r = parseReceiptText("Hardware\n2026-10-01\nSUBTOTAL 100.00\nGST 5.00\nTOTAL 105.00");
        $this->assertSame('5.00', $r['gst']);
        $this->assertSame('printed', $r['gst_source']);
        $this->assertArrayNotHasKey('gst_estimated', $r);
    }

    public function testEstimatedGstKeepsItsNumberButIsFlagged(): void
    {
        $r = parseReceiptText("Hardware\n2026-10-01\nGST incl.\nTOTAL 105.00");
        $this->assertSame('5.00', $r['gst'], 'existing callers still get the number');
        $this->assertTrue($r['gst_estimated']);
        $this->assertSame('estimated', $r['gst_source']);
    }

    public function testSanitizeReplacementIsFlagged(): void
    {
        $r = sanitizeReceiptAmounts(['total' => '2.61', 'gst' => '5.00', 'pst' => null, 'subtotal' => null]);
        $this->assertTrue($r['gst_estimated']);
        $this->assertSame('estimated', $r['gst_source']);
        $this->assertTrue($r['subtotal_derived']);
    }
}
