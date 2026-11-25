<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Importing supplier lists into this company's tables and linking lines to the shared catalogue
 * (migration 1331), on SQLite with synthetic lists (fake plants, fake prices).
 */
final class SupplierPriceListServiceTest extends TestCase
{
    private function import(SupplierPriceListService $svc, array $prices, int $serial, int $supplier = 8, string $file = 'Wholesale_Test_Availability.xlsx'): array
    {
        return $svc->import(PriceListFixtures::xlsx(PriceListFixtures::availabilityRows($prices, $serial)),
                            ['supplier_account_id' => $supplier, 'format' => PriceListFixtures::inlineFormat(), 'file_name' => $file]);
    }

    public function testImportIsIdempotentAndDiffsAgainstLastWeek(): void
    {
        $db = PriceListFixtures::sqlite();
        $svc = new SupplierPriceListService($db);
        $this->assertTrue($svc->ready());

        $w1 = $this->import($svc, [1001 => 8.95, 1002 => 12.00, 1003 => 21.50, 1004 => 99.99], 46300);
        $this->assertSame(['imported', 4, '2026-10-05'], [$w1['status'], $w1['rows'], $w1['list_date']]);
        $this->assertSame('duplicate', $this->import($svc, [1001 => 1.00], 46300)['status'], 'same supplier+kind+date imports once');
        $this->assertSame(4, (int)$db->query("SELECT COUNT(*) FROM supplier_price_items")->fetchColumn());
        $this->assertTrue($svc->diff($w1['list_id'])['first']);

        $w2 = $this->import($svc, [1001 => 9.25, 1002 => 11.50, 1003 => 21.50, 1005 => 7.00], 46307);
        $d = $svc->diff($w2['list_id']);
        $this->assertSame([1, 1, 1, 1], [$d['new'], $d['up'], $d['down'], $d['same']]);
        $this->assertCount(1, $d['gone']);
        $this->assertSame('1004', (string)$d['gone'][0]['product_code']);
        $up = $db->query("SELECT prev_price, price, price_change, name, size FROM supplier_price_items WHERE list_id = {$w2['list_id']} AND product_code = '1001'")->fetch();
        $this->assertSame([8.95, 9.25, 'up', "Fakeia testii 'Blue Moon'", '11cm Round'], [(float)$up['prev_price'], (float)$up['price'], $up['price_change'], $up['name'], $up['size']],
                          'the supplier\'s own name and size are kept as given');

        $items = $svc->items($w2['list_id'], ['changes' => 1]);
        $this->assertCount(3, $items);
        $this->assertCount(1, $svc->items($w2['list_id'], ['q' => 'red sun']));
        $this->assertCount(1, $svc->latestLists(), 'one supplier, one kind');
        $this->assertSame('2026-10-12', $svc->latestLists()[0]['list_date']);
        $this->assertSame(['Order Minimum $500.00', '11cm Round - 10/tray'], $svc->latestLists()[0]['rules']);
    }

    public function testCatalogueSeededOncePerPlantWithSizesZoneAndCategory(): void
    {
        $db = PriceListFixtures::sqlite();
        $svc = new SupplierPriceListService($db);
        $this->import($svc, [1001 => 8.95, 1002 => 12.00, 1003 => 21.50, 1004 => 99.99, 1005 => 7.00], 46307);
        // Blue Moon in two sizes = one catalogue product.
        $this->assertSame(4, (int)$db->query("SELECT COUNT(*) FROM catalog_products")->fetchColumn());
        $rows = $db->query("SELECT product_code, catalog_product_id, size_code, height, match_how FROM supplier_price_items ORDER BY product_code")->fetchAll();
        $this->assertSame($rows[0]['catalog_product_id'], $rows[1]['catalog_product_id']);
        $this->assertSame(['11cm', 'no1', 'no2', 'no10', 'qt'], array_column($rows, 'size_code'));
        $this->assertSame('5-6ft', $rows[3]['height']);
        $pid = (int)$rows[0]['catalog_product_id'];
        $attrs = $svc->catalog()->attributes($pid);
        $this->assertSame(['fakeia', 'testii', 'blue moon', '4', '4'], [$attrs['genus'], $attrs['species'], $attrs['cultivar'], $attrs['zone'], $attrs['hardiness_zone_min']]);
        $cherry = $db->query("SELECT p.common_names, c.name FROM catalog_products p JOIN catalog_categories c ON c.id = p.category_id WHERE p.group_key = 'samplea'")->fetch();
        $this->assertSame(['sample cherry', 'Deciduous Trees & STDs'], [$cherry['common_names'], $cherry['name']], 'unknown supplier category becomes a learned category');
        $this->assertSame(3, (int)$db->query("SELECT category_id FROM catalog_category_map WHERE text_key = 'perennials'")->fetchColumn(), 'seeded mapping used');
        $this->assertNull($db->query("SELECT use_kind FROM supplier_price_items LIMIT 1")->fetchColumn(), 'plants family has no default use');
    }

