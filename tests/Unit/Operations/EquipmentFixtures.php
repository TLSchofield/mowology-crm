<?php
declare(strict_types=1);

require_once __DIR__ . '/PriceListFixtures.php';

/** SQLite: the catalogue (PriceListFixtures) + the equipment tables as migrations 1155/1156/1350 leave them. */
final class EquipmentFixtures
{
    public static function sqlite(): PDO
    {
        $db = PriceListFixtures::sqlite();
        $db->exec("INSERT INTO catalog_families (slug, name, matcher, default_use_kind, sort_order) VALUES ('tools','Tools & equipment','part_number','equipment',6)");
        $db->exec("INSERT INTO catalog_categories (parent_id, family, name, slug) VALUES (NULL,'tools','Tools & equipment','tools')");
        $db->exec("CREATE TABLE equipment (id INTEGER PRIMARY KEY, name TEXT NOT NULL, equipment_class TEXT NOT NULL, make TEXT, model TEXT, catalog_product_id INT,
                   serial_no TEXT, power_source TEXT DEFAULT 'battery', assigned_user_id INT, truck_id INT, purchase_date TEXT, expense_id INT, cost_manual REAL,
                   cca_class TEXT, hours_baseline REAL DEFAULT 0, runtime_new_min INT, range_km INT, reserve_pct INT, top_speed_kph INT, vehicle_id TEXT,
                   base_lat REAL, base_lng REAL, status TEXT DEFAULT 'active', notes TEXT, plate_media_id INT, photo_media_id INT,
                   created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT)");
        $db->exec("CREATE TABLE equipment_service_intervals (id INTEGER PRIMARY KEY, equipment_class TEXT, equipment_id INT, catalog_product_id INT, task TEXT,
                   every_hours REAL, every_days INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE equipment_service_log (id INTEGER PRIMARY KEY, equipment_id INT, task TEXT, done_on TEXT, task_id INT, expense_id INT, note TEXT, created_by INT,
                   created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE catalog_service_intervals (id INTEGER PRIMARY KEY, product_id INT, task TEXT, task_key TEXT, every_hours REAL, every_days INT, page TEXT,
                   quote TEXT, source TEXT DEFAULT 'manual', times_confirmed INT DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(product_id, task_key))");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, is_active INT DEFAULT 1)");
        $db->exec("INSERT INTO users (id, full_name) VALUES (1, 'Tim Schofield'), (2, 'Nigel Crew')");
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, expense_date TEXT, total REAL, amount REAL, vendor_name_raw TEXT, description TEXT, notes TEXT,
                   accounting_category TEXT, asset_tag TEXT, status TEXT)");
        $db->exec("CREATE TABLE expense_line_items (id INTEGER PRIMARY KEY, expense_id INT, name TEXT)");
        return $db;
    }
}
