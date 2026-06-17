<?php
/**
 * Migration 995 — create etransfer_notifications table.
 * Mirrors database/migrations/995_etransfer_notifications.sql. Admin-only, idempotent.
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
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db = getDB();

$sql = "CREATE TABLE etransfer_notifications (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mailbox             VARCHAR(64)     NOT NULL,
    dedup_key           VARCHAR(255)    NOT NULL,
    reference_number    VARCHAR(64)     DEFAULT NULL,
    message_id          VARCHAR(255)    DEFAULT NULL,
    sender_name         VARCHAR(160)    DEFAULT NULL,
    amount              DECIMAL(10,2)   DEFAULT NULL,
    memo                TEXT            DEFAULT NULL,
    invoice_hint        VARCHAR(32)     DEFAULT NULL,
    transfer_type       VARCHAR(20)     NOT NULL DEFAULT 'unknown',
    email_subject       VARCHAR(255)    DEFAULT NULL,
    email_date          DATETIME        DEFAULT NULL,
    matched_invoice_id  INT             DEFAULT NULL,
    match_method        VARCHAR(20)     DEFAULT NULL,
    match_confidence    VARCHAR(10)     DEFAULT NULL,
    status              VARCHAR(20)     NOT NULL DEFAULT 'pending',
    recorded_invoice_id INT             DEFAULT NULL,
    recorded_by         INT             DEFAULT NULL,
    created_at          DATETIME        NOT NULL,
    processed_at        DATETIME        DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dedup (dedup_key),
    KEY idx_status (status),
    KEY idx_matched_invoice (matched_invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

try {
    $db->exec($sql);
    echo json_encode(['ok' => true, 'migration' => '995', 'result' => 'created etransfer_notifications']);
} catch (PDOException $e) {
    $already = stripos($e->getMessage(), 'already exists') !== false;
    echo json_encode([
        'ok'        => $already,
        'migration' => '995',
        'result'    => $already ? 'already exists' : 'error',
        'msg'       => $already ? null : $e->getMessage(),
    ]);
}
