<?php
/**
 * Run Migration 1395: Penny's revisit list (penny_revisits).
 * Admin only, idempotent, confirm-then-POST via migrationRunnerGuard(). See the .sql file for why.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
require_once APP_ROOT . '/Core/MigrationRunnerGuard.php';
migrationRunnerGuard(); // admin only; GET shows a confirm button, the run needs POST + CSRF
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db = getDB();

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS penny_revisits (
          id INT AUTO_INCREMENT PRIMARY KEY,
          transaction_id INT NOT NULL,
          trigger_kind VARCHAR(20) NOT NULL DEFAULT 'scan',
          trigger_ref VARCHAR(100) NULL,
          payee_key VARCHAR(160) NOT NULL DEFAULT '',
          from_account_id INT NULL,
          to_account_id INT NOT NULL,
          tier CHAR(1) NOT NULL,
          source VARCHAR(20) NOT NULL DEFAULT '',
          reason VARCHAR(500) NOT NULL DEFAULT '',
          amount DECIMAL(10,2) NULL,
          line_date DATE NULL,
          status VARCHAR(12) NOT NULL DEFAULT 'proposed',
          decided_by INT NULL,
          decided_at DATETIME NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_pr_line_target (transaction_id, to_account_id, trigger_kind),
          INDEX idx_pr_status_tier (status, tier),
          INDEX idx_pr_payee (payee_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo json_encode(['success' => true, 'migration' => '1395']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
