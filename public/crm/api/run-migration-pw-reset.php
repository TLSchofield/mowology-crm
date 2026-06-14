<?php
/**
 * Migration: password_reset_tokens
 *
 * Creates the table used by the forgot-password flow (web + iOS app).
 * Run once via authenticated POST, then delete this file.
 *
 * POST /crm/api/run-migration-pw-reset.php
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

// ── Create password_reset_tokens ─────────────────────────────────────────────
$tableCheck = $db->query("SHOW TABLES LIKE 'password_reset_tokens'")->fetch();
if (!$tableCheck) {
    try {
        $db->exec("
            CREATE TABLE password_reset_tokens (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id    INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at    DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_token_hash (token_hash),
                KEY idx_user_id (user_id),
                KEY idx_expires_at (expires_at),
                CONSTRAINT fk_prt_user FOREIGN KEY (user_id)
                    REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $results[] = ['step' => 'password_reset_tokens', 'status' => 'created'];
    } catch (Throwable $e) {
        $results[] = ['step' => 'password_reset_tokens', 'status' => 'error', 'message' => $e->getMessage()];
    }
} else {
    $results[] = ['step' => 'password_reset_tokens', 'status' => 'already_exists'];
}

echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT);
