<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A supplier's public Shopify shop read as a price list (Tim, 2026-10-20: brecksbulbs.ca). Fake products shaped like
 * the real /products.json checked that day (HTML-entity types, pack variants "10 Bulbs | Ships in Fall", blank types
 * mixing plants and gifts). No live calls in tests.
 */
final class SupplierWebCatalogTest extends TestCase
{
    private function products(): array
    {
        return [
            ['title' => 'Nemo Tulip', 'handle' => 'nemo-tulip', 'product_type' => 'Fall-Planted Bulbs', 'variants' => [
                ['title' => '25 Bulbs | Ships in Fall', 'sku' => '16987', 'price' => '19.99', 'available' => false],
                ['title' => '50 Bulbs | Ships in Fall', 'sku' => '16988', 'price' => '34.99', 'available' => true]]],
            ['title' => 'Sweet Summer Fantasy Phlox', 'handle' => 'sweet-phlox', 'product_type' => 'Sun Perennials', 'variants' => [
                ['title' => 'Default Title', 'sku' => '', 'price' => '42.99', 'available' => true]]],
            ['title' => 'Winter Wonderland Dishtowel Set', 'handle' => 'towel', 'product_type' => '', 'variants' => [['title' => 'Default Title', 'sku' => '1', 'price' => '14.99', 'available' => true]]],
            ['title' => 'Yellow Cocotte Lily', 'handle' => 'cocotte', 'product_type' => '', 'variants' => [['title' => '3 Bulbs | Ships in Spring', 'sku' => '2', 'price' => '12.5', 'available' => false]]],
            ['title' => 'French Lavender Reed Diffuser', 'handle' => 'diffuser', 'product_type' => 'Gifts', 'variants' => [['title' => 'Default Title', 'sku' => '3', 'price' => '44.99', 'available' => true]]],
            ['title' => 'Antique Décor Planter', 'handle' => 'planter', 'product_type' => 'D&eacute;cor', 'variants' => [['title' => 'Default Title', 'sku' => '4', 'price' => '30', 'available' => true]]],
            ['title' => 'Priced wrong', 'handle' => 'x', 'product_type' => 'Hostas', 'variants' => [['title' => 'Default Title', 'sku' => '5', 'price' => 'call']]],
        ];
    }

    private function fakeHttp(array $pages, array &$seen = []): callable
    {
        return function (string $url) use ($pages, &$seen): array {
            $seen[] = $url;
            parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
            $page = (int)($q['page'] ?? 1);
            $r = $pages[$page] ?? ['products' => []];
            return is_int($r) ? ['code' => $r, 'body' => ''] : ['code' => 200, 'body' => is_string($r) ? $r : json_encode($r)];
        };
    }

    public function testSafeBase(): void
    {
        $this->assertSame('https://www.brecksbulbs.ca', SupplierWebCatalogReader::safeBase('https://www.brecksbulbs.ca/collections/x?y=1'));
        $this->assertSame('https://brecksbulbs.ca', SupplierWebCatalogReader::safeBase('Brecksbulbs.ca'));
        foreach (['http://shop.example.com', 'https://127.0.0.1', 'https://localhost', 'https://user:pw@shop.example.com', 'https://shop.example.com:8443',
                  'https://intranet', 'https://printer.local', 'ftp://shop.example.com', ''] as $bad) {
            try { SupplierWebCatalogReader::safeBase($bad); $this->fail("accepted: {$bad}"); } catch (InvalidArgumentException $e) { $this->assertTrue(true); }
        }
    }

    public function testSizeAndShipping(): void
    {
        $this->assertSame(['25 Bulbs', 'Ships in Fall'], SupplierWebCatalogReader::sizeOf('25 Bulbs | Ships in Fall'));
        $this->assertSame(['Triple', 'Ships in Fall'], SupplierWebCatalogReader::sizeOf('Triple | Ships in Fall'));
        $this->assertSame(['', ''], SupplierWebCatalogReader::sizeOf('Default Title'));
        $this->assertSame(['Premium Rhizome', ''], SupplierWebCatalogReader::sizeOf('Premium Rhizome'));
    }

    public function testSkipsNonPlantsByTypeAndByTitleWhenTypeIsBlank(): void
    {
        $c = SupplierWebCatalogReader::cells($this->products());
        $names = array_column(array_slice($c, 1, null, true), 'B');
        $this->assertContains('Nemo Tulip', $names);
        $this->assertContains('Yellow Cocotte Lily', $names, 'blank type but a plant name stays');
        $this->assertNotContains('Winter Wonderland Dishtowel Set', $names, 'blank type + non-plant title is skipped');
        $this->assertNotContains('French Lavender Reed Diffuser', $names, 'Gifts type');
        $this->assertNotContains('Antique Décor Planter', $names, 'D&eacute;cor is decoded before comparing');
        $this->assertNotContains('Priced wrong', $names, 'a variant with no numeric price is dropped');
    }

