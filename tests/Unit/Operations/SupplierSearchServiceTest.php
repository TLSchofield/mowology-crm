<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * "japanese maple #5" → who has it, cheapest in stock first (SupplierSearchService, 2026-10-19).
 * Fake plants and suppliers; the shapes are the real lists' (Specimen: caps + common name in the
 * comment; Inline: botanical only).
 */
class SupplierSearchServiceTest extends TestCase
{
    public function testQueriesSplitIntoWordsAndASize(): void
    {
        $this->assertSame(['words' => ['japanese', 'maple'], 'size' => '#5'], SupplierSearchService::parseQuery('Japanese Maple #5'));
        $this->assertSame('#5', SupplierSearchService::parseQuery('japanese maple 5 gal')['size']);
        $this->assertSame('#2', SupplierSearchService::parseQuery('hydrangea no.2 pot')['size']);
        $this->assertSame('2.0M', SupplierSearchService::parseQuery('japanese maple 2m')['size'], 'as the lists write heights');
        $this->assertSame('6CM CAL', SupplierSearchService::parseQuery('red maple 6cm')['size']);
        $this->assertSame(['words' => ['garden', 'soil'], 'size' => null], SupplierSearchService::parseQuery('garden soil'));
        $this->assertSame(['boxwood'], SupplierSearchService::parseQuery('the boxwood plants')['words'], 'filler words dropped');
    }

    public function testCommonNamesLeadToBotanicalStems(): void
    {
        $this->assertSame(['acer palm'], SupplierSearchService::stems(['ACER PALMATUM', 'ACER PALM ATROPURPUREUM', "Acer palmatum 'Bloodgood'"]));
        $this->assertSame(['buxu semp', 'buxu micr'], array_map(fn($s) => substr($s, 0, 4) . ' ' . substr($s, strpos($s, ' ') + 1), SupplierSearchService::stems(['BUXUS SEMPERVIRENS', 'BUXUS SEMPERVIRENS', 'BUXUS MICROPHYLLA'])));
        $this->assertSame(['buxus gree'], SupplierSearchService::stems(['BUXUS x GREEN MOUNTAIN']), 'a hybrid x is skipped');
        $this->assertSame([], SupplierSearchService::stems(['ROSE']), 'no species, no stem');
    }

    public function testMarkupAndDistance(): void
    {
        $this->assertSame(58.05, SupplierSearchService::sell(43.0, 35.0));
        $this->assertSame(62.11, SupplierSearchService::sell(43.0, 35.0, 7.0), 'cost + 7% PST we paid, then 35%');
        $this->assertNull(SupplierSearchService::sell(null, 35.0));
        $this->assertSame(['km' => 14.0, 'minutes' => 21], SupplierSearchService::roadEstimate(10.0));
        $this->assertSame(['Order Minimum $750.00'], SupplierSearchService::orderRules('["Order Minimum $750.00","11cm Round - 10/tray"]'));
        $this->assertSame([], SupplierSearchService::orderRules('not json'));
    }

    private static function row(array $o): array
    {
        return $o + ['id' => 1, 'name' => 'ACER PALMATUM', 'size' => '#5 POT', 'size_code' => 'no5', 'price' => '43.00', 'available' => 10,
                     'comment' => 'Japanese Maple', 'category' => 'TREES', 'catalog_product_id' => 7, 'name_key' => 'acer|palmatum',
                     'product_code' => 'X', 'price_change' => null, 'prev_price' => null, 'list_id' => 1, 'list_date' => '2026-10-15',
                     'rules_json' => '[]', 'supplier_account_id' => 2, 'supplier_name' => 'Fake Trees', 'lat' => 49.2261, 'lng' => -122.6962,
                     'address' => 'Pitt Meadows', 'family' => 'plants', 'canonical_name' => 'Acer palmatum', 'common_names' => null];
    }

    public function testCheapestInStockLeadsAndStaleOrSoldOutFallBack(): void
    {
        $rows = [
            self::row(['id' => 1, 'supplier_account_id' => 2, 'supplier_name' => 'Cheap But Sold Out', 'price' => '30.00', 'available' => 0]),
            self::row(['id' => 2, 'supplier_account_id' => 3, 'supplier_name' => 'Old List', 'price' => '35.00', 'list_date' => '2026-07-01']),
            self::row(['id' => 3, 'supplier_account_id' => 4, 'supplier_name' => 'Fresh In Stock', 'price' => '43.00', 'available' => 163]),
            self::row(['id' => 4, 'supplier_account_id' => 4, 'supplier_name' => 'Fresh In Stock', 'price' => '41.00', 'available' => 0]),
        ];
        $job = ['lat' => 49.2573, 'lng' => -123.1398, 'label' => '1 Test St'];
        $g = SupplierSearchService::group($rows, ['japanese', 'maple'], $job, 35.0, '2026-10-19', 10, [], 7.0);
        $this->assertCount(1, $g, 'one product, one size');
        $names = array_column($g[0]['offers'], 'supplier');
        $this->assertSame(['Fresh In Stock', 'Old List', 'Cheap But Sold Out'], $names);
        $this->assertSame(43.0, $g[0]['best']['price'], 'a supplier\'s in-stock line beats its own sold-out cheaper one');
        $this->assertSame(62.11, $g[0]['sell']);
        $this->assertTrue($g[0]['offers'][1]['stale']);
        $this->assertTrue($g[0]['best']['distance']['estimate']);
        $this->assertGreaterThan(30, $g[0]['best']['distance']['km']);
    }

    public function testBulkMaterialsAreNotMarkedUpHere(): void
    {
        $g = SupplierSearchService::group([self::row(['name' => 'Garden Soil', 'size' => null, 'size_code' => null, 'available' => null, 'family' => 'hardscape',
                                                      'catalog_product_id' => 9, 'comment' => '2P', 'canonical_name' => 'Garden Soil'])],
                                          ['garden', 'soil'], null, 35.0, '2026-10-19', 10);
        $this->assertTrue($g[0]['bulk']);
        $this->assertNull($g[0]['sell'], 'Otto and Sam price bulk material installed');
        $this->assertNull($g[0]['best']['in_stock'], 'the list has no stock column');
    }
}