    public function testMatchingTiersAndReviewQueue(): void
    {
        $db = PriceListFixtures::sqlite();
        $cat = new CatalogService($db);
        $first = $cat->resolve('plants', ['name' => "Mockus Starry® 'Vivid Dawn'"]);
        $this->assertSame('new', $first['how']);

        $exact = $cat->resolve('plants', ['name' => 'MOCKUS STARRY™ “Vivid Dawn” (PW)']);
        $this->assertSame(['exact', 1.0, $first['product_id']], [$exact['how'], $exact['confidence'], $exact['product_id']]);

        $alt = $cat->resolve('plants', ['name' => "Mockus 'Vivid Dawn'"]);
        $this->assertSame(['alt', 0.9, $first['product_id']], [$alt['how'], $alt['confidence'], $alt['product_id']], 'series left out: auto, high');

        $fuzzy = $cat->resolve('plants', ['name' => "Mockus 'Vivid Dawne'"]);
        $this->assertNull($fuzzy['product_id'], 'fuzzy is never linked on its own');
        $this->assertSame($first['product_id'], $fuzzy['suggested_id']);
        $q = $cat->queue();
        $this->assertCount(1, $q);

        // A person confirms → the spelling is learned (shared) and the next list matches by itself.
        $db->exec("INSERT INTO supplier_price_items (site_id, list_id, name, name_key, match_how) VALUES (1, 1, 'Mockus ''Vivid Dawne''', " . $db->quote($fuzzy['name_key']) . ", 'suggested')");
        $this->assertSame($first['product_id'], $cat->decide((int)$q[0]['id'], 'confirm', 7));
        $this->assertSame($first['product_id'], (int)$db->query("SELECT catalog_product_id FROM supplier_price_items")->fetchColumn());
        $other = new CatalogService($db, 2);   // another tenant
        $again = $other->resolve('plants', ['name' => "Mockus 'Vivid Dawne'"]);
        $this->assertSame(['alias', $first['product_id']], [$again['how'], $again['product_id']]);
        $this->assertCount(0, $other->queue(), 'the queue is per company');

        // Separate: a look-alike that is really a different plant.
        $near = $cat->resolve('plants', ['name' => "Mockus 'Vivid Dusk'"]);
        if ($near['product_id'] === null) {
            $qid = (int)$cat->queue()[0]['id'];
            $new = $cat->decide($qid, 'separate', 7);
            $this->assertNotSame($first['product_id'], $new);
        } else {
            $this->assertSame('new', $near['how']);
        }

        // Merge two catalogue entries.
        $a = $cat->resolve('plants', ['name' => "Testus alpha 'One'"])['product_id'];
        $b = $cat->resolve('plants', ['name' => "Testus beta 'Two'"])['product_id'];
        $cat->mergeProducts($a, $b);
        $this->assertNull($cat->product($a));
        $fresh = new CatalogService($db);
        $m = $fresh->resolve('plants', ['name' => "Testus alpha 'One'"]);
        $this->assertSame(['alias', $b], [$m['how'], $m['product_id']], 'the merged entry\'s spelling now finds the survivor');
    }

