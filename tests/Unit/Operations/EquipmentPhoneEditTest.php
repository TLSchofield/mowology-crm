<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/EquipmentFixtures.php';

/** Editing a machine from the iPhone (2026-10-14: the serial couldn't be added after the plate snap). */
final class EquipmentPhoneEditTest extends TestCase
{
    public function testThePhoneChangesOnlyWhatItSendsAndRelinksTheModel(): void
    {
        $db = EquipmentFixtures::sqlite();
        $db->sqliteCreateFunction('FIELD', function ($v, ...$list) { $i = array_search($v, $list, true); return $i === false ? 0 : $i + 1; });
        $eq = new EquipmentService($db, '2026-10-14');
        $id = (int)$eq->save(['name' => 'Shindaiwa', 'equipment_class' => 'other', 'make' => 'Shindaiwa', 'power_source' => 'gas',
                              'hours_baseline' => '12.5', 'notes' => 'from the truck', 'plate_media_id' => 1149])['id'];

        $r = $eq->updateFromPhone($id, ['name' => 'Shindaiwa EB770RT backpack blower', 'equipment_class' => 'blower',
                                        'model' => 'EB770RT', 'serial_no' => 'P52115010324']);
        $this->assertTrue($r['ok'], $r['message']);
        $row = $eq->row($id);
        $this->assertSame(['blower', 'EB770RT', 'P52115010324'], [$row['equipment_class'], $row['model'], $row['serial_no']]);
        $this->assertEquals(12.5, (float)$row['hours_baseline'], 'a field the phone never shows is kept');
        $this->assertSame('from the truck', $row['notes']);
        $this->assertSame(1149, (int)$row['plate_media_id']);
        $this->assertNotNull($row['catalog_product_id'], 'the new model joins the shared catalogue');

        $list = $eq->phoneList();
        $this->assertSame('P52115010324', $list[0]['serial_no']);
        $this->assertArrayNotHasKey('hours_baseline', $list[0]);
    }

    public function testASerialOnAnotherMachineIsRefused(): void
    {
        $db = EquipmentFixtures::sqlite();
        $eq = new EquipmentService($db, '2026-10-14');
        $eq->save(['name' => 'Blower A', 'equipment_class' => 'blower', 'serial_no' => 'P1234567890']);
        $b = (int)$eq->save(['name' => 'Blower B', 'equipment_class' => 'blower'])['id'];
        $r = $eq->updateFromPhone($b, ['serial_no' => 'p1234567890']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Blower A', $r['message']);
        $this->assertFalse($eq->updateFromPhone(999, ['name' => 'x'])['ok']);
    }
}
