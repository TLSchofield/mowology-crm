<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/EquipmentFixtures.php';

/**
 * A machine's MODEL is shared catalogue knowledge; the UNIT is private (migration 1350, 2026-10-13).
 */
final class EquipmentCatalogTest extends TestCase
{
    private function honda(EquipmentService $eq, string $serial, string $name = 'Honda HRC216 mower'): int
    {
        $r = $eq->save(['name' => $name, 'equipment_class' => 'mower', 'make' => 'Honda', 'model' => 'HRC216K3HXCH', 'serial_no' => $serial,
                        'power_source' => 'gas', 'purchase_date' => '2026-04-15', 'cost_manual' => '2899.00', 'model_name' => 'HRC216 Hydrostatic Commercial']);
        $this->assertTrue($r['ok']);
        return (int)$r['id'];
    }

    public function testTwoUnitsOfOneModelShareOneCatalogueProduct(): void
    {
        $db = EquipmentFixtures::sqlite();
        $eq = new EquipmentService($db, '2026-10-13');
        $a = $eq->row($this->honda($eq, 'MAKA-2058647'));
        $b = $eq->row($this->honda($eq, 'MAKA-2059999', 'Honda HRC216 mower #2'));
        $this->assertNotNull($a['catalog_product_id']);
        $this->assertSame($a['catalog_product_id'], $b['catalog_product_id'], 'same model, one catalogue product');
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM catalog_products WHERE family = 'tools'")->fetchColumn());

        $p = $db->query("SELECT * FROM catalog_products")->fetch();
        $this->assertSame(['Honda', 'HRC216K3HXCH', 'Honda HRC216K3HXCH'], [$p['brand'], $p['mfr_part_no'], $p['canonical_name']]);
        // Shared rows never carry the unit's serial or price.
        $shared = json_encode([$db->query("SELECT * FROM catalog_products")->fetchAll(), $db->query("SELECT * FROM catalog_attribute_values")->fetchAll()]);
        $this->assertStringNotContainsString('MAKA', $shared);
        $this->assertStringNotContainsString('2899', $shared);
        $attrs = (new EquipmentCatalogService($db))->attributes((int)$p['id']);
        $this->assertSame(['mower', 'gas', 'HRC216 Hydrostatic Commercial'], [$attrs['equipment_class'], $attrs['power_source'], $attrs['model_name']]);
        $this->assertSame(2, (new EquipmentCatalogService($db))->unitsOfModel((int)$p['id']));
    }

