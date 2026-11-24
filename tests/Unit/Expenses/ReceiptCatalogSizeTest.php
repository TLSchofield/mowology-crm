<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReceiptCatalogSizeTest extends TestCase
{
    /** @dataProvider sizes */
    public function testParsesPackageSizes(string $name, float $qty, string $unit): void
    {
        $this->assertSame(['qty' => $qty, 'unit' => $unit], ReceiptCatalogService::parseSize($name));
    }

    public function sizes(): array
    {
        return [['DEF 9.46L', 9.46, 'l'], ['DEF 9.46 litres', 9.46, 'l'], ['Coolant 20 L', 20.0, 'l'], ['Oil 500ml', 500.0, 'ml'],
                ['Grease 2kg', 2.0, 'kg'], ['Line 10m', 10.0, 'm'], ['Filters 12 pk', 12.0, 'pc'], ['Blades 12 pack', 12.0, 'pc'], ['Ties 6pc', 6.0, 'pc']];
    }

    public function testInvalidOrMissingSizeIsNull(): void
    {
        $this->assertNull(ReceiptCatalogService::parseSize('Oil filter'));
        $this->assertNull(ReceiptCatalogService::parseSize('Oil 0 L'));
    }

    public function testNormalizesPerUnitPrice(): void
    {
        $this->assertSame(['value' => 3.1702, 'label' => '$/L'], ReceiptCatalogService::perUnit(29.99, 9.46, 'l'));
        $this->assertSame(['value' => 10.0, 'label' => '$/L'], ReceiptCatalogService::perUnit(5.0, 500, 'ml'));
        $this->assertSame(['value' => 2.0, 'label' => '$/piece'], ReceiptCatalogService::perUnit(12.0, 6, 'pc'));
        $this->assertNull(ReceiptCatalogService::perUnit(10.0, 0, 'l'));
    }

    public function testSizeSchemaRequiresBothColumns(): void
    {
        $this->assertFalse(ReceiptCatalogService::hasSizeColumns([]));
        $this->assertFalse(ReceiptCatalogService::hasSizeColumns(['unit_size']));
        $this->assertFalse(ReceiptCatalogService::hasSizeColumns(['unit_size_unit']));
        $this->assertTrue(ReceiptCatalogService::hasSizeColumns(['unit_size', 'unit_size_unit']));
    }
}
