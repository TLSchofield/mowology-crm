<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductPriceHistoryServiceTest extends TestCase
{
    public function testEffectivePricePrefersUnitPriceThenFallsBackToLineTotal(): void
    {
        $this->assertSame(12.5, ProductPriceHistoryService::effectivePrice(12.5, 30.0, 3.0));
        $this->assertSame(10.0, ProductPriceHistoryService::effectivePrice(0.0, 30.0, 3.0));
        $this->assertNull(ProductPriceHistoryService::effectivePrice(null, 30.0, 0.0));
    }

    public function testPickReturnsNewestAndCheapestOtherPurchase(): void
    {
        $rows = [
            ['vendor' => 'Vendor A', 'date' => '2026-10-20', 'unit_price' => 29.99, 'per_unit_value' => 3.1691, 'per_unit_label' => '$/L'],
            ['vendor' => 'Vendor B', 'date' => '2026-09-01', 'unit_price' => 27.00, 'per_unit_value' => 2.8541, 'per_unit_label' => '$/L'],
        ];
        $hint = ProductPriceHistoryService::pick($rows, true);
        $this->assertSame('Vendor A', $hint['last']['vendor']);
        $this->assertSame('Vendor B', $hint['cheapest']['vendor']);
        $this->assertSame(2, $hint['count']);
    }

    public function testVendorSummaryAndHintUseCompleteHistoryBeyondFiveHundredRows(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('SQLite PDO driver is required.');
        }
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE catalog_products (id INTEGER PRIMARY KEY, canonical_name TEXT, unit_size REAL, unit_size_unit TEXT)');
        $db->exec('CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE expenses (id INTEGER PRIMARY KEY, expense_date TEXT, vendor_id INTEGER, vendor_name_raw TEXT, status TEXT, accounting_category TEXT)');
        $db->exec('CREATE TABLE expense_line_items (id INTEGER PRIMARY KEY, expense_id INTEGER, catalog_product_id INTEGER, quantity REAL, unit_price REAL, line_total REAL)');
        $db->exec("INSERT INTO catalog_products VALUES (1, 'Oil', 1, 'l')");
        $db->exec("INSERT INTO vendors VALUES (1, 'Vendor A')");
        $expense = $db->prepare('INSERT INTO expenses VALUES (?, ?, 1, NULL, ?, ?)');
        $line = $db->prepare('INSERT INTO expense_line_items VALUES (?, ?, 1, 1, ?, ?)');
        for ($i = 1; $i <= 501; $i++) {
            $expense->execute([$i, sprintf('2026-01-%02d', (($i - 1) % 28) + 1), 'approved', 'Materials']);
            $price = $i === 1 ? 1.0 : 10.0;
            $line->execute([$i, $i, $price, $price]);
        }

        $service = new ProductPriceHistoryService($db);
        $vendors = $service->byVendor(1);
        $hint = $service->hint(1);

        $this->assertSame(501, $vendors[0]['count']);
        $this->assertSame(1.0, $vendors[0]['cheapest_price']);
        $this->assertSame(501, $hint['count']);
        $this->assertSame(1.0, $hint['cheapest']['price']);
    }
}
