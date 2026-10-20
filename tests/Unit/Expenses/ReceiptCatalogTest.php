<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Receipt lines matched to the shared catalogue by themselves (ReceiptCatalogService, migration 1378,
 * 2026-10-19). Line shapes are the real ones on production receipts (Lawnboy, COE Lumber, Hunters,
 * Canadian Tire); products are made up in the shapes the supplier lists give.
 */
class ReceiptCatalogTest extends TestCase
{
    private static function db(): PDO
    {
        $db = PriceListFixtures::sqlite();
        $db->exec("INSERT INTO catalog_families (slug, name, matcher, default_use_kind, sort_order) VALUES
                   ('hardscape','Bulk','name','consumable',2), ('tools','Tools','part_number','equipment',6), ('irrigation','Irrigation','part_number','consumable',3),
                   ('chemicals','Chemicals','part_number','consumable',7), ('other','Other','name',NULL,99)");
        $db->exec("CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, expense_date TEXT, vendor_id INT, vendor_name_raw TEXT, accounting_category TEXT, status TEXT)");
        $db->exec("CREATE TABLE expense_line_items (id INTEGER PRIMARY KEY, expense_id INT, name TEXT, sku_raw TEXT, line_total REAL,
                   catalog_product_id INT, catalog_match TEXT, catalog_suggested_id INT, catalog_score REAL)");
        $db->exec("INSERT INTO vendors VALUES (1, 'LAWNBOY'), (2, 'COE LUMBER & BUILDING SUPPLY'), (3, 'Chevron')");
        $svc = new CatalogService($db);
        foreach (['Compost Bark Mulch', 'Mushroom Manure', 'Turf Blend', 'Garden Soil', '19mm Minus (3/4") Road Base', 'Premium Garden and Veggie Blend 2P'] as $n) {
            $svc->resolve('hardscape', ['name' => $n], null, false);
        }
        $db->exec("INSERT INTO expenses VALUES (10, '2026-05-01', 1, NULL, 'Materials', 'approved'), (11, '2026-06-01', 2, NULL, 'Tools/Equipment', 'approved'),
                   (12, '2026-06-02', 3, NULL, 'Fuel', 'approved'), (13, '2026-07-01', 1, NULL, 'Materials', 'draft')");
        $db->exec("INSERT INTO expense_line_items (id, expense_id, name, sku_raw, line_total) VALUES
            (1, 10, 'Black Composted Bark Mulch', NULL, 40), (2, 10, '1 M Manure', NULL, 38), (3, 10, 'Turf Blend', NULL, 38), (4, 10, '2m CBM', NULL, 80),
            (5, 10, 'Road Base', NULL, 35), (6, 11, 'Corona Leaf Rake', 'RK 62001', 55.98), (7, 11, 'EHF', '298-7134-2', 8),
            (8, 12, 'Diesel', NULL, 90), (9, 13, '2 m CBM', NULL, 80)");
        return $db;
    }

    public function testCleaningAndWords(): void
    {
        $this->assertSame(['Manure', null], ReceiptCatalogService::clean('1 M Manure'));
        $this->assertSame(['CBM', null], ReceiptCatalogService::clean('2m CBM'));
        $this->assertSame(['DT Blend', null], ReceiptCatalogService::clean('1 yd DT Blend'));
        $this->assertSame(['EverGro Rhodo and Azalea Fertilizer', 'EVV-33225'], ReceiptCatalogService::clean('EVV-33225 EverGro Rhodo and Azalea Fertilizer (2 @ $14.99)'));
        $this->assertSame('24 X 16 TURFSTONE MUTUAL/ 35 PCS PER PALLET', ReceiptCatalogService::clean('24 X 16 TURFSTONE MUTUAL/ 35 PCS PER PALLET (20 EA @ 11.06)')[0], 'a size is not a quantity');
        $this->assertSame(['compost', 'bark', 'mulch'], ReceiptCatalogService::tokens('Composted Bark Mulch'));
        $this->assertSame(['hd', 'epdm', 'tarp', 'strap', '20in'], ReceiptCatalogService::tokens('HD EPDM TARP STRAP 20"'), 'a size is a word');
        $this->assertNotSame(ReceiptCatalogService::tokens('Mulch 2 cu ft'), ReceiptCatalogService::tokens('Mulch 1.5 cu ft'));
        $this->assertSame(['lattice', '4x8'], ReceiptCatalogService::tokens('Lattice 4*8'));
        $this->assertNull(ReceiptCatalogService::barcode('40-2574-6'), 'a store SKU is not a barcode');
        $this->assertSame('036000291452', ReceiptCatalogService::barcode('036000291452'));
        $this->assertFalse(ReceiptCatalogService::isProduct('EHF'));
        $this->assertTrue(ReceiptCatalogService::isProduct('Corona Leaf Rake'));
        foreach (['Sub Total', 'Subotal', '$', 'Savings: $22.49', 'Value', 'Labour', 'Discount: Landscapers (25%)', 'Tire Disposal Fee', '** Purchase **',
                  'Annuals 6 Pack', 'Perennial × 18', 'Herbs/Veggies (Veggie 4" Fibre Pot)', 'Bulbs x 12 ($19.99 each, 25% landscaper discount)', 'rock'] as $x) {
            $this->assertFalse(ReceiptCatalogService::isProduct($x), $x);
        }
        foreach (['Southlands Potting Soil', 'Topsoil 30L Bag', 'Mower Blades', 'Moss Control', 'Road Base', 'Turf Blend'] as $x) {
            $this->assertTrue(ReceiptCatalogService::isProduct($x), $x);
        }
        $this->assertSame('hardscape', ReceiptCatalogService::guessFamily('Materials', 'Road Base'));
        $this->assertSame('irrigation', ReceiptCatalogService::guessFamily('Materials', 'Orbit Faucet Timer 2-Outlet'));
        $this->assertSame('chemicals', ReceiptCatalogService::guessFamily('Materials', 'Moss Control'));
        $this->assertSame('tools', ReceiptCatalogService::guessFamily('Tools/Equipment', 'Corona 13" Pruning Saw'));
        $this->assertSame(['sku' => 'rcpt:lawnboy:sku:RK62001', 'n' => 'rcpt:lawnboy:n:corona leaf rake'],
                          ReceiptCatalogService::aliasKeys('lawnboy', 'RK 62001', 'Corona Leaf Rake'));
    }

    public function testPickNeedsOneClearWinner(): void
    {
        $this->assertSame([7, 1.0, 'exact'], ReceiptCatalogService::pick([['id' => 7, 'score' => 1.0], ['id' => 8, 'score' => 0.5]]));
        $this->assertSame([7, 0.8, 'suggested'], ReceiptCatalogService::pick([['id' => 7, 'score' => 0.8], ['id' => 8, 'score' => 0.6]]));
        $this->assertSame([null, 0.0, null], ReceiptCatalogService::pick([['id' => 7, 'score' => 0.67], ['id' => 8, 'score' => 0.67]]), 'a tie is nobody');
        $this->assertSame([null, 0.0, null], ReceiptCatalogService::pick([['id' => 7, 'score' => 0.4]]));
        $this->assertSame(0.5, ReceiptCatalogService::score(['tarp', 'strap', '20in'], ['tarp', 'strap', '30in']), 'another size: halved');
        $this->assertSame(0.95, ReceiptCatalogService::score(['road', 'base'], ['19mm', 'road', 'base']), 'no size on the receipt: asked, not linked');
        $this->assertSame(1.0, ReceiptCatalogService::score(['tarp', 'strap', '20in'], ['tarp', 'strap', '20in']));
    }

    public function testLinesMatchByThemselvesAndATapTeachesTheNextReceipt(): void
    {
        $db = self::db();
        $svc = new ReceiptCatalogService($db);
        $r = $svc->match(10);
        $got = $db->query("SELECT l.id, l.catalog_match, p.canonical_name, q.canonical_name FROM expense_line_items l
                           LEFT JOIN catalog_products p ON p.id = l.catalog_product_id LEFT JOIN catalog_products q ON q.id = l.catalog_suggested_id
                           WHERE l.expense_id = 10 ORDER BY l.id")->fetchAll(PDO::FETCH_NUM);
        $this->assertSame(['1', 'suggested', null, 'Compost Bark Mulch'], array_map(fn($v) => $v === null ? null : (string)$v, $got[0]), '"Black" is one word more');
        $this->assertSame(['2', 'suggested', null, 'Mushroom Manure'], array_map(fn($v) => $v === null ? null : (string)$v, $got[1]));
        $this->assertSame(['3', 'exact', 'Turf Blend', null], array_map(fn($v) => $v === null ? null : (string)$v, $got[2]));
        $this->assertSame(['4', 'open', null, null], array_map(fn($v) => $v === null ? null : (string)$v, $got[3]), 'CBM: nothing alike yet');
        $this->assertSame('suggested', $got[4][1], 'Road Base');
        $this->assertSame(['linked' => 1, 'suggested' => 3, 'open' => 1], $r);

        // Tim: "2m CBM" is Compost Bark Mulch. Lawnboy's next receipt says "2 m CBM" → linked by itself.
        $cbm = (int)$db->query("SELECT id FROM catalog_products WHERE canonical_name = 'Compost Bark Mulch'")->fetchColumn();
        $this->assertTrue($svc->confirm(4, $cbm, 5)['ok']);
        $this->assertSame(['alias', $cbm], (function () use ($db) { $x = $db->query("SELECT catalog_match, catalog_product_id FROM expense_line_items WHERE id = 9")->fetch(PDO::FETCH_NUM); return [$x[0], (int)$x[1]]; })(),
                          'the same vendor\'s other receipt, by the learned wording');
        $this->assertSame('confirmed', $db->query("SELECT catalog_match FROM expense_line_items WHERE id = 4")->fetchColumn());
        // A person's decision is never undone by the matcher.
        $svc->match(10);
        $this->assertSame('confirmed', $db->query("SELECT catalog_match FROM expense_line_items WHERE id = 4")->fetchColumn());
    }

    public function testAddToCatalogueOnlyByATapAndBySku(): void
    {
        $db = self::db();
        $svc = new ReceiptCatalogService($db);
        $before = (int)$db->query("SELECT COUNT(*) FROM catalog_products")->fetchColumn();
        $this->assertSame(['linked' => 0, 'suggested' => 0, 'open' => 1], $svc->match(11), 'EHF is a fee, not a product');
        $this->assertSame($before, (int)$db->query("SELECT COUNT(*) FROM catalog_products")->fetchColumn(), 'never grows by itself');
        $this->assertFalse($svc->add(6, 'made-up', 'Corona Leaf Rake')['ok']);
        $r = $svc->add(6, 'tools', 'Corona Leaf Rake', 'Corona', 5);
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['new']);
        $this->assertSame('added', $db->query("SELECT catalog_match FROM expense_line_items WHERE id = 6")->fetchColumn());
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM catalog_aliases WHERE alias_key LIKE 'rcpt:%:sku:RK62001'")->fetchColumn(), 'the SKU is remembered for that vendor');
        // The same SKU on the vendor's next receipt, worded differently, links by the SKU.
        $db->exec("INSERT INTO expenses VALUES (14, '2026-08-01', 2, NULL, 'Tools/Equipment', 'approved')");
        $db->exec("INSERT INTO expense_line_items (id, expense_id, name, sku_raw, line_total) VALUES (20, 14, 'CORONA RAKE LEAF 24T', 'RK62001', 59.99)");
        $svc->match(14);
        $this->assertSame([(int)$r['product']['id'], 'alias'], array_map(fn($v) => is_numeric($v) ? (int)$v : $v, $db->query("SELECT catalog_product_id, catalog_match FROM expense_line_items WHERE id = 20")->fetch(PDO::FETCH_NUM)));
        $this->assertSame(['linked' => 0, 'suggested' => 0, 'open' => 0], $svc->match(12), 'fuel receipts are not catalogue work');
        $this->assertSame('Corona Leaf Rake', $svc->search('leaf rake')[0]['name']);
    }

    public function testNoIsRememberedAndACorrectionMovesTheKey(): void
    {
        $db = self::db();
        $svc = new ReceiptCatalogService($db);
        $svc->match(10);
        $svc->dismiss(2, 5);                                                   // "1 M Manure" is not Mushroom Manure
        $db->exec("UPDATE expense_line_items SET catalog_match = NULL WHERE id = 2");   // as if the line was saved again
        $svc->match(10);
        $this->assertSame('open', $db->query("SELECT catalog_match FROM expense_line_items WHERE id = 2")->fetchColumn(), 'never suggested again for that wording');

        $soil = (int)$db->query("SELECT id FROM catalog_products WHERE canonical_name = 'Garden Soil'")->fetchColumn();
        $svc->add(4, 'other', 'Composted Bark Mulch (other)', null, 5);   // first: a wrong family
        $svc->confirm(4, $soil, 5);                                         // then corrected
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM catalog_aliases WHERE alias_key = 'rcpt:lawnboy:n:cbm'")->fetchColumn(), 'one product per spelling');
        $this->assertSame($soil, (int)$db->query("SELECT product_id FROM catalog_aliases WHERE alias_key = 'rcpt:lawnboy:n:cbm'")->fetchColumn());
    }

    public function testSweepMovesOn(): void
    {
        $db = self::db();
        $svc = new ReceiptCatalogService($db);
        $first = $svc->sweep('2026-01-01');
        $this->assertSame(3, $first['receipts'], 'Materials + Tools receipts (not fuel)');
        $this->assertSame(0, $svc->sweep('2026-01-01')['receipts'], 'every line looked at: nothing left');
        $this->assertSame('skip', $db->query("SELECT catalog_match FROM expense_line_items WHERE id = 7")->fetchColumn(), 'EHF');
    }
}
