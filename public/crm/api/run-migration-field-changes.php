<?php
/**
 * Run Migration: Field Changes (Audit Trail)
 *
 * Creates the field_changes table for tracking field-level changes
 * on quotes, contacts, job_plans, and invoices.
 *
 * MySQL 5.7-compatible: uses per-column try/catch.
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
session_write_close();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db      = getDB();
$results = [];

// ── Helper ────────────────────────────────────────────────────────────────────
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

// ── Create field_changes table ───────────────────────────────────────────────
tryExec($db, "
    CREATE TABLE IF NOT EXISTS field_changes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entity_type VARCHAR(50) NOT NULL COMMENT 'quote, contact, job_plan, invoice, task',
        entity_id INT NOT NULL,
        field_name VARCHAR(100) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        changed_by INT NULL,
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_fc_entity (entity_type, entity_id),
        INDEX idx_fc_changed_by (changed_by),
        INDEX idx_fc_changed_at (changed_at),
        FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", 'create field_changes table', $results);

echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT);
