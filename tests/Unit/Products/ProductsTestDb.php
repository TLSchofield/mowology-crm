<?php
declare(strict_types=1);

/**
 * In-memory SQLite stand-in for the tables the label / receipt → product flow reads and
 * writes (products, expenses, line items, vendors, equipment, migration 1225 tables).
 * Not a test case — included by the Products tests.
 */
final class ProductsTestDb
{
    public static function make(): PDO
    {
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        $db = method_exists(PDO::class, 'connect') ? PDO::connect('sqlite::memory:', null, null, $opts) : new PDO('sqlite::memory:', null, null, $opts);
        // MySQL's FIELD() (EquipmentService::items ordering)
        $field = function ($v, ...$list) { $i = array_search($v, $list, true); return $i === false ? 0 : $i + 1; };
        if (method_exists($db, 'createFunction')) $db->createFunction('FIELD', $field);
        else $db->sqliteCreateFunction('FIELD', $field);
        $db->exec("CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, sku TEXT, description TEXT, category_id INT, base_cost REAL DEFAULT 0,
                   base_price REAL DEFAULT 0, track_inventory INT DEFAULT 0, current_stock REAL DEFAULT 0, reorder_point REAL DEFAULT 0, supplier_info TEXT,
                   image_url TEXT, sds_sheet_url TEXT, safety_warnings TEXT, care_notes TEXT, label_media_id INT, photo_marketing_ok INT DEFAULT 0, active INT DEFAULT 1)");
        $db->exec("CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT, aliases TEXT)");
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, expense_date TEXT, vendor_id INT, vendor_name_raw TEXT, raw_ocr_json TEXT, created_by INT, total REAL, amount REAL, status TEXT)");
        $db->exec("CREATE TABLE expense_line_items (id INTEGER PRIMARY KEY AUTOINCREMENT, expense_id INT, product_id INT, name TEXT, quantity REAL DEFAULT 1,
                   unit_price REAL, line_total REAL DEFAULT 0, sku_raw TEXT, sort_order INT DEFAULT 0, is_adjustment INT DEFAULT 0)");
        $db->exec("CREATE TABLE label_captures (id INTEGER PRIMARY KEY AUTOINCREMENT, media_id INT, captured_by INT, captured_at TEXT, lat REAL, lng REAL,
                   ocr_text TEXT, ocr_source TEXT, parsed_json TEXT, status TEXT DEFAULT 'pending', result_type TEXT, result_id INT)");
        $db->exec("CREATE TABLE product_proposals (id INTEGER PRIMARY KEY AUTOINCREMENT, head TEXT, kind TEXT, proposal_key TEXT, status TEXT DEFAULT 'pending',
                   title TEXT, product_id INT, equipment_id INT, label_capture_id INT, vendor_id INT, payload_json TEXT, decided_by INT, decided_at TEXT,
                   created_at TEXT, updated_at TEXT)");
        $db->exec("CREATE TABLE product_proposal_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, proposal_id INT, line_ref TEXT UNIQUE, expense_id INT, line_item_id INT,
                   name TEXT, quantity REAL, unit_price REAL, line_date TEXT, vendor_name TEXT)");
        $db->exec("CREATE TABLE product_proposal_scans (expense_id INT PRIMARY KEY, scanned_at TEXT)");
        $db->exec("CREATE TABLE equipment (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, equipment_class TEXT, make TEXT, model TEXT, serial_no TEXT,
                   power_source TEXT DEFAULT 'battery', assigned_user_id INT, purchase_date TEXT, expense_id INT, cost_manual REAL, cca_class TEXT,
                   hours_baseline REAL DEFAULT 0, runtime_new_min INT, range_km INT, reserve_pct INT, top_speed_kph INT, vehicle_id TEXT, base_lat REAL,
                   base_lng REAL, status TEXT DEFAULT 'active', notes TEXT, created_at TEXT DEFAULT '2026-01-01 00:00:00')");
        $db->exec("CREATE TABLE battery_pack_runs (id INTEGER PRIMARY KEY, equipment_id INT, run_date TEXT, runtime_min INT, ran_flat INT, logged_by INT)");
        $db->exec("CREATE TABLE equipment_service_intervals (id INTEGER PRIMARY KEY AUTOINCREMENT, equipment_class TEXT, equipment_id INT, task TEXT, every_hours REAL, every_days INT)");
        $db->exec("CREATE TABLE equipment_service_log (id INTEGER PRIMARY KEY AUTOINCREMENT, equipment_id INT, task TEXT, done_on TEXT, task_id INT, expense_id INT, note TEXT, created_by INT)");
        $db->exec("CREATE TABLE equipment_manual_reads (id INTEGER PRIMARY KEY AUTOINCREMENT, equipment_id INT, media_id INT, file_name TEXT, source TEXT,
                   proposals_json TEXT, note TEXT, model TEXT, input_tokens INT, output_tokens INT, cost_usd REAL, error TEXT, requested_by INT, created_at TEXT)");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        return $db;
    }

    /** Linker that does what ExpenseLineItemService::link() does to stock. */
    public static function linker(PDO $db): callable
    {
        return function (int $lineId, int $productId) use ($db): void {
            $q = $db->prepare("SELECT quantity FROM expense_line_items WHERE id = ?");
            $q->execute([$lineId]);
            $qty = (float)$q->fetchColumn();
            $db->prepare("UPDATE expense_line_items SET product_id = ? WHERE id = ?")->execute([$productId, $lineId]);
            $db->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ? AND track_inventory = 1")->execute([$qty, $productId]);
        };
    }

    /** Tim's real data: Lawnboy (vendor 7), product #11 CBM bark mulch, product #44 the seed. */
    public static function lawnboy(PDO $db, bool $withSeedProduct = true, float $seedCost = 60.0): void
    {
        $db->exec("INSERT INTO vendors (id, name) VALUES (7, 'LAWNBOY'), (9, 'The Home Depot'), (3, 'Chevron')");
        $db->exec("INSERT INTO products (id, name, sku, base_cost, track_inventory, current_stock) VALUES (11, 'Black Composted Bark Mulch', 'CBM', 41, 1, 0)");
        $db->exec("INSERT INTO products (id, name, sku, base_cost, track_inventory, current_stock) VALUES (19, 'OVER-SEED', NULL, 0, 0, 0)");
        if ($withSeedProduct) {
            $db->prepare("INSERT INTO products (id, name, sku, base_cost, track_inventory, current_stock) VALUES (44, 'Richardson Sun & Shade Lawn Seed 5 kg', 'TL02100350', ?, 1, 0)")
               ->execute([$seedCost]);
        }
        $db->exec("INSERT INTO expenses (id, expense_date, vendor_id, vendor_name_raw, created_by) VALUES (412, '2026-10-07', 7, 'LAWNBOY', 1), (413, '2026-10-07', 7, 'LAWNBOY', 1)");
        $db->exec("INSERT INTO expense_line_items (id, expense_id, name, quantity, unit_price, line_total) VALUES
                   (229, 412, '2 B. 5K Seed', 2, 60, 120),
                   (230, 412, 'CBM', 2, 40, 80),
                   (231, 413, 'CBM 1 yd', 1, 40, 40)");
    }
}
