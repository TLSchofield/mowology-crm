<?php
/**
 * Migration 1066 + 1067 — Receipt Archival & Export.
 *
 * Idempotent + admin-only. Adds the archival pointer columns to media_assets and creates
 * the receipt_archive_batches audit table. Safe to run repeatedly.
 *
 *   media_assets: archived_at, archive_path, archive_batch_id, original_removed, thumb_path
 *                 + index idx_media_archived
 *   receipt_archive_batches: new audit table
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

$db   = getDB();
$done = [];

try {
    // ── media_assets columns (each guarded so partial prior runs are fine) ──
    $columns = [
        'archived_at'      => "ADD COLUMN archived_at DATETIME NULL DEFAULT NULL COMMENT 'When the original was moved to cold archive'",
        'archive_path'     => "ADD COLUMN archive_path VARCHAR(512) NULL DEFAULT NULL COMMENT 'APP_ROOT-relative archive ref'",
        'archive_batch_id' => "ADD COLUMN archive_batch_id INT NULL DEFAULT NULL COMMENT 'receipt_archive_batches.id'",
        'original_removed' => "ADD COLUMN original_removed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = web original deleted'",
        'thumb_path'       => "ADD COLUMN thumb_path VARCHAR(512) NULL DEFAULT NULL COMMENT 'web path of kept thumbnail'",
    ];
    foreach ($columns as $col => $ddl) {
        $exists = $db->query("SHOW COLUMNS FROM media_assets LIKE '" . $col . "'")->rowCount() > 0;
        if ($exists) { $done['media_' . $col] = 'already_present'; continue; }
        $db->exec("ALTER TABLE media_assets " . $ddl);
        $done['media_' . $col] = 'added';
    }

    // ── index ──
    $hasIdx = $db->query("SHOW INDEX FROM media_assets WHERE Key_name = 'idx_media_archived'")->rowCount() > 0;
    if (!$hasIdx) {
        $db->exec("CREATE INDEX idx_media_archived ON media_assets (archived_at)");
        $done['idx_media_archived'] = 'added';
    } else {
        $done['idx_media_archived'] = 'already_present';
    }

    // ── receipt_archive_batches table ──
    $hasTable = $db->query("SHOW TABLES LIKE 'receipt_archive_batches'")->rowCount() > 0;
    if (!$hasTable) {
        $db->exec("
            CREATE TABLE receipt_archive_batches (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                trigger_type  ENUM('cron','manual') NOT NULL,
                date_from     DATE NULL,
                date_to       DATE NULL,
                receipt_count INT NOT NULL DEFAULT 0,
                zip_count     INT NOT NULL DEFAULT 0,
                total_bytes   BIGINT NOT NULL DEFAULT 0,
                recipients    VARCHAR(1024) NULL,
                email_status  ENUM('sent','failed','skipped') NOT NULL DEFAULT 'skipped',
                purge_status  ENUM('done','partial','skipped') NOT NULL DEFAULT 'skipped',
                error         TEXT NULL,
                created_by    INT NULL,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_rab_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $done['receipt_archive_batches'] = 'created';
    } else {
        $done['receipt_archive_batches'] = 'already_present';
    }

    echo json_encode(['success' => true, 'migration' => '1066+1067', 'result' => $done]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'done_so_far' => $done]);
}
