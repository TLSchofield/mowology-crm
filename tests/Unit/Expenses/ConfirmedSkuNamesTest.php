<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Approving a receipt confirms its SKU → name pairs (migration 1322, 2026-10-11). */
final class ConfirmedSkuNamesTest extends TestCase
{
    public function testPairs(): void
    {
        $items = [
            ['name' => 'Felco Pruner #4', 'sku_raw' => 'FELCO4', 'product_id' => null],
            ['name' => 'Felco Pruner #4', 'sku_raw' => 'FELCO4', 'product_id' => null],   // same SKU twice on one receipt
            ['name' => '10% off', 'sku_raw' => 'DISC', 'is_adjustment' => 1],
            ['name' => 'Bag of mulch', 'sku_raw' => ''],
            ['name' => '', 'sku_raw' => 'X1'],
            ['name' => 'Rake', 'sku_raw' => '0123456789', 'product_id' => '42'],
        ];
        $this->assertSame([
            ['sku' => 'FELCO4', 'name' => 'Felco Pruner #4', 'product_id' => null],
            ['sku' => '0123456789', 'name' => 'Rake', 'product_id' => 42],
        ], confirmedSkuPairs($items));
        $this->assertSame([], confirmedSkuPairs([]));
    }
}
