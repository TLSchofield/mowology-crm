<?php
/**
 * Migration 993 — Create idempotency_keys table
 * DELETE THIS FILE after running on production.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    requirePermission('settings.edit');

    $db  = getDB();
    $log = [];

    $steps = [
        [
            'sql' => "CREATE TABLE idempotency_keys (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                key_hash      CHAR(64)        NOT NULL,
                user_id       INT             NOT NULL,
                endpoint      VARCHAR(80)     NOT NULL,
                action        VARCHAR(20)     NOT NULL,
                response_json MEDIUMTEXT      NOT NULL,
                created_at    DATETIME        NOT NULL,
                expires_at    DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_key_hash   (key_hash),
                KEY           idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            'desc' => 'Create idempotency_keys table',
        ],
    ];

    foreach ($steps as $step) {
        try {
            $db->exec($step['sql']);
            $log[] = '✓ ' . $step['desc'];
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'already exists') !== false) {
                $log[] = '— ' . $step['desc'] . ' (already exists — skipped)';
            } else {
                $log[] = '✗ ' . $step['desc'] . ': ' . $msg;
            }
        }
    }

    echo json_encode(['ok' => true, 'log' => $log]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
