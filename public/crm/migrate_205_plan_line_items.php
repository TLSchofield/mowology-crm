<?php
/**
 * Migration 205: Plan Line Items
 * Run once, then delete this file.
 */
require_once dirname(__DIR__) . '/loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePermission('admin.access');

$db = getDB();
$results = [];

// 1. Create plan_line_items table
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS plan_line_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_id INT NOT NULL,
            quote_line_item_id INT NULL,
            service_type VARCHAR(100) NOT NULL,
            description TEXT,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
            unit_type ENUM('each','sqft','hour','visit','season','month') DEFAULT 'visit',
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(10,2) NOT NULL DEFAULT 0,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (plan_id) REFERENCES job_plans(id) ON DELETE CASCADE,
            INDEX idx_plan_id (plan_id),
            INDEX idx_quote_line_item (quote_line_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $results[] = "OK: plan_line_items table created (or already exists)";
} catch (PDOException $e) {
    $results[] = "ERROR creating plan_line_items: " . $e->getMessage();
}

// 2. Add plan_id column to quote_line_items
try {
    $check = $db->query("SHOW COLUMNS FROM quote_line_items LIKE 'plan_id'");
    if ($check->rowCount() === 0) {
        $db->exec("ALTER TABLE quote_line_items ADD COLUMN plan_id INT NULL AFTER quote_id");
        $db->exec("ALTER TABLE quote_line_items ADD INDEX idx_plan_id (plan_id)");
        $results[] = "OK: Added plan_id column to quote_line_items";
    } else {
        $results[] = "SKIP: quote_line_items.plan_id already exists";
    }
} catch (PDOException $e) {
    $results[] = "ERROR adding plan_id to quote_line_items: " . $e->getMessage();
}

// 3. Verify
try {
    $cols = $db->query("SHOW COLUMNS FROM plan_line_items")->fetchAll(PDO::FETCH_COLUMN);
    $results[] = "VERIFY: plan_line_items columns: " . implode(', ', $cols);
} catch (PDOException $e) {
    $results[] = "VERIFY ERROR: " . $e->getMessage();
}

header('Content-Type: text/plain');
echo "Migration 205: Plan Line Items\n";
echo str_repeat('=', 50) . "\n\n";
foreach ($results as $r) {
    echo $r . "\n";
}
echo "\nDone. Delete this file after verifying.";
