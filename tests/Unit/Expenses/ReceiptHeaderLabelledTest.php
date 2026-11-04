<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptLlmExtractor.php';

/** The AI's header values count only when printed under that field's own label (Codex review, 2026-10-28). */
class ReceiptHeaderLabelledTest extends TestCase
{
    private const TEXT = "ITEM 5.00\nSUBTOTAL 100.00\nTOTAL 105.00\nCASH 120.00\nCHANGE 15.00";

    public function testAnItemPriceIsNotTheGst(): void
    {
        $this->assertFalse(receiptHeaderAmountLabelled('gst', '5.00', self::TEXT));
    }

    public function testCashTenderedIsNotTheTotal(): void
    {
        $this->assertFalse(receiptHeaderAmountLabelled('total', '120.00', self::TEXT));
        $this->assertTrue(receiptHeaderAmountLabelled('total', '105.00', self::TEXT));
        $this->assertFalse(receiptHeaderAmountLabelled('total', '100.00', self::TEXT), 'the subtotal line is not the total');
        $this->assertTrue(receiptHeaderAmountLabelled('subtotal', '100.00', self::TEXT));
    }

    public function testLabelOnOneLineAmountOnTheNext(): void
    {
        $this->assertTrue(receiptHeaderAmountLabelled('total', '23.28', "Fuel sales \$ 23.28\nTOTAL\nCAD\$ 23.28"));
        $this->assertTrue(receiptHeaderAmountLabelled('gst', '1.11', "GST INCLUDED \$ 1.11"));
    }

    public function testThousandsSeparator(): void
    {
        $this->assertTrue(receiptHeaderAmountLabelled('total', '1234.56', "TOTAL \$1,234.56"));
    }
}
