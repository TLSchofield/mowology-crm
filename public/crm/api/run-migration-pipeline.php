<?php
/**
 * Run Migration: Sales Pipeline
 *
 * Creates pipeline_stages table and adds pipeline columns to quotes.
 */
declare(strict_types=1);
header('Content-Type: application/json');

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
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db      = getDB();
$results = [];

function tryExec(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'ok'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate')) {
            $results[] = ['step' => $label, 'status' => 'already exists'];
        } else {
            $results[] = ['step' => $label, 'status' => 'error', 'msg' => $msg];
        }
    }
}

function tryAlter(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'added'];
    } catch (PDOException $e) {
        if ($e->getCode() === '42S21' || str_contains($e->getMessage(), 'Duplicate column')) {
            $results[] = ['step' => $label, 'status' => 'already exists'];
        } else {
            $results[] = ['step' => $label, 'status' => 'error', 'msg' => $e->getMessage()];
        }
    }
}

// ── Create pipeline_stages table ─────────────────────────────────────────────
tryExec($db, "
    CREATE TABLE IF NOT EXISTS pipeline_stages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stage_key VARCHAR(50) NOT NULL UNIQUE,
        stage_label VARCHAR(100) NOT NULL,
        stage_order INT DEFAULT 0,
        stage_color VARCHAR(10) DEFAULT '#6B7280',
        default_probability INT DEFAULT 0 COMMENT 'Default win % for this stage',
        description TEXT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", 'create pipeline_stages table', $results);

// Seed default stages (skip if any exist)
$existing = (int)$db->query("SELECT COUNT(*) FROM pipeline_stages")->fetchColumn();
if ($existing === 0) {
    $db->exec("
        INSERT INTO pipeline_stages (stage_key, stage_label, stage_order, stage_color, default_probability) VALUES
            ('prospecting',  'Prospecting',    1, '#6B7280', 10),
            ('qualification','Qualification',  2, '#3B82F6', 20),
            ('proposal',     'Proposal Sent',  3, '#F59E0B', 50),
            ('negotiation',  'Negotiation',    4, '#F97316', 70),
            ('closed_won',   'Closed Won',     5, '#2D8659', 100),
            ('closed_lost',  'Closed Lost',    6, '#DC2626', 0)
    ");
    $results[] = ['step' => 'seed default stages', 'status' => 'ok'];
} else {
    $results[] = ['step' => 'seed default stages', 'status' => 'already exists'];
}

// ── Add columns to quotes ────────────────────────────────────────────────────
tryAlter($db, "ALTER TABLE `quotes` ADD COLUMN `probability` INT UNSIGNED DEFAULT NULL COMMENT 'Win probability 0-100' AFTER `status`", 'quotes.probability', $results);
tryAlter($db, "ALTER TABLE `quotes` ADD COLUMN `expected_close_date` DATE NULL COMMENT 'Expected close date' AFTER `probability`", 'quotes.expected_close_date', $results);
tryAlter($db, "ALTER TABLE `quotes` ADD COLUMN `pipeline_stage` VARCHAR(50) DEFAULT NULL COMMENT 'Pipeline stage key' AFTER `expected_close_date`", 'quotes.pipeline_stage', $results);
tryAlter($db, "ALTER TABLE `quotes` ADD COLUMN `stage_entered_at` DATETIME NULL COMMENT 'When current stage was entered' AFTER `pipeline_stage`", 'quotes.stage_entered_at', $results);
tryAlter($db, "ALTER TABLE `quotes` ADD COLUMN `lost_reason` VARCHAR(255) NULL COMMENT 'Why quote was declined' AFTER `stage_entered_at`", 'quotes.lost_reason', $results);
tryAlter($db, "ALTER TABLE `quotes` ADD COLUMN `deal_source` VARCHAR(100) NULL COMMENT 'How this deal originated' AFTER `lost_reason`", 'quotes.deal_source', $results);

echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT);
