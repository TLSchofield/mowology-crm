<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptParser.php';
require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptSmartMatch.php';

/** Vendors that charge no PST (and no GST), e.g. the City of Vancouver landfill (Tim, 2026-11-19). */
class VendorPstExemptTest extends TestCase
{
    public function test_not_flagged_leaves_receipt_alone(): void
    {
        $p = ['total' => '25.00'];
        $this->assertSame($p, applyVendorPstExempt($p, ['vendor_pst_exempt' => false]));
    }

    public function test_flagged_zeroes_pst(): void
    {
        $r = applyVendorPstExempt(['total' => '25.00', 'gst' => '1.19', 'subtotal' => '23.81'], ['vendor_pst_exempt' => true]);
        $this->assertSame('0.00', $r['pst']);
        $this->assertSame('23.81', $r['subtotal']);
    }

    public function test_pst_and_gst_exempt_total_only_becomes_subtotal(): void
    {
        $r = applyVendorPstExempt(['total' => '25.00', 'gst' => '0.00', 'gst_exempt' => true], ['vendor_pst_exempt' => true]);
        $this->assertSame('0.00', $r['pst']);
        $this->assertSame('25.00', $r['subtotal']);
    }

    public function test_estimated_gst_does_not_invent_a_subtotal(): void
    {
        $r = applyVendorPstExempt(['total' => '25.00', 'gst' => '1.19', 'gst_estimated' => true], ['vendor_pst_exempt' => true]);
        $this->assertArrayNotHasKey('subtotal', $r);
    }
}
