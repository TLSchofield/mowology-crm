<?php
/**
 * Run Migration 960 — Conflict Resolution Tracking
 *
 * Adds:
 *   - conflict_resolutions table (tracks resolved/dismissed route reconciliation conflicts)
 *
 * Admin-only, idempotent (re-runnable safely).
 */
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
requirePermission('admin');

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$results = [];

$statements = [

    // 1. Conflict resolution tracking table
    "CREATE TABLE IF NOT EXISTS conflict_resolutions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        visit_id INT NOT NULL,
        visit_date DATE NOT NULL,
        resolution_type ENUM('time_entry_created','dismissed','noted','sms_sent') NOT NULL,
        resolved_by INT NOT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_visit (visit_id),
        INDEX idx_date (visit_date),
        INDEX idx_type_date (resolution_type, visit_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

];

foreach ($statements as $i => $sql) {
    try {
        $db->exec($sql);
        $results[] = "OK  [$i] " . substr(trim($sql), 0, 80) . '...';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false ||
            strpos($e->getMessage(), '1060') !== false ||
            strpos($e->getMessage(), "already exists") !== false) {
            $results[] = "SKIP[$i] Already exists — " . substr(trim($sql), 0, 60);
        } else {
            $results[] = "ERR [$i] " . $e->getMessage() . ' | SQL: ' . substr(trim($sql), 0, 80);
        }
    }
}

echo "Migration 960 — Conflict Resolution Tracking\n\n";
echo implode("\n", $results) . "\n\nDone.";
