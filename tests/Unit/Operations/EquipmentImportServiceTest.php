<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/EquipmentFixtures.php';

/** Importing an equipment list (CSV / xlsx) — EquipmentImportService, 2026-10-13. Synthetic list. */
final class EquipmentImportServiceTest extends TestCase
{
    private const CSV = "Name,Make,Model,Serial No.,Type,Purchase date,Cost,Truck,Used by\n"
        . "Mower 1,Honda,HRC216K3HXCH,MAKA-1000001,mower,2024-04-15,\"$2,899.00\",Dodge Ram,Nigel\n"
        . "Mower 2,Honda,HRC216K3HXCH,MAKA-1000002,,15/05/2025,2950,Red trailer,\n"
        . ",Stihl,FS 91 R,512345678,,,449.99,,\n"
        . "Duplicate,Honda,HRC216K3HXCH,MAKA-1000001,,,,,\n"
        . ",,,,,,,,\n";

    public function testHeaderMapFindsColumnsInAnyOrder(): void
    {
        $h = EquipmentImportService::headerMap([1 => ['A' => 'Equipment list'], 2 => ['A' => 'Serial #', 'B' => 'Brand', 'C' => 'Model No', 'D' => 'Date Purchased']]);
        $this->assertSame(2, $h['row']);
        $this->assertSame(['serial_no' => 'A', 'make' => 'B', 'model' => 'C', 'purchase_date' => 'D'], $h['map']);
        $this->assertSame(['2025-05-15', '2024-04-15', null], [EquipmentImportService::dateOf('15/05/2025'), EquipmentImportService::dateOf('2024-04-15'), EquipmentImportService::dateOf('')]);
        $this->assertSame([2899.0, null], [EquipmentImportService::moneyOf('$2,899.00'), EquipmentImportService::moneyOf('n/a')]);
    }

    public function testPreviewThenCommitAddsReadyRowsAndLinksTheModel(): void
    {
        $db = EquipmentFixtures::sqlite();
        $db->exec("INSERT INTO equipment (name, equipment_class, status) VALUES ('Dodge Ram', 'truck', 'active')");
        $imp = new EquipmentImportService($db, new EquipmentService($db, '2026-10-13'));

        $p = $imp->preview(self::CSV, 'equipment.csv');
        $this->assertTrue($p['ok']);
        $this->assertCount(4, $p['rows'], 'the blank line is ignored');
        [$m1, $m2, $stihl, $dup] = $p['rows'];
        $this->assertSame(['mower', 'gas', '2024-04-15', 2899.0, 1, 2], [$m1['equipment_class'], $m1['power_source'], $m1['purchase_date'], $m1['cost_manual'], $m1['truck_id'], $m1['assigned_user_id']]);
        $this->assertSame('mower', $m2['equipment_class'], 'type guessed from the Honda HRC model');
        $this->assertNull($m2['truck_id']);
        $this->assertStringContainsString('Truck: Red trailer', $m2['notes']);
        $this->assertSame(['Stihl FS 91 R trimmer', 'trimmer', 'ready'], [$stihl['name'], $stihl['equipment_class'], $stihl['status']]);
        $this->assertSame('skip', $dup['status'], 'same serial twice in one file');
        $this->assertSame(3, $p['ready']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM equipment")->fetchColumn(), 'preview adds nothing');

        $c = $imp->commit(self::CSV, 'equipment.csv');
        $this->assertSame([3, 1], [$c['added'], $c['skipped']]);
        $hondas = $db->query("SELECT catalog_product_id FROM equipment WHERE make = 'Honda'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(2, $hondas);
        $this->assertSame($hondas[0], $hondas[1], 'two Hondas, one catalogue model');
        $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM catalog_products WHERE family = 'tools'")->fetchColumn());

        $again = $imp->commit(self::CSV, 'equipment.csv');
        $this->assertSame([0, 4], [$again['added'], $again['skipped']], 'importing the same file twice adds nothing (serials already registered)');
    }
}