    public function testCellsReadBackThroughTheParser(): void
    {
        $spec = SupplierWebCatalogReader::spec('https://shop.example.com', 7);
        $p = SupplierListParser::parse(SupplierWebCatalogReader::cells($this->products(), $spec['web']), $spec);
        $this->assertSame(4, count($p['rows']));
        $rows = [];
        foreach ($p['rows'] as $r) $rows[$r['product_code']] = $r;
        $this->assertSame([19.99, 0, '25 Bulbs', 'Ships in Fall'], [$rows['16987']['price'], $rows['16987']['available'], $rows['16987']['size'], $rows['16987']['comment']], 'out of season = sold out');
        $this->assertNull($rows['16988']['available'], 'in stock = stock not listed (Shopify gives no count)');
        $this->assertSame('sweet-phlox', array_values(array_filter($rows, fn($r) => $r['name'] === 'Sweet Summer Fantasy Phlox'))[0]['product_code'], 'no sku: the handle is the code');
        $this->assertSame(['Order online: shipping extra, ships in season'], $p['rules']);
        $this->assertSame('availability', $p['kind']);
    }

    public function testFetchAllPagesAndStops(): void
    {
        $seen = [];
        $full = array_fill(0, SupplierWebCatalogReader::PAGE_SIZE, ['title' => 'A', 'variants' => []]);
        $slept = 0;
        $r = SupplierWebCatalogReader::fetchAll('https://shop.example.com/x', $this->fakeHttp([1 => ['products' => $full], 2 => ['products' => [['title' => 'B', 'variants' => []]]]], $seen), function (int $us) use (&$slept) { $slept += $us; });
        $this->assertSame([SupplierWebCatalogReader::PAGE_SIZE + 1, 2], [count($r['products']), $r['pages']]);
        $this->assertSame('https://shop.example.com/products.json?limit=250&page=1', $seen[0]);
        $this->assertSame(SupplierWebCatalogReader::PAUSE_US, $slept, 'a pause before page 2 only');
    }

    public function testRefusals(): void
    {
        foreach ([429, 403] as $code) {
            try { SupplierWebCatalogReader::fetchAll('https://shop.example.com', $this->fakeHttp([1 => $code]), fn() => null); $this->fail("read through {$code}"); }
            catch (RuntimeException $e) { $this->assertStringContainsString((string)$code, $e->getMessage()); }
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shopify');
        SupplierWebCatalogReader::fetchAll('https://shop.example.com', $this->fakeHttp([1 => '<html>not json</html>']), fn() => null);
    }

    public function testAddShopImportsHeldForReviewThenRefreshesWeekly(): void
    {
        $db = PriceListFixtures::sqlite();
        $svc = new SupplierPriceListService($db);
        $http = $this->fakeHttp([1 => ['products' => $this->products()]]);
        $public = fn() => '93.184.216.34';

        $r = $svc->addWebSupplier("Breck's Bulbs", 'https://www.brecksbulbs.ca', $http, fn() => null, $public);
        $this->assertSame('imported', $r['import']['status']);
        $this->assertSame(4, $r['import']['rows']);
        $sup = $db->query("SELECT name, domain, status, list_kind FROM supplier_accounts WHERE id = {$r['supplier_id']}")->fetch();
        $this->assertSame(["Breck's Bulbs", 'brecksbulbs.ca', 'open', 'web'], array_values($sup));
        $list = $svc->list((int)$r['import']['list_id']);
        $this->assertSame(1, (int)$list['needs_review'], 'the first reading waits for "looks right"');
        $this->assertSame('availability', $list['kind']);
        $this->assertSame(['Order online: shipping extra, ships in season'], $list['rules']);

        $again = $svc->addWebSupplier("Breck's Bulbs", 'https://brecksbulbs.ca', $http, fn() => null, $public);
        $this->assertSame($r['supplier_id'], $again['supplier_id'], 'one supplier per web domain, www or not');
        $this->assertSame('duplicate', $again['import']['status'], 'the same day is not imported twice');

        $this->assertSame(0, $svc->refreshWebDue($http, fn() => null)['shops'], 'fresh: nothing to do');
        $db->exec("UPDATE supplier_price_lists SET list_date = '" . date('Y-m-d', strtotime('-8 days')) . "'");
        $w = $svc->refreshWebDue($http, fn() => null);
        $this->assertSame([1, 1, 0], [$w['shops'], $w['imported'], $w['failed']]);
        $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM supplier_price_lists")->fetchColumn());
    }

    public function testAFailingShopIsRecordedNotSilent(): void
    {
        $db = PriceListFixtures::sqlite();
        $svc = new SupplierPriceListService($db);
        $svc->addWebSupplier('Shop', 'https://shop.example.com', $this->fakeHttp([1 => ['products' => $this->products()]]), fn() => null, fn() => '93.184.216.34');
        $db->exec("UPDATE supplier_price_lists SET list_date = '2020-01-01'");
        $w = $svc->refreshWebDue($this->fakeHttp([1 => 429]), fn() => null);
        $this->assertSame(1, $w['failed']);
        $skips = $svc->openSkips();
        $this->assertCount(1, $skips);
        $this->assertStringContainsString('429', $skips[0]['reason']);
        $again = $svc->refreshWebDue($this->fakeHttp([1 => 429]), fn() => null);
        $this->assertSame(0, $again['shops'], 'refused today: not asked again until tomorrow');
    }

    public function testRefusesAPrivateAddressAndNonPublicNames(): void
    {
        $svc = new SupplierPriceListService(PriceListFixtures::sqlite());
        $this->expectException(InvalidArgumentException::class);
        $svc->addWebSupplier('Evil', 'https://shop.example.com', $this->fakeHttp([]), fn() => null, fn() => '10.0.0.5');
    }
}
