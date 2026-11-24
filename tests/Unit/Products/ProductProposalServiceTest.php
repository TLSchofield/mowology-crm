<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ProductsTestDb.php';
require_once __DIR__ . '/LabelReaderServiceTest.php';

/**
 * Label photos and receipt lines → proposals on Penny's / Otto's cards. Nothing is created
 * until accept(); "Not now" is remembered; each receipt line is proposed once.
 * Fixtures are Tim's real 2026-10-07 seed bag, Lawnboy receipt #412 and product #44 / #11.
 */
class ProductProposalServiceTest extends TestCase
{
    private function capture(PDO $db, string $text, int $mediaId = 900, string $at = '2026-10-07 16:40:00'): int
    {
        $db->prepare("INSERT INTO label_captures (media_id, captured_by, captured_at, ocr_text, ocr_source, parsed_json, status) VALUES (?, 1, ?, ?, 'vision', ?, 'pending')")
           ->execute([$mediaId, $at, $text, json_encode(LabelReaderService::read($text))]);
        return (int)$db->lastInsertId();
    }

    private function svc(PDO $db): ProductProposalService
    {
        return new ProductProposalService($db, ProductsTestDb::linker($db), '2026-10-07');
    }

    // ── Pure rules ──────────────────────────────────────────────────────────

    public function test_fuel_meals_fees_taxes_deposits_delivery_are_never_products(): void
    {
        foreach (['REGULAR UNLEADED 45.2L', 'Diesel', 'Coffee', 'Lunch - crew', 'ENVIRO FEE', 'GST', 'Bottle deposit', 'Delivery charge', 'Freight'] as $n) {
            $this->assertTrue(ProductProposalService::excluded($n), $n);
        }
        foreach (['CBM', '2 B. 5K Seed', 'SCOTTS TURF BUILDER 5KG', 'Black Mulch'] as $n) {
            $this->assertFalse(ProductProposalService::excluded($n), $n);
        }
    }

    public function test_match_score_sku_and_size_plus_word(): void
    {
        $seed = ['name' => 'Richardson Sun & Shade Lawn Seed 5 kg', 'sku' => 'TL02100350'];
        $cbm = ['name' => 'Black Composted Bark Mulch', 'sku' => 'CBM'];
        $this->assertSame(100.0, ProductProposalService::matchScore('CBM 1 yd', null, $cbm));
        $this->assertSame(100.0, ProductProposalService::matchScore('anything', 'TL02100350', $seed));
        $this->assertGreaterThan(0, ProductProposalService::matchScore('2 B. 5K Seed', null, $seed));
        $this->assertSame(0.0, ProductProposalService::matchScore('2 B. 10K Seed', null, $seed), 'a different size vetoes');
        $this->assertSame(0.0, ProductProposalService::matchScore('Seed', null, ['name' => 'OVER-SEED', 'sku' => null]), 'one shared word alone is not enough');
    }

    public function test_card_sentence_for_a_new_product_from_a_label(): void
    {
        $say = ProductProposalService::say([
            'kind' => 'product_new',
            'payload' => ['name' => 'Richardson Sun & Shade Lawn Seed 5 kg', 'unit_cost' => 60.0, 'unit' => 'bag', 'vendor_name' => 'LAWNBOY',
                          'track_inventory' => 1, 'photo_url' => '/crm/api/serve-receipt.php?id=900', 'sku' => 'TL02100350'],
            'lines' => [['expense_id' => 412, 'line_item_id' => 229, 'quantity' => 2]],
        ]);
        $this->assertSame('New product: Richardson Sun & Shade Lawn Seed 5 kg · $60/bag from Lawnboy · 2 in stock · photo', $say);
    }

    // ── Label photo → product ───────────────────────────────────────────────

