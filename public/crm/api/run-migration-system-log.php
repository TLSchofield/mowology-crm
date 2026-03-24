<?php
/**
 * One-time Migration — System Log Table
 * Creates system_log for internal error/warning/info recording.
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

// ── Create system_log table ───────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS system_log (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        level      ENUM('error','warning','info') NOT NULL DEFAULT 'info',
        source     VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'stripe, sms, pdf, auth, etc.',
        message    TEXT NOT NULL,
        context    TEXT NULL COMMENT 'JSON key/value context',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_level      (level),
        INDEX idx_source     (source),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
      COMMENT='Internal system error and event log'");
    $results[] = ['step' => 'system_log table', 'status' => 'ok'];
} catch (PDOException $e) {
    $results[] = ['step' => 'system_log table', 'status' => 'error', 'msg' => $e->getMessage()];
}

echo json_encode(['migration' => 'system_log', 'results' => $results, 'done' => true], JSON_PRETTY_PRINT);
