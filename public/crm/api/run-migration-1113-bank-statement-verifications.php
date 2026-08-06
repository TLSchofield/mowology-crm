<?php
/**
 * Run Migration 1113: Bank statement verification table
 *
 * Creates bank_statement_verifications — see the .sql file for why. Admin
 * only, idempotent.
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
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db = getDB();

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS bank_statement_verifications (
            id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            transaction_date  DATE NOT NULL,
            amount            DECIMAL(10,2) NOT NULL,
            real_count        INT UNSIGNED NOT NULL,
            verified_by       INT NULL,
            verified_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_date_amount (transaction_date, amount)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo json_encode(['success' => true, 'migration' => '1113']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
