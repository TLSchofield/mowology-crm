<?php
/**
 * Run Migration 1396: texts from numbers that are not a customer yet (text_unmatched, text_personal).
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
        CREATE TABLE IF NOT EXISTS text_unmatched (
          id INT AUTO_INCREMENT PRIMARY KEY,
          phone10 CHAR(10) NOT NULL,
          message_key VARCHAR(191) NOT NULL,
          direction VARCHAR(10) NOT NULL,
          snippet TEXT NULL,
          sent_at DATETIME NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_tu_key (message_key),
          INDEX idx_tu_phone (phone10, sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS text_personal (
          number_hash CHAR(64) NOT NULL PRIMARY KEY,
          marked_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo json_encode(['success' => true, 'migration' => '1396']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
