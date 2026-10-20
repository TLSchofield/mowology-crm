<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Otto adds machines from purchase receipts by himself, and "Not equipment" moves one out for good
 * (EquipmentReceiptIntakeService, migration 1377, 2026-10-19). Fake receipts.
 */
class EquipmentReceiptIntakeTest extends TestCase
{
    private static function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, expense_date TEXT, vendor_id INT, vendor_name_raw TEXT, description TEXT,
                   total REAL, gst_amount REAL, pst_amount REAL, accounting_category TEXT, status TEXT)");
        $db->exec("CREATE TABLE expense_line_items (id INTEGER PRIMARY KEY, expense_id INT, name TEXT, line_total REAL, sort_order INT DEFAULT 0)");
        $db->exec("CREATE TABLE equipment (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, equipment_class TEXT, make TEXT, model TEXT, serial_no TEXT,
                   power_source TEXT, assigned_user_id INT, purchase_date TEXT, expense_id INT, cost_manual REAL, cca_class TEXT, hours_baseline REAL,
                   runtime_new_min INT, range_km INT, reserve_pct INT, top_speed_kph INT, vehicle_id TEXT, base_lat REAL, base_lng REAL, status TEXT, notes TEXT)");
        $db->exec("CREATE TABLE equipment_service_intervals (id INTEGER PRIMARY KEY, equipment_id INT)");
        $db->exec("CREATE TABLE equipment_service_log (id INTEGER PRIMARY KEY, equipment_id INT)");
        $db->exec("CREATE TABLE equipment_receipt_intake (id INTEGER PRIMARY KEY AUTOINCREMENT, expense_id INT, line_item_id INT DEFAULT 0, outcome TEXT,
                   equipment_id INT, line_name TEXT, line_total REAL, decided_by INT, UNIQUE (expense_id, line_item_id))");
        $db->exec("INSERT INTO vendors VALUES (1, 'Fake Hardware')");
        $db->exec("INSERT INTO expenses VALUES
            (10, '2026-03-05', 1, NULL, NULL, 1800.95, 90, 110.96, 'Tools/Equipment', 'approved'),
            (11, '2026-04-01', 1, NULL, 'Backpack blower', 560.00, 25, 35, 'Tools/Equipment', 'forwarded'),
            (12, '2026-05-01', 1, NULL, NULL, 700.00, 0, 0, 'Tools/Equipment', 'draft'),
            (13, '2026-05-02', 1, NULL, NULL, 700.00, 0, 0, 'Materials', 'approved')");
        $db->exec("INSERT INTO expense_line_items (id, expense_id, name, line_total, sort_order) VALUES
            (1, 10, 'EG056V22\" SPMW A', 1599.99, 1), (2, 10, 'Mower blade', 45.00, 2), (3, 10, '3 yr protection plan', 310.00, 3),
            (4, 12, 'Chainsaw 18in', 650.00, 1), (5, 13, 'Paver cutter', 650.00, 1)");
        return $db;
    }

    public function testGuesses(): void
    {
        $this->assertSame('mower', EquipmentReceiptIntakeService::guessClass('EG056V22" SPMW A'));
        $this->assertSame('hedge_trimmer', EquipmentReceiptIntakeService::guessClass('56V hedge trimmer'));
        $this->assertSame('trimmer', EquipmentReceiptIntakeService::guessClass('Line trimmer 16in'));
        $this->assertSame('other', EquipmentReceiptIntakeService::guessClass('Wheelbarrow'));
        $this->assertSame('gas', EquipmentReceiptIntakeService::guessPower('Backpack blower 63.3cc'));
        $this->assertSame('battery', EquipmentReceiptIntakeService::guessPower('EG056V22" SPMW A'));
        $this->assertTrue(EquipmentReceiptIntakeService::isMachine('Line trimmer 16in', 399.0, 300.0), 'a line trimmer is a machine');
        $this->assertFalse(EquipmentReceiptIntakeService::isMachine('Trimmer line .095', 399.0, 300.0));
        $this->assertFalse(EquipmentReceiptIntakeService::isMachine('3 yr protection plan', 310.0, 300.0));
        $this->assertFalse(EquipmentReceiptIntakeService::isMachine('Leaf rake', 29.0, 300.0));
    }

    public function testApprovedMachinesAreAddedOnceAndMovedOutForGood(): void
    {
        $db = self::db();
        $svc = new EquipmentReceiptIntakeService($db, new EquipmentService($db));
        $r = $svc->sweep();
        $this->assertSame(2, $r['added'], 'the mower line and the no-lines blower; not the blade, the plan, the draft or Materials');
        $eq = $db->query("SELECT name, equipment_class, power_source, expense_id, purchase_date FROM equipment ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(['EG056V22" SPMW A', 'mower', 'battery', 10, '2026-03-05'],
                          [$eq[0]['name'], $eq[0]['equipment_class'], $eq[0]['power_source'], (int)$eq[0]['expense_id'], $eq[0]['purchase_date']]);
        $this->assertSame(['Backpack blower', 'blower', 11], [$eq[1]['name'], $eq[1]['equipment_class'], (int)$eq[1]['expense_id']]);
        $this->assertSame(0, $svc->sweep()['added'], 'nothing twice');

        $mower = (int)$db->query("SELECT id FROM equipment WHERE expense_id = 10")->fetchColumn();
        $this->assertTrue($svc->moveOut($mower, 5)['ok']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM equipment WHERE id = $mower")->fetchColumn());
        $this->assertSame(0, $svc->sweep()['added'], 'moved out stays out');
        $this->assertSame('moved_out', $db->query("SELECT outcome FROM equipment_receipt_intake WHERE expense_id = 10")->fetchColumn());
    }

    public function testAMachineWithHistoryOrAddedByHandIsNotMovedOut(): void
    {
        $db = self::db();
        $svc = new EquipmentReceiptIntakeService($db, new EquipmentService($db));
        $svc->sweep();
        $blower = (int)$db->query("SELECT id FROM equipment WHERE expense_id = 11")->fetchColumn();
        $db->exec("INSERT INTO equipment_service_log (equipment_id) VALUES ($blower)");
        $this->assertFalse($svc->moveOut($blower, 5)['ok']);
        $db->exec("INSERT INTO equipment (name, equipment_class, power_source, status) VALUES ('Hand-added', 'other', 'battery', 'active')");
        $this->assertFalse($svc->moveOut((int)$db->lastInsertId(), 5)['ok']);
    }

    public function testAReceiptLinkedByHandIsLeftAlone(): void
    {
        $db = self::db();
        $db->exec("INSERT INTO equipment (name, equipment_class, power_source, expense_id, status) VALUES ('My mower', 'mower', 'battery', 10, 'active')");
        $r = (new EquipmentReceiptIntakeService($db, new EquipmentService($db)))->sweep();
        $this->assertSame(1, $r['added'], 'only the blower');
    }
}