    public function testADifferentModelNumberIsADifferentModelNeverAFuzzySuggestion(): void
    {
        $db = EquipmentFixtures::sqlite();
        $eq = new EquipmentService($db, '2026-10-13');
        $a = $eq->row($this->honda($eq, 'S1'));
        $r = $eq->save(['name' => 'Honda HRC216 other', 'equipment_class' => 'mower', 'make' => 'Honda', 'model' => 'HRC216K3HXC', 'power_source' => 'gas']);
        $c = $eq->row((int)$r['id']);
        $this->assertNotNull($c['catalog_product_id']);
        $this->assertNotSame($a['catalog_product_id'], $c['catalog_product_id']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM catalog_match_queue")->fetchColumn(), 'no review item for a machine model');
        // Spacing and case on the model don't make a new model; the same number under another brand does.
        $d = $eq->row((int)$eq->save(['name' => 'x', 'equipment_class' => 'mower', 'make' => 'HONDA', 'model' => 'hrc216-k3hxch'])['id']);
        $this->assertSame($a['catalog_product_id'], $d['catalog_product_id']);
        $e = $eq->row((int)$eq->save(['name' => 'y', 'equipment_class' => 'mower', 'make' => 'Toro', 'model' => 'HRC216K3HXCH'])['id']);
        $this->assertNotSame($a['catalog_product_id'], $e['catalog_product_id']);
    }

    public function testManualIntervalsAreSharedByTheModelAndAdoptedPerCompany(): void
    {
        $db = EquipmentFixtures::sqlite();
        $eq = new EquipmentService($db, '2026-10-13');
        $a = $eq->row($this->honda($eq, 'S1'));
        $b = $eq->row($this->honda($eq, 'S2'));
        $pid = (int)$a['catalog_product_id'];
        $ec = new EquipmentCatalogService($db);
        $ec->recordManualInterval($pid, 'Change engine oil', 50.0, 365, '42', 'Every season or 50 hours');
        $ec->recordManualInterval($pid, 'change engine oil', 25.0, null, '9', 'a second reading');
        $rows = $ec->sharedIntervals($pid);
        $this->assertCount(1, $rows);
        $this->assertSame([50.0, 2, '42'], [(float)$rows[0]['every_hours'], (int)$rows[0]['times_confirmed'], $rows[0]['page']], 'the first reading stays; confirmations count');

        $this->assertCount(1, $ec->notAdopted($pid));
        $this->assertSame([], $eq->intervalRows($a), 'nothing is watched until Tim adopts it');
        $this->assertSame(1, $ec->adoptShared($pid));
        $this->assertSame(0, $ec->adoptShared($pid), 'adopting twice adds nothing');
        foreach ([$a, $b] as $unit) {
            $rows = $eq->intervalRows($unit);
            $this->assertCount(1, $rows, 'both units of the model get it');
            $this->assertSame(['Change engine oil', 'model'], [$rows[0]['task'], $rows[0]['scope']]);
        }
        // An item's own interval beats the model's for the same task.
        $eq->saveInterval(['equipment_id' => $a['id'], 'task' => 'Change engine oil', 'every_hours' => 30]);
        $this->assertSame(['item', 30.0], [$eq->intervalRows($a)[0]['scope'], $eq->intervalRows($a)[0]['every_hours']]);
        $this->assertSame('model', $eq->intervalRows($b)[0]['scope']);
    }

    public function testPartsBecomeCatalogueProductsSoADealerListMatches(): void
    {
        $db = EquipmentFixtures::sqlite();
        $eq = new EquipmentService($db, '2026-10-13');
        $pid = (int)$eq->row($this->honda($eq, 'S1'))['catalog_product_id'];
        $ec = new EquipmentCatalogService($db);
        $r = $ec->setPart($pid, 'part_air_filter', '17211-ZL8-023');
        $this->assertTrue($r['ok']);
        $this->assertNotNull($r['part_product_id']);
        // A dealer's parts list line for the same part number (another spelling) resolves to that product.
        $line = (new CatalogService($db))->resolve('tools', ['name' => 'AIR FILTER ELEMENT', 'brand' => 'Honda', 'mfr_part_no' => '17211ZL8023']);
        $this->assertSame($r['part_product_id'], $line['product_id']);
        $parts = array_column($ec->parts($pid), null, 'key');
        $this->assertSame(['17211-ZL8-023', $r['part_product_id']], [$parts['part_air_filter']['value'], $parts['part_air_filter']['part_product_id']]);
        $this->assertSame('', $parts['part_blade']['value']);
        $this->assertFalse($ec->setPart($pid, 'part_unknown', 'x')['ok']);
    }

    public function testAddFromPlateRefusesADuplicateSerialAndYearCostCountsOwnAndTaggedReceipts(): void
    {
        $db = EquipmentFixtures::sqlite();
        $db->exec("INSERT INTO expenses (id, expense_date, total, vendor_name_raw, description, accounting_category, asset_tag, status) VALUES
                   (10, '2026-04-15', 2899.00, 'Dealer Ltd', 'Honda HRC216K3HXCH', 'Tools/Equipment', NULL, 'approved'),
                   (11, '2026-06-01', 40.00, 'Gas Bar', 'gas', 'Fuel', 'equipment', 'approved'),
                   (12, '2026-07-01', 30.00, 'Gas Bar', 'gas', 'Fuel', 'equipment', 'rejected'),
                   (13, '2026-08-01', 62.50, 'Dealer Ltd', 'blade', 'Repairs/Maintenance', NULL, 'approved')");
        $eq = new EquipmentService($db, '2026-10-13');
        $cands = $eq->receiptCandidates(['make' => 'Honda', 'model' => 'HRC216K3HXCH', 'date' => '2026-04-14', 'cost' => 2899]);
        $this->assertSame(10, $cands[0]['id'], 'model + date + amount on the receipt');
        $r = $eq->addFromPlate(['name' => 'Honda HRC216 mower', 'equipment_class' => 'mower', 'make' => 'Honda', 'model' => 'HRC216K3HXCH',
                                'serial_no' => 'MAKA-2058647', 'power_source' => 'gas', 'purchase_date' => '2026-04-15', 'expense_id' => 10], 1);
        $this->assertTrue($r['ok']);
        $this->assertFalse($eq->addFromPlate(['name' => 'again', 'equipment_class' => 'mower', 'serial_no' => 'maka-2058647'], 1)['ok']);
        $eq->logService((int)$r['id'], 'Sharpen blade', '2026-08-01', 1, null, null, 13);
        $c = $eq->yearCost($eq->row((int)$r['id']));
        $this->assertSame([2961.5, 40.0, 1, 40.0, 3001.5], [$c['own_total'], $c['pool_total'], $c['units'], $c['share'], $c['total']],
                          'purchase + blade receipt are its own; the rejected fuel receipt is not counted');
    }
}
