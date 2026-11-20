<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptParser.php';

/**
 * A pump receipt prints "TOTAL" on one line and "CAD$ 23.28" on the next. The split-line fallback
 * accepted only "$23.28" or "23.28", so the total came back empty and the phone's Review Receipt
 * sheet opened with a blank Total (2026-10-28, Chevron).
 */
class ReceiptParserTotalCadTest extends TestCase
{
    public function testTotalOnTheLineAfterTheLabelWithCadPrefix(): void
    {
        $text = "Supreme (91)\n10.124L AT \$2.299/L\nFuel sales \$ 23.28\nGST INCLUDED \$ 1.11\nTOTAL\nCAD\$ 23.28\ne-outh Completion";
        $this->assertSame('23.28', parseReceiptText($text)['total']);
    }

    public function testPlainSplitTotalStillWorks(): void
    {
        $this->assertSame('64.26', extractTotal("Item 10.00\nTOTAL\n\$64.26", ["Item 10.00", "TOTAL", "\$64.26"]));
    }
}
