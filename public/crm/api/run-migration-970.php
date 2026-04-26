<?php
/**
 * Run Migration 970 — Purchase Task System
 *
 * Expands tasks table with purchase-specific columns.
 * Creates task_items table for purchase line items.
 * Enhances vendor_locations with operating hours/contact info.
 * Links expenses and job_time_entries to purchase tasks.
 *
 * Admin-only, re-runnable (column-existence checks).
 */
header('Content-Type: text/plain; charset=utf-8');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo "Admin only.\n";
    exit;
}

$db      = getDB();
$results = [];

function tableExists(PDO $db, $table) {
    try {
        $r = $db->query("SHOW TABLES LIKE '" . $table . "'")->fetchAll();
        return !empty($r);
    } catch (Exception $e) {
        return false;
    }
}

function addCol(PDO $db, $table, $col, $definition, &$results) {
    try {
        $check = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'")->fetch();
        if ($check) {
            $results[] = "SKIP  {$table}.{$col} -- already exists";
            return;
        }
    } catch (Exception $e) {
        $results[] = "ERR   {$table}.{$col} -- table missing or unreadable: " . $e->getMessage();
        return;
    }
    try {
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$definition}");
        $results[] = "OK    {$table}.{$col} -- added";
    } catch (Exception $e) {
        $results[] = "ERR   {$table}.{$col} -- " . $e->getMessage();
    }
}

function addIdx(PDO $db, $table, $name, $cols, $unique, &$results) {
    try {
        $check = $db->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$name}'")->fetch();
        if ($check) {
            $results[] = "SKIP  INDEX {$name} -- already exists";
            return;
        }
    } catch (Exception $e) {
        $results[] = "ERR   INDEX {$name} -- table missing: " . $e->getMessage();
        return;
    }
    $type = $unique ? 'UNIQUE INDEX' : 'INDEX';
    try {
        $db->exec("CREATE {$type} `{$name}` ON `{$table}` ({$cols})");
        $results[] = "OK    INDEX {$name} -- created";
    } catch (Exception $e) {
        $results[] = "ERR   INDEX {$name} -- " . $e->getMessage();
    }
}

