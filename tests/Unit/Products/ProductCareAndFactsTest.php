<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ProductsTestDb.php';
require_once __DIR__ . '/LabelReaderServiceTest.php';

/**
 * Otto's "how to look after it" (due / nearly due, low stock, label care + SDS), his brief
 * items, the label capture record path, and Ask Charlie's product / equipment answers
 * (data only, null when unsure, source suffix).
 */
class ProductCareAndFactsTest extends TestCase
{
    private const TODAY = '2026-10-07';

    private function shop(): PDO
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db);
        $db->exec("UPDATE products SET current_stock = 2, reorder_point = 0 WHERE id = 44");
        $db->exec("UPDATE products SET current_stock = 0 WHERE id = 11");
        $db->exec("UPDATE expense_line_items SET product_id = 44 WHERE id = 229");
        $db->exec("UPDATE expense_line_items SET product_id = 11 WHERE id IN (230, 231)");
        $db->exec("INSERT INTO equipment (id, name, equipment_class, make, model, purchase_date) VALUES
                   (3, 'EGO mower', 'mower', 'EGO', 'LM2135SP', '2026-09-01'), (4, 'Stihl trimmer', 'trimmer', 'Stihl', 'FS91R', '2026-01-01'),
                   (5, 'Might-E truck', 'truck', NULL, NULL, '2025-01-01')");
        $db->exec("INSERT INTO equipment_service_intervals (equipment_id, equipment_class, task, every_hours, every_days) VALUES
                   (3, NULL, 'Sharpen blade', NULL, 40), (NULL, 'trimmer', 'Replace spark plug', NULL, 365)");
        $db->exec("INSERT INTO equipment_service_log (equipment_id, task, done_on) VALUES (4, 'Replace spark plug', '2026-03-01')");
        return $db;
    }

    // ── Care / brief ────────────────────────────────────────────────────────

    public function test_interval_state(): void
    {
        $this->assertSame('due', ProductCareService::intervalState(25.0, null, 25.0, 3));
        $this->assertSame('soon', ProductCareService::intervalState(25.0, null, 20.5, 3));
        $this->assertSame('soon', ProductCareService::intervalState(null, 40, 0.0, 36));
        $this->assertSame('ok', ProductCareService::intervalState(25.0, 365, 3.0, 30));
        $this->assertSame('CBM: none left.', ProductCareService::stockLine('CBM', 0, 0));
        $this->assertSame('Seed: 2 left (reorder at 3).', ProductCareService::stockLine('Seed', 2, 3));
    }

    public function test_machines_show_where_each_interval_stands_and_trucks_are_left_out(): void
    {
        $m = (new ProductCareService($this->shop(), self::TODAY))->machines();
        $this->assertSame(['EGO mower', 'Stihl trimmer'], array_column($m, 'name'));
        $ego = $m[0]['intervals'][0];
        $this->assertSame(['Sharpen blade', 'every 40 days', 'soon', 36], [$ego['task'], $ego['every'], $ego['state'], $ego['days']]);
        $this->assertSame('ok', $m[1]['intervals'][0]['state']);
        $this->assertSame('2026-03-01', $m[1]['intervals'][0]['last']);
    }

    public function test_otto_brief_has_service_and_low_stock_items_in_the_shared_shape(): void
    {
        $items = (new ProductCareService($this->shop(), self::TODAY))->briefItems();
        $kinds = array_column($items, 'kind');
        $this->assertSame(['service_soon', 'low_stock'], $kinds);
        $this->assertSame('EGO mower is nearly due for service: sharpen blade.', $items[0]['text']);
        $this->assertSame('Low stock — Black Composted Bark Mulch: none left.', $items[1]['text']);
        foreach ($items as $it) foreach (['key', 'kind', 'value', 'since', 'text', 'url', 'priority'] as $k) $this->assertArrayHasKey($k, $it);
    }

    public function test_care_lists_label_notes_and_asks_for_an_sds_on_chemicals_only(): void
    {
        $db = $this->shop();
        $db->exec("UPDATE products SET care_notes = 'Store in a cool, dry place.', label_media_id = 900 WHERE id = 44");
        $db->exec("INSERT INTO products (id, name, care_notes, label_media_id) VALUES (50, 'Scotts Turf Builder Lawn Fertilizer 5kg', 'Keep out of reach of children.', 901)");
        $svc = new ProductCareService($db, self::TODAY);
        $care = $svc->care();
        $this->assertSame(['Richardson Sun & Shade Lawn Seed 5 kg', 'Scotts Turf Builder Lawn Fertilizer 5kg'], array_column($care, 'name'));
        $this->assertFalse($care[0]['needs_sds']);
        $this->assertTrue($care[1]['needs_sds']);
        $this->assertFalse($svc->saveSds(50, 'not a link')['ok']);
        $this->assertTrue($svc->saveSds(50, 'https://scotts.ca/sds/turf-builder.pdf')['ok']);
        $this->assertFalse($svc->care()[1]['needs_sds']);
        $this->assertTrue($svc->setMarketingOk(44, true)['ok']);
        $this->assertSame(1, (int)$db->query("SELECT photo_marketing_ok FROM products WHERE id = 44")->fetchColumn());
    }

    public function test_label_capture_record_reads_stores_and_proposes_without_an_expense(): void
    {
        $db = ProductsTestDb::make();
        ProductsTestDb::lawnboy($db, false);
        $expenses = (int)$db->query("SELECT COUNT(*) FROM expenses")->fetchColumn();
        $r = (new LabelCaptureService($db))->record(1, 900, 49.2, -123.1, LabelReaderServiceTest::SEED, 'vision', '2026-10-07 16:40:00');
        $this->assertTrue($r['success']);
        $this->assertSame('product', $r['kind']);
        $this->assertSame('Richardson Sun & Shade Lawn Seed 5 kg', $r['title']);
        $this->assertSame($expenses, (int)$db->query("SELECT COUNT(*) FROM expenses")->fetchColumn(), 'a label never makes an expense');
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM product_proposals WHERE head = 'penny'")->fetchColumn());
        $blank = (new LabelCaptureService($db))->record(1, 901, null, null, '', 'none');
        $this->assertStringContainsString('could not read any text', $blank['message']);
    }

    // ── Ask Charlie ─────────────────────────────────────────────────────────

    public function test_intents(): void
    {
        $this->assertSame('stock', ProductFactAnswerer::intent('How much seed do we have?'));
        $this->assertSame('stock', ProductFactAnswerer::intent('how many bags of mulch left'));
        $this->assertSame('cost', ProductFactAnswerer::intent('What does CBM cost us?'));
        $this->assertSame('last_bought', ProductFactAnswerer::intent('When did we last buy seed?'));
        $this->assertSame('service_due', ProductFactAnswerer::intent("What's due for service?"));
        $this->assertSame('last_service', ProductFactAnswerer::intent('When was the EGO mower last serviced?'));
        $this->assertSame('equipment', ProductFactAnswerer::intent('What equipment do we have?'));
        $this->assertNull(ProductFactAnswerer::intent('How many visits to Lawnboy today?'));
    }

    private function ask(string $q): ?array
    {
        return (new ProductFactAnswerer($this->shop(), self::TODAY))->answer($q);
    }

    public function test_how_much_seed_do_we_have(): void
    {
        $a = $this->ask('How much seed do we have?');
        $this->assertSame('penny', $a['head']);
        $this->assertStringStartsWith('We have 2 bags of Richardson Sun & Shade Lawn Seed 5 kg in stock.', $a['answer']);
        $this->assertStringEndsWith("\n— from Penny's products", $a['answer']);
    }

    public function test_how_much_mulch_and_cost_and_last_bought(): void
    {
        $this->assertStringStartsWith('None of Black Composted Bark Mulch left in stock.', $this->ask('how much mulch have we got')['answer']);
        $c = $this->ask('What does CBM cost us?');
        $this->assertStringStartsWith('Black Composted Bark Mulch costs us $41 per yd. Last bought Oct 7, 2026 at Lawnboy for $40 (receipt #413).', $c['answer']);
        $b = $this->ask('When did we last buy seed?');
        $this->assertStringStartsWith('Last bought Oct 7, 2026 at Lawnboy: 2 × $60 (receipt #412).', $b['answer']);
    }

    public function test_service_due_last_service_and_equipment_list(): void
    {
        $d = $this->ask("What's due for service?");
        $this->assertSame('otto', $d['head']);
        $this->assertStringStartsWith('Nearly due: EGO mower — sharpen blade.', $d['answer']);
        $this->assertStringEndsWith("\n— from Otto's equipment", $d['answer']);
        $this->assertStringStartsWith('The Stihl trimmer was last serviced Mar 1, 2026: replace spark plug.', $this->ask('When was the stihl trimmer last serviced?')['answer']);
        $this->assertStringStartsWith('No service is logged for the EGO mower yet.', $this->ask('When was the EGO mower last serviced?')['answer']);
        $this->assertStringStartsWith('3 machines: 1 mower (EGO mower); 1 trimmer (Stihl trimmer); 1 truck (Might-E truck).', $this->ask('What equipment do we have?')['answer']);
    }

    public function test_unsure_means_null(): void
    {
        $this->assertNull($this->ask('How much gravel do we have?'), 'no such product');
        $this->assertNull($this->ask('What does a dump run cost?'));
        $this->assertNull($this->ask('When was the blower last serviced?'), 'no such machine');
        $this->assertNull((new ProductFactAnswerer(new PDO('sqlite::memory:'), self::TODAY))->answer('How much seed do we have?'), 'no tables → null');
    }

    public function test_charlie_routes_product_questions_and_keeps_his_own(): void
    {
        $db = $this->shop();
        $c = new CharlieFactAnswerer($db, self::TODAY);
        $a = $c->answer('How much seed do we have?');
        $this->assertNotNull($a);
        $this->assertStringEndsWith("— from Penny's products", $a['answer']);
        $this->assertNull($c->answer('What does a dump run cost?'), 'no ops_places here → still null, not a product answer');
    }
}
