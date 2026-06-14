<?php
/**
 * One-time Migration Runner — Migration 901
 * Run once via authenticated POST, then DELETE this file.
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db = getDB();
$results = [];

// ── Step 1: Add columns to job_visits individually (skip if already present) ──
$colDefs = [
    'labor_rate_snapshot'      => "DECIMAL(8,2)  NULL COMMENT 'users.hourly_rate at time of completion'",
    'labor_cost_snapshot'      => "DECIMAL(10,2) NULL COMMENT 'labor_rate_snapshot x (actual_duration_minutes / 60)'",
    'drive_time_minutes'       => "INT           NULL COMMENT 'Estimated drive time to this stop'",
    'drive_cost_snapshot'      => "DECIMAL(10,2) NULL COMMENT 'drive_time_minutes x cost_per_minute at completion'",
    'rollover_count'           => "TINYINT       NOT NULL DEFAULT 0 COMMENT 'How many times this visit rolled forward'",
    'original_scheduled_date'  => "DATE          NULL COMMENT 'Original date before rollover'",
];
foreach ($colDefs as $col => $def) {
    $exists = $db->query("SHOW COLUMNS FROM job_visits LIKE '$col'")->fetch();
    if (!$exists) {
        try {
            $db->exec("ALTER TABLE job_visits ADD COLUMN $col $def");
            $results[] = ['step' => "job_visits.$col", 'status' => 'added'];
        } catch (PDOException $e) {
            $results[] = ['step' => "job_visits.$col", 'status' => 'error', 'msg' => $e->getMessage()];
        }
    } else {
        $results[] = ['step' => "job_visits.$col", 'status' => 'already_exists'];
    }
}

// ── Step 2: Create visit_margin_snapshots table ────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS visit_margin_snapshots (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        visit_id         INT          NOT NULL,
        plan_id          INT          NULL,
        contact_id       INT          NULL,
        property_id      INT          NULL,
        visit_date       DATE         NULL,
        service_type     VARCHAR(60)  NULL,
        quoted_amount    DECIMAL(10,2) NULL,
        labor_cost       DECIMAL(10,2) NULL,
        material_cost    DECIMAL(10,2) NULL,
        drive_cost       DECIMAL(10,2) NULL,
        other_cost       DECIMAL(10,2) NULL,
        gross_margin     DECIMAL(10,2) NULL,
        margin_pct       DECIMAL(5,2)  NULL,
        computed_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        notes            VARCHAR(255) NULL,
        UNIQUE KEY uk_visit (visit_id),
        INDEX idx_visit_date    (visit_date),
        INDEX idx_contact       (contact_id),
        INDEX idx_service_type  (service_type),
        INDEX idx_margin_pct    (margin_pct)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $results[] = ['step' => 'visit_margin_snapshots table', 'status' => 'created_or_exists'];
} catch (PDOException $e) {
    $results[] = ['step' => 'visit_margin_snapshots table', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── Step 3: ops_settings drive cost rate ──────────────────────────────────────
$opsCheck = $db->query("SHOW TABLES LIKE 'ops_settings'")->fetch();
if ($opsCheck) {
    try {
        $db->exec("INSERT INTO ops_settings (setting_key, setting_value, description)
            VALUES ('drive_cost_per_minute', '0.75', 'Vehicle cost per driving minute in dollars')
            ON DUPLICATE KEY UPDATE setting_key = setting_key");
        $results[] = ['step' => 'ops_settings drive_cost_per_minute', 'status' => 'set'];
    } catch (PDOException $e) {
        $results[] = ['step' => 'ops_settings drive_cost_per_minute', 'status' => 'error', 'msg' => $e->getMessage()];
    }
} else {
    $results[] = ['step' => 'ops_settings drive_cost_per_minute', 'status' => 'skipped — ops_settings table not found'];
}

echo json_encode(['migration' => '901', 'results' => $results, 'done' => true], JSON_PRETTY_PRINT);