    public function testPartNumberFamilyMatchesOnCodes(): void
    {
        $db = PriceListFixtures::sqlite();
        $cat = new CatalogService($db);
        $a = $cat->resolve('plumbing', ['name' => 'PVC Elbow 90 1/2in', 'brand' => 'Acme', 'mfr_part_no' => 'EL-90-050']);
        $this->assertSame('new', $a['how']);
        $b = $cat->resolve('plumbing', ['name' => 'Elbow, 90deg, half inch, PVC', 'mfr_part_no' => 'el90050']);   // no brand on this list
        $this->assertSame(['mpn', $a['product_id']], [$b['how'], $b['product_id']]);
        $c = $cat->resolve('retail_goods', ['name' => 'Argan shampoo 300 ml', 'upc' => '036000291452']);
        $d = $cat->resolve('retail_goods', ['name' => 'ARGAN SHAMPOO 300ML', 'brand' => 'X', 'upc' => '036000291452']);
        $this->assertSame(['exact', $c['product_id']], [$d['how'], $d['product_id']], 'same barcode = same product, whatever the name');
        $this->assertSame('1/2in', $cat->size('plumbing', '1/2"')['code']);
        $this->assertSame('no1', $cat->size('plants', '1 Gallon')['code']);
        $this->assertSame('qt', $cat->size('plants', '1 qt')['code'], 'equivalence table');
    }

    public function testCompareAcrossSuppliers(): void
    {
        $db = PriceListFixtures::sqlite();
        $svc = new SupplierPriceListService($db);
        $this->import($svc, [1001 => 8.95, 1002 => 12.00], 46300);
        $this->import($svc, [1001 => 9.25, 1002 => 12.50], 46307);
        // A second supplier writes the same plant differently.
        $csvSpec = ['header_anchor' => 'Botanical Name', 'columns' => ['name' => ['Botanical Name'], 'size' => ['Pot'], 'price' => ['Net']],
                    'kinds' => [['kind' => 'availability', 'file' => 'stock']]];
        $fmt = ['id' => 2, 'family' => 'plants', 'spec_json' => json_encode($csvSpec)];
        $svc->import("Botanical Name,Pot,Net\nFakeia testii Blue Moon,1 gal,11.75\n", ['supplier_account_id' => 9, 'format' => $fmt, 'file_name' => 'stock_2026_10_10.csv']);
        $pid = (int)$db->query("SELECT catalog_product_id FROM supplier_price_items WHERE product_code = '1001' LIMIT 1")->fetchColumn();
        $rows = $svc->compare($pid, 'no1');
        $this->assertSame([['Other Growers', 11.75], ['Test Nursery', 12.5]], array_map(fn($r) => [$r['supplier_name'], (float)$r['price']], $rows),
                          'newest list of each supplier only, cheapest first');
        $this->assertCount(3, $svc->compare($pid));
    }

    public function testSpecialsJoinOntoAvailability(): void
    {
        $db = PriceListFixtures::sqlite();
        $svc = new SupplierPriceListService($db);
        $av = $this->import($svc, [1001 => 8.95, 1002 => 12.00], 46307);
        $rows = [3 => ['B' => '2026 October Specials'],
                 14 => ['A' => 'ProductID', 'B' => 'Plant', 'C' => 'Size', 'F' => 'List Price', 'G' => 'October', 'H' => 'Available', 'J' => 'Comments'],
                 15 => ['A' => 1001, 'B' => "Fakeia testii 'Blue Moon'", 'C' => '11cm Round', 'F' => 8.95, 'G' => 6.5, 'H' => 10, 'J' => 'Minimum 10']];
        $sp = $svc->import(PriceListFixtures::xlsx($rows), ['supplier_account_id' => 8, 'format' => PriceListFixtures::inlineFormat(),
                           'file_name' => 'Test_Specials_List.xlsx', 'body' => 'valid on all orders shipping by October 30th']);
        $this->assertSame(['specials', '2026-10-01'], [$sp['kind'], $sp['list_date']]);
        $this->assertSame('2026-10-30', $svc->list($sp['list_id'])['valid_until']);
        $cur = $svc->currentSpecials(8, '2026-10-15');
        $this->assertSame(6.5, $cur['items'][SupplierPriceListService::lineKey(['product_code' => '1001', 'size' => '11cm Round'])]['price']);
        $this->assertSame([], $svc->currentSpecials(8, '2026-11-02')['items'], 'expired specials drop off');
    }

