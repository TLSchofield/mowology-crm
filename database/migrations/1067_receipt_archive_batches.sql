-- Migration 1067: Audit log of receipt export/archive runs
-- Run on production via /crm/api/run-migration-1066-receipt-archive.php (admin only).
--
-- One row per ReceiptArchiveService::run() invocation (cron or manual). Records what
-- was bundled, where it was emailed, and whether the off-disk purge completed — the
-- auditable trail for the Receipt Archival & Export feature (migration 1066).
--
-- MySQL 5.7-compatible. No FK to media_assets (rows point back via archive_batch_id).

CREATE TABLE IF NOT EXISTS receipt_archive_batches (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