function addFK(PDO $db, $table, $name, $col, $ref, &$results) {
    try {
        $check = $db->prepare("
            SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
        ");
        $check->execute([$table, $name]);
        if ($check->fetch()) {
            $results[] = "SKIP  FK {$name} -- already exists";
            return;
        }
    } catch (Exception $e) {
        $results[] = "ERR   FK {$name} -- constraint check failed: " . $e->getMessage();
        return;
    }
    try {
        $db->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$col}`) REFERENCES {$ref} ON DELETE SET NULL");
        $results[] = "OK    FK {$name} -- created";
    } catch (Exception $e) {
        $results[] = "ERR   FK {$name} -- " . $e->getMessage();
    }
}

// ===============================================================================
// 1. ALTER tasks — Purchase-specific columns
// ===============================================================================
$results[] = "-- tasks table --";

addCol($db, 'tasks', 'task_type',        "ENUM('general','purchase') NOT NULL DEFAULT 'general' AFTER status", $results);
addCol($db, 'tasks', 'task_number',      "VARCHAR(60) NULL AFTER task_type", $results);
addCol($db, 'tasks', 'vendor_id',        "INT NULL AFTER invoice_id", $results);
addCol($db, 'tasks', 'vendor_location_id', "INT NULL AFTER vendor_id", $results);
addCol($db, 'tasks', 'procurement_mode', "ENUM('on_route','asap','truck_kit') NULL AFTER vendor_location_id", $results);
addCol($db, 'tasks', 'requested_date',   "DATE NULL AFTER procurement_mode", $results);
addCol($db, 'tasks', 'scheduled_date',   "DATE NULL AFTER requested_date", $results);
addCol($db, 'tasks', 'purchase_status',  "ENUM('requested','assigned','en_route','at_vendor','purchased','delivered','verified') NULL AFTER scheduled_date", $results);

// GPS
addCol($db, 'tasks', 'departure_lat',    "DECIMAL(10,8) NULL AFTER purchase_status", $results);
addCol($db, 'tasks', 'departure_lng',    "DECIMAL(11,8) NULL AFTER departure_lat", $results);
addCol($db, 'tasks', 'arrival_lat',      "DECIMAL(10,8) NULL AFTER departure_lng", $results);
addCol($db, 'tasks', 'arrival_lng',      "DECIMAL(11,8) NULL AFTER arrival_lat", $results);
addCol($db, 'tasks', 'delivery_lat',     "DECIMAL(10,8) NULL AFTER arrival_lng", $results);
addCol($db, 'tasks', 'delivery_lng',     "DECIMAL(11,8) NULL AFTER delivery_lat", $results);

// Timestamps
addCol($db, 'tasks', 'started_at',       "TIMESTAMP NULL AFTER delivery_lng", $results);
addCol($db, 'tasks', 'arrived_at',       "TIMESTAMP NULL AFTER started_at", $results);
addCol($db, 'tasks', 'purchased_at',     "TIMESTAMP NULL AFTER arrived_at", $results);
addCol($db, 'tasks', 'delivered_at',     "TIMESTAMP NULL AFTER purchased_at", $results);
addCol($db, 'tasks', 'verified_at',      "TIMESTAMP NULL AFTER delivered_at", $results);

// Cost
addCol($db, 'tasks', 'estimated_total',  "DECIMAL(10,2) NULL AFTER verified_at", $results);
addCol($db, 'tasks', 'actual_total',     "DECIMAL(10,2) NULL AFTER estimated_total", $results);
addCol($db, 'tasks', 'expense_id',       "INT NULL AFTER actual_total", $results);

// Time
addCol($db, 'tasks', 'drive_time_minutes', "INT NULL AFTER expense_id", $results);
addCol($db, 'tasks', 'shop_time_minutes',  "INT NULL AFTER drive_time_minutes", $results);
addCol($db, 'tasks', 'total_time_minutes', "INT NULL AFTER shop_time_minutes", $results);

// Indexes
addIdx($db, 'tasks', 'idx_task_type',            'task_type',        false, $results);
addIdx($db, 'tasks', 'idx_task_number',           'task_number',      false, $results);
addIdx($db, 'tasks', 'idx_task_vendor',           'vendor_id',        false, $results);
addIdx($db, 'tasks', 'idx_task_purchase_status',  'purchase_status',  false, $results);
addIdx($db, 'tasks', 'idx_task_scheduled_date',   'scheduled_date',   false, $results);
addIdx($db, 'tasks', 'uq_task_number',            'task_number',      true,  $results);

// Foreign keys (skip gracefully if referenced tables missing)
addFK($db, 'tasks', 'fk_task_vendor',     'vendor_id',          'vendors(id)',          $results);
addFK($db, 'tasks', 'fk_task_vendor_loc', 'vendor_location_id', 'vendor_locations(id)', $results);
addFK($db, 'tasks', 'fk_task_expense',    'expense_id',         'expenses(id)',         $results);

// ===============================================================================
// 2. CREATE task_items
// ===============================================================================
$results[] = "\n-- task_items table --";

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS task_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            vendor_product_id INT NULL,
            product_id INT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            unit VARCHAR(50) NULL,
            estimated_unit_price DECIMAL(10,2) NULL,
            actual_unit_price DECIMAL(10,2) NULL,
            actual_total DECIMAL(10,2) NULL,
            quote_line_item_id INT NULL,
            plan_line_item_id INT NULL,
            is_purchased TINYINT(1) NOT NULL DEFAULT 0,
            is_substituted TINYINT(1) NOT NULL DEFAULT 0,
            substitution_notes TEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ti_task (task_id),
            INDEX idx_ti_vendor_product (vendor_product_id),
            INDEX idx_ti_product (product_id),
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $results[] = "OK    task_items -- created (or already exists)";
} catch (Exception $e) {
    $results[] = "ERR   task_items -- " . $e->getMessage();
}

// Add optional FKs to vendor_products and products separately (non-fatal if tables missing)
if (tableExists($db, 'vendor_products')) {
    try {
        $fkCheck = $db->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'task_items' AND CONSTRAINT_NAME = 'fk_ti_vendor_product'");
        $fkCheck->execute();
        if (!$fkCheck->fetch()) {
            $db->exec("ALTER TABLE task_items ADD CONSTRAINT fk_ti_vendor_product FOREIGN KEY (vendor_product_id) REFERENCES vendor_products(id) ON DELETE SET NULL");
            $results[] = "OK    FK fk_ti_vendor_product -- added";
        } else {
            $results[] = "SKIP  FK fk_ti_vendor_product -- already exists";
        }
    } catch (Exception $e) {
        $results[] = "ERR   FK fk_ti_vendor_product -- " . $e->getMessage();
    }
} else {
    $results[] = "SKIP  FK fk_ti_vendor_product -- vendor_products table missing";
}

if (tableExists($db, 'products')) {
    try {
        $fkCheck = $db->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'task_items' AND CONSTRAINT_NAME = 'fk_ti_product'");
        $fkCheck->execute();
        if (!$fkCheck->fetch()) {
            $db->exec("ALTER TABLE task_items ADD CONSTRAINT fk_ti_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL");
            $results[] = "OK    FK fk_ti_product -- added";
        } else {
            $results[] = "SKIP  FK fk_ti_product -- already exists";
        }
    } catch (Exception $e) {
        $results[] = "ERR   FK fk_ti_product -- " . $e->getMessage();
    }
} else {
    $results[] = "SKIP  FK fk_ti_product -- products table missing";
}

// ===============================================================================
// 3. ALTER vendor_locations — Enhanced fields
// ===============================================================================
$results[] = "\n-- vendor_locations table --";

if (tableExists($db, 'vendor_locations')) {
    addCol($db, 'vendor_locations', 'phone',          "VARCHAR(20) NULL AFTER city", $results);
    addCol($db, 'vendor_locations', 'hours_weekday',  "VARCHAR(20) NULL AFTER phone", $results);
    addCol($db, 'vendor_locations', 'hours_saturday', "VARCHAR(20) NULL AFTER hours_weekday", $results);
    addCol($db, 'vendor_locations', 'hours_sunday',   "VARCHAR(20) NULL AFTER hours_saturday", $results);
    addCol($db, 'vendor_locations', 'notes',          "TEXT NULL AFTER hours_sunday", $results);
    addCol($db, 'vendor_locations', 'is_preferred',   "TINYINT(1) NOT NULL DEFAULT 0 AFTER notes", $results);
} else {
    $results[] = "SKIP  vendor_locations -- table does not exist";
}

// ===============================================================================
// 4. ALTER expenses — Purchase task linkage
// ===============================================================================
$results[] = "\n-- expenses table --";

if (tableExists($db, 'expenses')) {
    addCol($db, 'expenses', 'purchase_task_id', "INT NULL AFTER job_id", $results);
    addIdx($db, 'expenses', 'idx_exp_purchase_task', 'purchase_task_id', false, $results);
    addFK($db, 'expenses', 'fk_exp_purchase_task', 'purchase_task_id', 'tasks(id)', $results);
} else {
    $results[] = "SKIP  expenses -- table does not exist (run migration 980 first)";
}

// ===============================================================================
// 5. ALTER job_time_entries — Purchase time type
// ===============================================================================
$results[] = "\n-- job_time_entries table --";

if (tableExists($db, 'job_time_entries')) {
    addCol($db, 'job_time_entries', 'purchase_task_id', "INT NULL AFTER visit_id", $results);
    addCol($db, 'job_time_entries', 'time_type', "ENUM('job','purchase','drive') NOT NULL DEFAULT 'job' AFTER purchase_task_id", $results);
    addIdx($db, 'job_time_entries', 'idx_jte_purchase_task', 'purchase_task_id', false, $results);
} else {
    $results[] = "SKIP  job_time_entries -- table does not exist";
}

// ===============================================================================
// Done
// ===============================================================================
echo "Migration 970 -- Purchase Task System\n";
echo str_repeat('=', 60) . "\n\n";
echo implode("\n", $results) . "\n\n";
echo "Done.\n";