    public function testEmailLinksAllowListAndFetch(): void
    {
        $f = PriceListFixtures::inlineFormat();
        $body = "Weekly availability: https://mcusercontent.com/abc/files/u1/Wholesale_Test_Availability_2026_10_12.xlsx\n"
              . "<a href=\"https://mcusercontent.com/abc/files/u2/Test_Specials_List.xlsx\">Specials</a>\n"
              . "Booking: https://mcusercontent.com/abc/files/u3/Booking_Form_2027.xlsx\n"
              . "Evil: https://mcusercontent.com.evil.example/x/Availability.xlsx http://mcusercontent.com/abc/files/u4/Availability.xlsx\n"
              . "Image: https://mcusercontent.com/abc/images/logo.png";
        $links = SupplierPriceListService::listLinks($body, $f);
        $this->assertSame(['Wholesale_Test_Availability_2026_10_12.xlsx', 'Test_Specials_List.xlsx'], array_column($links, 'file_name'));
        $this->assertTrue(SupplierPriceListService::urlAllowed('https://mcusercontent.com/a.xlsx', ['mcusercontent.com']));
        $this->assertFalse(SupplierPriceListService::urlAllowed('https://user:pw@mcusercontent.com/a.xlsx', ['mcusercontent.com']));
        $this->assertFalse(SupplierPriceListService::urlAllowed('https://mcusercontent.com:8443/a.xlsx', ['mcusercontent.com']));
        $this->assertFalse(SupplierPriceListService::urlAllowed('https://notmcusercontent.com/a.xlsx', ['mcusercontent.com']));

        $db = PriceListFixtures::sqlite();
        $svc = new SupplierPriceListService($db);
        $calls = 0;
        $fetch = function (string $url) use (&$calls) {
            $calls++;
            if (strpos($url, 'Specials') !== false) throw new RuntimeException('HTTP 404');
            return PriceListFixtures::xlsx(PriceListFixtures::availabilityRows([1001 => 8.95], 46307));
        };
        $r = $svc->importFromEmail(['id' => 8, 'name' => 'Test Nursery'], $f, $body, ['message_key' => 'm1@x'], $fetch);
        $this->assertSame([1, 0, 1], [$r['imported'], $r['duplicate'], $r['failed']], 'a failed download is counted, not thrown');
        $r2 = $svc->importFromEmail(['id' => 8, 'name' => 'Test Nursery'], $f, $body, ['message_key' => 'm1@x'], $fetch);
        $this->assertSame(1, $r2['duplicate']);
        $this->assertSame(3, $calls, 'the same email\'s link is not downloaded twice');
    }

    public function testFormatForDomain(): void
    {
        $db = PriceListFixtures::sqlite();
        $f = PriceListFixtures::inlineFormat();
        $db->prepare("INSERT INTO supplier_list_formats (code, name, family, sender_domains, link_hosts, arrives, spec_json) VALUES (?, 'x', ?, ?, ?, ?, ?)")
           ->execute([$f['code'], $f['family'], $f['sender_domains'], $f['link_hosts'], $f['arrives'], $f['spec_json']]);
        $svc = new SupplierPriceListService($db);
        $this->assertSame('inline-nurseries', $svc->formatForDomain('inlinenurseries.com')['code']);
        $this->assertSame('inline-nurseries', $svc->formatForDomain('mail.inlinenurseries.com')['code']);
        $this->assertNull($svc->formatForDomain('notinlinenurseries.com'));
    }
}
