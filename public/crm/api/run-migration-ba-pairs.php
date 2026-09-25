<?php
/**
 * Migration: Create ba_pairs table for Before & After pairs
 * Run once: /crm/api/run-migration-ba-pairs.php
 */

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
session_write_close();

header('Content-Type: application/json');

try {
    $db = getDB();

    $db->exec("
        CREATE TABLE IF NOT EXISTS ba_pairs (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            before_id   INT NOT NULL,
            after_id    INT NOT NULL,
            label       VARCHAR(255) NOT NULL DEFAULT '',
            service     VARCHAR(120) NOT NULL DEFAULT '',
            category    VARCHAR(60)  NOT NULL DEFAULT 'general',
            published   TINYINT(1)   NOT NULL DEFAULT 1,
            sort_order  INT          NOT NULL DEFAULT 0,
            crew        VARCHAR(60)  NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_published (published),
            INDEX idx_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    echo json_encode(['success' => true, 'message' => 'ba_pairs table created successfully']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
