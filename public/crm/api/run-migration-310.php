<?php
/**
 * One-time migration runner — Migration 310
 * PIPEDA Privacy Compliance: DSAR + Retention Settings
 *
 * Run once at: https://mowology.ca/crm/api/run-migration-310.php
 * Requires CRM admin login.
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 7; $__i++) {
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
    $__dir = dirname($__dir);
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

header('Content-Type: text/plain; charset=utf-8');
$db = getDB();

$statements = [
    // 1 — privacy_requests table
    "CREATE TABLE IF NOT EXISTS privacy_requests (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        contact_id      INT NULL,
        request_type    ENUM('access','deletion','correction') NOT NULL,
        status          ENUM('pending','in_progress','completed','rejected') NOT NULL DEFAULT 'pending',
        requester_name  VARCHAR(255) NOT NULL,
        requester_email VARCHAR(255) NOT NULL,
        notes           TEXT NULL,
        handled_by      INT NULL,
        completed_at    TIMESTAMP NULL,
        export_path     VARCHAR(500) NULL,
        created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_pr_status  (status),
        INDEX idx_pr_contact (contact_id),
        INDEX idx_pr_email   (requester_email),
        INDEX idx_pr_created (created_at),
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
        FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // 2 — data_retention_settings table
    "CREATE TABLE IF NOT EXISTS data_retention_settings (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        data_type      VARCHAR(50) NOT NULL,
        retention_days INT NOT NULL,
        description    TEXT NULL,
        updated_by     INT NULL,
        updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_data_type (data_type),
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // 3 — Seed retention defaults
    "INSERT IGNORE INTO data_retention_settings (data_type, retention_days, description) VALUES
        ('crew_location_history', 90,  'Raw GPS location pings for crew members'),
        ('visit_gps_points',      180, 'GPS breadcrumb trail recorded during job visits'),
        ('visit_audit_log',       730, 'Immutable audit log of all visit actions (2-year legal hold)')",
];

$ok = 0; $errors = [];
foreach ($statements as $i => $sql) {
    try {
        $db->exec($sql);
        echo "OK: statement " . ($i + 1) . "\n";
        $ok++;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'already exists') !== false || stripos($msg, 'Duplicate') !== false) {
            echo "SKIP (already applied): statement " . ($i + 1) . "\n";
            $ok++;
        } else {
            echo "ERROR: statement " . ($i + 1) . " — {$msg}\n";
            $errors[] = $msg;
        }
    }
}
echo "\n--- Migration 310 complete: {$ok}/" . count($statements) . " OK, " . count($errors) . " errors ---\n";
