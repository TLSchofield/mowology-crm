<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Rona prints "BARCODE QTY UNIT_PRICE EA", the description on the next line, then the line total
 * (#405 / #444, 2026-10-15: the reader named the earmuffs "2 32.49EA", 1 × $64.98).
 */
class ReceiptParserRonaTest extends TestCase
{
    private static function items(string $body): array
    {
        $t = "RONA+ Vancouver - Grandview\n83704\nITEM\nQTY PRICE\nTOTAL\n{$body}\nSubtotal:\n\$64.98\nGST:\n\$3.25\nTotal:\n\$68.23\n";
        return parseReceiptText($t)['line_items'];
    }

    public function test_the_description_is_the_name_and_the_quantity_is_read(): void
    {
        foreach (['EA', 'ER'] as $suffix) {   // OCR reads EA as ER
            $i = self::items("3295249290177 2 32.49{$suffix}\nEARMUFF GREY 28 DECIBEL\n64.98P");
            $this->assertCount(1, $i);
            $this->assertSame('EARMUFF GREY 28 DECIBEL', $i[0]['name']);
            $this->assertSame(2, $i[0]['quantity']);
            $this->assertSame(32.49, $i[0]['unit_price']);
            $this->assertSame('64.98', $i[0]['amount']);
            $this->assertSame('3295249290177', $i[0]['sku_raw']);
        }
    }

    public function test_several_items(): void
    {
        $i = self::items("3295249290177 1 9.99EA\nGLOVES NITRILE L\n9.99P\n0628451234567 3 4.29EA\nSCREWS #8 1-1/4\n12.87P");
        $this->assertSame(['GLOVES NITRILE L', 'SCREWS #8 1-1/4'], array_column($i, 'name'));
        $this->assertSame([1, 3], array_column($i, 'quantity'));
    }
}