    public function test_seed_label_matching_an_existing_sku_proposes_attaching_the_photo(): void
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db);
        $db->exec("UPDATE expense_line_items SET product_id = 44 WHERE id = 229");   // migration 1224 linked it
        $id = $this->capture($db, LabelReaderServiceTest::SEED);
        $r = $this->svc($db)->proposeFromLabel($id);
        $this->assertTrue($r['existing']);
        $q = $this->svc($db)->queue('penny');
        $this->assertCount(1, $q);
        $this->assertSame('product_match', $q[0]['kind']);
        $this->assertSame('Add this photo to Richardson Sun & Shade Lawn Seed 5 kg', $q[0]['say'], 'same $60 cost → no cost question, line already linked');
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM products WHERE id <> 44 AND sku = 'TL02100350'")->fetchColumn(), 'nothing created');

        $a = $this->svc($db)->accept($q[0]['id'], 1);
        $this->assertTrue($a['ok']);
        $p = $db->query("SELECT * FROM products WHERE id = 44")->fetch();
        $this->assertSame('/crm/api/serve-receipt.php?id=900', $p['image_url']);
        $this->assertSame(900, (int)$p['label_media_id']);
        $this->assertSame(0, (int)$p['photo_marketing_ok'], 'label photos are internal');
        $this->assertSame(0.0, (float)$p['current_stock'], 'already-linked line is not counted twice');
        $c = $db->query("SELECT status, result_type, result_id FROM label_captures WHERE id = {$id}")->fetch();
        $this->assertSame(['status' => 'done', 'result_type' => 'product', 'result_id' => 44], ['status' => $c['status'], 'result_type' => $c['result_type'], 'result_id' => (int)$c['result_id']]);
    }

    public function test_existing_picture_is_kept_and_the_label_photo_saved_beside_it(): void
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db);
        $db->exec("UPDATE products SET image_url = '/uploads/cms/media-seed.jpg', photo_marketing_ok = 1 WHERE id = 44");
        $id = $this->capture($db, LabelReaderServiceTest::SEED);
        $svc = $this->svc($db);
        $svc->proposeFromLabel($id);
        $svc->accept($svc->queue('penny')[0]['id'], 1);
        $p = $db->query("SELECT image_url, label_media_id, photo_marketing_ok FROM products WHERE id = 44")->fetch();
        $this->assertSame('/uploads/cms/media-seed.jpg', $p['image_url']);
        $this->assertSame(1, (int)$p['photo_marketing_ok']);
        $this->assertSame(900, (int)$p['label_media_id']);
    }

    public function test_cost_change_is_offered_and_applied_on_accept(): void
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db, true, 64.0);
        $id = $this->capture($db, LabelReaderServiceTest::SEED);
        $svc = $this->svc($db);
        $svc->proposeFromLabel($id);
        $q = $svc->queue('penny');
        $this->assertCount(1, $q, 'the label proposal took line 229 — no second card for it');
        $this->assertSame('Add this photo to Richardson Sun & Shade Lawn Seed 5 kg · link 1 receipt line (+2 to stock) · cost changed $64→$60 — update?', $q[0]['say']);
        $svc->accept($q[0]['id'], 1);
        $p = $db->query("SELECT base_cost, current_stock FROM products WHERE id = 44")->fetch();
        $this->assertSame(60.0, (float)$p['base_cost']);
        $this->assertSame(2.0, (float)$p['current_stock']);
        $this->assertSame(44, (int)$db->query("SELECT product_id FROM expense_line_items WHERE id = 229")->fetchColumn());
    }

    public function test_cost_update_can_be_declined(): void
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db, true, 64.0);
        $svc = $this->svc($db);
        $svc->proposeFromLabel($this->capture($db, LabelReaderServiceTest::SEED));
        $svc->accept($svc->queue('penny')[0]['id'], 1, ['update_cost' => false]);
        $this->assertSame(64.0, (float)$db->query("SELECT base_cost FROM products WHERE id = 44")->fetchColumn());
    }

    public function test_new_seed_product_from_label_and_same_day_receipt_line(): void
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db, false);
        $svc = $this->svc($db);
        $r = $svc->proposeFromLabel($this->capture($db, LabelReaderServiceTest::SEED));
        $this->assertFalse($r['existing']);
        $this->assertStringContainsString('Lawnboy receipt #412', $r['message']);
        $q = $svc->queue('penny');
        $this->assertCount(1, $q);
        $this->assertSame('New product: Richardson Sun & Shade Lawn Seed 5 kg · $60/bag from Lawnboy · 2 in stock · photo', $q[0]['say']);
        $this->assertSame([412], $q[0]['receipts']);

        $a = $svc->accept($q[0]['id'], 1);
        $this->assertTrue($a['ok'], $a['message']);
        $p = $db->query("SELECT * FROM products WHERE sku = 'TL02100350'")->fetch();
        $this->assertSame('Richardson Sun & Shade Lawn Seed 5 kg', $p['name']);
        $this->assertSame(60.0, (float)$p['base_cost']);
        $this->assertSame(1, (int)$p['track_inventory']);
        $this->assertSame(2.0, (float)$p['current_stock']);
        $this->assertSame('/crm/api/serve-receipt.php?id=900', $p['image_url']);
        $this->assertSame(0, (int)$p['photo_marketing_ok']);
        $this->assertStringContainsString('50% Turf Type Perennial Ryegrass', (string)$p['description']);
        $this->assertStringContainsString('Lawnboy — $60 per bag (2026-10-07)', (string)$p['supplier_info']);
        $this->assertNull($p['care_notes'], 'the bag prints no storage advice, so none is written');
    }

    public function test_label_care_lines_are_kept_on_the_product(): void
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db, false);
        $svc = $this->svc($db);
        $svc->proposeFromLabel($this->capture($db, LabelReaderServiceTest::SEED . "\nStore in a cool, dry place"));
        $svc->accept($svc->queue('penny')[0]['id'], 1);
        $this->assertSame('Store in a cool, dry place.', $db->query("SELECT care_notes FROM products WHERE sku = 'TL02100350'")->fetchColumn());
    }

    public function test_not_now_is_remembered(): void
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db, false);
        $svc = $this->svc($db);
        $id = $this->capture($db, LabelReaderServiceTest::SEED);
        $svc->proposeFromLabel($id);
        $svc->dismiss($svc->queue('penny')[0]['id'], 1);
        $svc->proposeFromLabel($id);
        $this->assertSame([], $svc->queue('penny'));
        $this->assertSame('dismissed', $db->query("SELECT status FROM label_captures WHERE id = {$id}")->fetchColumn());
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM products WHERE sku = 'TL02100350'")->fetchColumn());
    }

    // ── Label photo → machine ───────────────────────────────────────────────

    public function test_new_machine_with_its_purchase_receipt(): void
    {
        $db = ProductsTestDb::make();
        $db->exec("INSERT INTO vendors (id, name) VALUES (5, 'Dueck Power Equipment')");
        $db->exec("INSERT INTO expenses (id, expense_date, vendor_id, vendor_name_raw, created_by) VALUES (500, '2026-10-06', 5, 'Dueck', 1)");
        $db->exec("INSERT INTO expense_line_items (expense_id, name, quantity, unit_price, line_total) VALUES (500, 'EGO LM2135SP MOWER KIT', 1, 649, 649)");
        $svc = $this->svc($db);
        $r = $svc->proposeFromLabel($this->capture($db, LabelReaderServiceTest::EGO));
        $this->assertSame('machine', $r['kind']);
        $q = $svc->queue('otto');
        $this->assertCount(1, $q);
        $this->assertSame([], $svc->queue('penny'));
        $this->assertSame('New machine: EGO LM2135SP lawn mower, S/N NLM2135SP2104000123, bought Oct 6 receipt #500 — Add to equipment', $q[0]['say']);
        $a = $svc->accept($q[0]['id'], 1);
        $this->assertTrue($a['ok'], $a['message']);
        $e = $db->query("SELECT * FROM equipment")->fetch();
        $this->assertSame(['mower', 'EGO', 'LM2135SP', 'NLM2135SP2104000123', 'battery', '2026-10-06', 500],
                          [$e['equipment_class'], $e['make'], $e['model'], $e['serial_no'], $e['power_source'], $e['purchase_date'], (int)$e['expense_id']]);
    }

    public function test_known_machine_by_serial_needs_no_card(): void
    {
        $db = ProductsTestDb::make();
        $db->exec("INSERT INTO equipment (id, name, equipment_class, make, model, serial_no) VALUES (3, 'Nigel''s EGO mower', 'mower', 'EGO', 'LM2135SP', 'NLM2135SP2104000123')");
        $svc = $this->svc($db);
        $id = $this->capture($db, LabelReaderServiceTest::EGO);
        $r = $svc->proposeFromLabel($id);
        $this->assertTrue($r['existing']);
        $this->assertSame([], $svc->queue('otto'));
        $this->assertSame('done', $db->query("SELECT status FROM label_captures WHERE id = {$id}")->fetchColumn());
    }

    public function test_known_model_without_serial_offers_to_fill_it_in(): void
    {
        $db = ProductsTestDb::make();
        $db->exec("INSERT INTO equipment (id, name, equipment_class, make, model, serial_no) VALUES (3, 'EGO mower', 'mower', 'EGO', 'LM2135SP', NULL)");
        $svc = $this->svc($db);
        $svc->proposeFromLabel($this->capture($db, LabelReaderServiceTest::EGO));
        $q = $svc->queue('otto');
        $this->assertSame('This is your EGO mower — add the serial from the label?', $q[0]['say']);
        $svc->accept($q[0]['id'], 1);
        $this->assertSame('NLM2135SP2104000123', $db->query("SELECT serial_no FROM equipment WHERE id = 3")->fetchColumn());
    }

    // ── Receipt lines → proposals (any source) ──────────────────────────────

    public function test_recurring_lawnboy_cbm_lines_link_to_product_11_on_one_card(): void
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db);
        $svc = $this->svc($db);
        $svc->proposeForExpense(412);
        $svc->proposeForExpense(413);
        $cbm = array_values(array_filter($svc->queue('penny'), fn($p) => ($p['payload']['product_id'] ?? 0) === 11));
        $this->assertCount(1, $cbm, 'one card per product, listing the receipts behind it');
        $this->assertSame([412, 413], $cbm[0]['receipts']);
        $this->assertSame('Link 2 receipt lines to Black Composted Bark Mulch (+3 to stock) · cost changed $41→$40 — update?', $cbm[0]['say']);
        $svc->accept($cbm[0]['id'], 1);
        $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM expense_line_items WHERE product_id = 11")->fetchColumn());
        $p = $db->query("SELECT base_cost, current_stock FROM products WHERE id = 11")->fetch();
        $this->assertSame([40.0, 3.0], [(float)$p['base_cost'], (float)$p['current_stock']]);
    }

    public function test_5k_seed_line_links_to_product_44_by_name_and_size(): void
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db);
        $svc = $this->svc($db);
        $svc->proposeForExpense(412);
        $seed = array_values(array_filter($svc->queue('penny'), fn($p) => ($p['payload']['product_id'] ?? 0) === 44));
        $this->assertCount(1, $seed);
        $this->assertSame('Link 1 receipt line to Richardson Sun & Shade Lawn Seed 5 kg (+2 to stock)', $seed[0]['say']);
        $this->assertSame(0, $svc->proposeForExpense(412), 'each line is proposed once');
    }

    public function test_home_depot_e_receipt_with_skus_proposes_new_products_but_not_cheap_one_off_tools(): void
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db);
        $raw = ['text' => '…', 'parsed' => ['line_items' => [
            ['name' => 'SCOTTS TURF BUILDER LAWN FOOD 5KG', 'sku_raw' => '1000123456', 'quantity' => 2, 'unit_price' => 39.98, 'amount' => '79.96'],
            ['name' => 'NITRILE GLOVES 10PK', 'sku_raw' => '1001234567', 'quantity' => 1, 'unit_price' => 9.98, 'amount' => '9.98'],
            ['name' => 'ECO FEE', 'sku_raw' => null, 'quantity' => 1, 'unit_price' => 0.25, 'amount' => '0.25'],
        ]], 'source' => 'email'];
        $db->prepare("INSERT INTO expenses (id, expense_date, vendor_id, vendor_name_raw, raw_ocr_json) VALUES (600, '2026-10-01', 9, 'THE HOME DEPOT', ?)")->execute([json_encode($raw)]);
        $svc = $this->svc($db);
        $this->assertSame(1, $svc->proposeForExpense(600));
        $q = $svc->queue('penny');
        $this->assertCount(1, $q);
        $this->assertSame('product_new', $q[0]['kind']);
        $this->assertSame('New product: Scotts Turf Builder Lawn Food 5kg · $39.98/bag from The Home Depot · SKU 1000123456', $q[0]['say']);
        $a = $svc->accept($q[0]['id'], 1);
        $p = $db->query("SELECT name, sku, base_cost, track_inventory, supplier_info FROM products WHERE sku = '1000123456'")->fetch();
        $this->assertSame(['Scotts Turf Builder Lawn Food 5kg', 39.98, 1], [$p['name'], (float)$p['base_cost'], (int)$p['track_inventory']]);
        $this->assertStringContainsString('The Home Depot — $39.98 per bag', $p['supplier_info']);
        $this->assertStringContainsString('Added', $a['message']);
    }

    public function test_fuel_and_meal_lines_are_ignored(): void
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db);
        $db->exec("INSERT INTO expenses (id, expense_date, vendor_id, vendor_name_raw) VALUES (700, '2026-10-02', 3, 'CHEVRON'), (701, '2026-10-03', 3, 'CHEVRON')");
        $db->exec("INSERT INTO expense_line_items (expense_id, name, quantity, unit_price, line_total, sku_raw) VALUES
                   (700, 'REGULAR UNLEADED', 45.2, 1.79, 80.91, '000123456'), (700, 'COFFEE', 1, 2.25, 2.25, NULL),
                   (701, 'REGULAR UNLEADED', 40, 1.81, 72.40, '000123456'), (701, 'COFFEE', 1, 2.25, 2.25, NULL)");
        $svc = $this->svc($db);
        $svc->proposeForExpense(700);
        $svc->proposeForExpense(701);
        $this->assertSame([], $svc->queue('penny'));
    }

    public function test_a_repeat_purchase_without_sku_becomes_one_new_product_card_and_not_now_sticks(): void
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db);
        $db->exec("INSERT INTO expenses (id, expense_date, vendor_id, vendor_name_raw) VALUES (801, '2026-09-01', 7, 'LAWNBOY'), (802, '2026-09-15', 7, 'LAWNBOY'), (803, '2026-10-01', 7, 'LAWNBOY')");
        $add = fn(int $e, string $n, float $q, float $u) => $db->prepare("INSERT INTO expense_line_items (expense_id, name, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)")
                                                       ->execute([$e, $n, $q, $u, $q * $u]);
        $svc = $this->svc($db);
        $add(801, 'River Rock 1in', 1, 70);
        $this->assertSame(0, $svc->proposeForExpense(801), 'bought once: not yet');
        $add(802, 'RIVER ROCK 1IN', 1, 72);
        $this->assertSame(1, $svc->proposeForExpense(802));
        $q = array_values(array_filter($svc->queue('penny'), fn($p) => $p['kind'] === 'product_new'));
        $this->assertCount(1, $q);
        $this->assertSame([801, 802], $q[0]['receipts']);
        $this->assertSame('New product: River Rock 1in · $72/yd from Lawnboy · 2 in stock · bought on 2 receipts', $q[0]['say']);
        $svc->dismiss($q[0]['id'], 1);
        $add(803, 'River rock 1in', 2, 72);
        $svc->proposeForExpense(803);
        $this->assertSame([], array_values(array_filter($svc->queue('penny'), fn($p) => $p['kind'] === 'product_new')), 'Not now is remembered');
    }

    public function test_backfill_reads_unscanned_receipts_in_the_window_only(): void
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db);
        $db->exec("INSERT INTO expenses (id, expense_date, vendor_id, vendor_name_raw) VALUES (100, '2026-01-02', 7, 'LAWNBOY')");
        $svc = $this->svc($db);
        $r = $svc->backfill(120, 1);
        $this->assertSame(1, $r['scanned']);
        $this->assertSame(1, $r['left']);
        $r = $svc->backfill(120, 10);
        $this->assertSame(['scanned' => 1, 'left' => 0], ['scanned' => $r['scanned'], 'left' => $r['left']]);
        $this->assertFalse((bool)$db->query("SELECT 1 FROM product_proposal_scans WHERE expense_id = 100")->fetchColumn(), 'older than 120 days');
    }

    public function test_nothing_happens_before_the_migration(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $svc = new ProductProposalService($db);
        $this->assertFalse($svc->ready());
        $this->assertSame(0, $svc->proposeForExpense(1));
        $this->assertSame([], $svc->queue('penny'));
        ProductProposalService::afterLineItemsSaved($db, 1);   // never throws
        $this->assertSame([], $svc->briefItems());
    }

    public function test_penny_brief_counts_waiting_proposals(): void
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db);
        $svc = $this->svc($db);
        $svc->proposeForExpense(412);
        $b = $svc->briefItems();
        $this->assertCount(1, $b);
        $this->assertSame('penny:product_proposals', $b[0]['kind']);
        $this->assertSame(2, $b[0]['value']);
        $this->assertSame('2 product proposals waiting (new products, label photos, costs from receipts)', $b[0]['text']);
        foreach (['key', 'kind', 'value', 'since', 'text', 'url', 'priority'] as $k) $this->assertArrayHasKey($k, $b[0]);
        $merged = PennyBriefAdapter::withItems(['head' => 'penny', 'headline' => 'x', 'items' => [['key' => 'a']], 'count' => 1], $b);
        $this->assertSame(2, $merged['count']);
    }
}
