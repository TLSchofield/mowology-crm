<?php
/**
 * Run Migration 961 — Conflict Resolution Stops (claimed GPS time ranges)
 *
 * Adds:
 *   - conflict_resolution_stops table (tracks which GPS dwell time ranges were claimed by each resolution)
 *
 * This prevents resolved dwell stops from appearing as available on other visits' conflict resolution pages,
 * and prevents resolved conflicts from re-appearing in route reconciliation when unclaimed stops exist.
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
session_write_close();
requirePermission('admin');

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$results = [];

$statements = [

    // 1. Tracks which specific GPS dwell time ranges were claimed by a resolution
    "CREATE TABLE IF NOT EXISTS conflict_resolution_stops (
        id INT AUTO_INCREMENT PRIMARY KEY,
        resolution_id INT NOT NULL,
        truck_id INT NOT NULL,
        stop_start_epoch INT NOT NULL,
        stop_end_epoch INT NOT NULL,
        stop_date DATE NOT NULL,
        property_lat DECIMAL(10,7) NULL,
        property_lng DECIMAL(10,7) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_resolution (resolution_id),
        INDEX idx_date_truck (stop_date, truck_id),
        INDEX idx_epochs (stop_start_epoch, stop_end_epoch)
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

echo "Migration 961 — Conflict Resolution Stops\n\n";
echo implode("\n", $results) . "\n\nDone.";
