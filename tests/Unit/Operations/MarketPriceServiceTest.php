<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Local market prices (MarketPriceService, migration 1375): a Shopify store's public products.json →
 * bulk-material prices per bag size, kept only when new or changed; the current price per material.
 * Fake store data in My Garden Bag's shape.
 */
class MarketPriceServiceTest extends TestCase
{
    private static function store(array $products): string
    {
        return json_encode(['products' => $products]);
    }

    private static function product(string $title, string $type, array $variants): array
    {
        return ['title' => $title, 'product_type' => $type, 'variants' => array_map(fn($t, $p) => ['title' => $t, 'price' => $p], array_keys($variants), $variants)];
    }

    private static function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE market_price_sources (id INTEGER PRIMARY KEY, region TEXT, name TEXT, url TEXT, reader TEXT, what TEXT, is_active INT DEFAULT 1, last_fetched_at TEXT, last_error TEXT)");
        $db->exec("CREATE TABLE market_prices (id INTEGER PRIMARY KEY AUTOINCREMENT, source_id INT, product TEXT, variant TEXT, product_type TEXT, material TEXT, yards REAL, price REAL,
                   seen_on TEXT, prev_price REAL, price_change TEXT, UNIQUE (source_id, product, variant, seen_on))");
        $db->exec("INSERT INTO market_price_sources (id, region, name, url, reader, what) VALUES (1, 'bc-lower-mainland', 'Fake Bags', 'https://fakebags.test', 'shopify', 'Delivered in a bag.')");
        return $db;
    }

    public function testBagSizesAndMaterials(): void
    {
        $this->assertSame(1.0, MarketPriceService::yards('1 Yard Bag'));
        $this->assertSame(0.5, MarketPriceService::yards('1/2 Yard Bag'));
        $this->assertSame(0.25, MarketPriceService::yards('1/4 Yard'));
        $this->assertNull(MarketPriceService::yards('Default Title'));
        $this->assertSame('compost', MarketPriceService::material('Mushroom Manure', 'Soil'));
        $this->assertSame('mulch', MarketPriceService::material('Bark Mulch (Composted)', 'Mulch'), 'mulch before compost');
        $this->assertSame('gravel', MarketPriceService::material('3/4" Clear Crush', 'Gravel'));
        $this->assertSame('soil', MarketPriceService::material('Botanical Blend', 'Soil'));
        $this->assertNull(MarketPriceService::material('Empty Yard Bag', ''), 'an empty bag is not material');
        $this->assertNull(MarketPriceService::material('Folding Rake', 'tools'));
    }

    public function testOnlyBulkLinesAreKept(): void
    {
        $rows = MarketPriceService::parseShopify(self::store([
            self::product('Mushroom Manure', 'Soil', ['1 Yard Bag' => '155.00', '1/2 Yard Bag' => '125.00', '1/4 Yard Bag' => '105.00']),
            self::product('Folding Head Landscaping Rake', '', ['Default Title' => '175.00']),
            self::product('Empty Yard Bag', '', ['Regular (1yd)' => '20.00']),
        ]));
        $this->assertCount(3, $rows);
        $this->assertSame(['Mushroom Manure', 'compost', 1.0, 155.0], [$rows[0]['product'], $rows[0]['material'], $rows[0]['yards'], $rows[0]['price']]);
    }

    public function testWeeklyReadKeepsOnlyNewOrChangedPrices(): void
    {
        $db = self::db();
        $pages = [self::store([self::product('Mushroom Manure', 'Soil', ['1 Yard Bag' => '155.00', '1/2 Yard Bag' => '125.00'])])];
        $svc = new MarketPriceService($db, function (string $url) use (&$pages) { return $pages[0]; });
        $t0 = strtotime('2026-10-19 09:00');
        $this->assertSame(['sources' => 1, 'stored' => 2, 'errors' => 0], $svc->refreshDue(false, $t0));
        $this->assertSame(0, $svc->refreshDue(false, $t0 + 86400)['sources'], 'read at most once a week');
        $pages = [self::store([self::product('Mushroom Manure', 'Soil', ['1 Yard Bag' => '165.00', '1/2 Yard Bag' => '125.00'])])];
        $r = $svc->refreshDue(false, $t0 + 8 * 86400);
        $this->assertSame(1, $r['stored'], 'only the changed 1-yard price is kept again');
        $up = $db->query("SELECT price, prev_price, price_change FROM market_prices WHERE variant = '1 Yard Bag' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([165.0, 155.0, 'up'], [(float)$up['price'], (float)$up['prev_price'], $up['price_change']]);

        $cur = $svc->current(['mushroom', 'manure'], 'compost');
        $this->assertSame('Fake Bags', $cur[0]['source']);
        $this->assertSame(['1' => 165.0, '0.5' => 125.0], $cur[0]['sizes'], 'the latest read of each size');
        $this->assertSame('Mushroom Manure', $svc->current(['soil', 'amender'], 'compost')[0]['product'], 'no name match: same material');
    }

    public function testABrokenSourceIsRecordedNotFatal(): void
    {
        $db = self::db();
        $svc = new MarketPriceService($db, fn() => null);
        $this->assertSame(['sources' => 1, 'stored' => 0, 'errors' => 1], $svc->refreshDue(true, strtotime('2026-10-19')));
        $this->assertStringContainsString('no answer', (string)$db->query("SELECT last_error FROM market_price_sources")->fetchColumn());
    }
}
