-- ============================================================================
-- Migration 502: Visit Photos System Upgrade
-- ============================================================================
-- Adds variant paths, soft-delete, sort order, and 'additional' photo type
-- to visit_photos.
-- Creates visit_share_tokens for the client gallery system.
-- MySQL 5.7+ compatible. Uses PROCEDURE to guard each ADD COLUMN idempotently.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Step 1: Add new columns to visit_photos (idempotent via stored procedure) ─

DROP PROCEDURE IF EXISTS mw_migrate_502;

DELIMITER $$
CREATE PROCEDURE mw_migrate_502()
BEGIN
    -- sort_order
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'visit_photos'
          AND COLUMN_NAME  = 'sort_order'
    ) THEN
        ALTER TABLE visit_photos ADD COLUMN sort_order INT DEFAULT 0 AFTER caption;
    END IF;

    -- deleted_at
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'visit_photos'
          AND COLUMN_NAME  = 'deleted_at'
    ) THEN
        ALTER TABLE visit_photos ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER sort_order;
    END IF;

    -- thumb_path
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'visit_photos'
          AND COLUMN_NAME  = 'thumb_path'
    ) THEN
        ALTER TABLE visit_photos ADD COLUMN thumb_path VARCHAR(500) NULL DEFAULT NULL AFTER deleted_at;
    END IF;

    -- grid_path
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'visit_photos'
          AND COLUMN_NAME  = 'grid_path'
    ) THEN
        ALTER TABLE visit_photos ADD COLUMN grid_path VARCHAR(500) NULL DEFAULT NULL AFTER thumb_path;
    END IF;

    -- view_path
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'visit_photos'
          AND COLUMN_NAME  = 'view_path'
    ) THEN
        ALTER TABLE visit_photos ADD COLUMN view_path VARCHAR(500) NULL DEFAULT NULL AFTER grid_path;
    END IF;

    -- ── Step 2: Extend ENUM to include 'additional' ──────────────────────────
    -- Safe to run repeatedly; MODIFY is idempotent if the ENUM already contains
    -- all listed values.
    ALTER TABLE visit_photos
        MODIFY COLUMN photo_type
            ENUM('before','after','additional','during','issue','other')
            DEFAULT 'after';

    -- ── Step 3: Add soft-delete index (ignore if already exists) ────────────
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'visit_photos'
          AND INDEX_NAME   = 'idx_deleted'
    ) THEN
        ALTER TABLE visit_photos ADD INDEX idx_deleted (deleted_at);
    END IF;

END$$
DELIMITER ;

CALL mw_migrate_502();
DROP PROCEDURE IF EXISTS mw_migrate_502;

-- ── Step 4: visit_share_tokens — client gallery access tokens ────────────────
-- One active token per visit (UNIQUE KEY on visit_id).
-- Raw token (64-char hex) given to client in URL; token_hash = SHA-256(raw).
-- Server verifies: hash('sha256', incoming_token) == token_hash.
CREATE TABLE IF NOT EXISTS visit_share_tokens (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    visit_id         INT NOT NULL,
    token_hash       CHAR(64) NOT NULL    COMMENT 'SHA-256 hex of the 32-byte raw token',
    token_preview    VARCHAR(12) NOT NULL COMMENT 'First 8 chars of raw token (display only)',
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by       INT NULL,
    expires_at       TIMESTAMP NULL       COMMENT 'NULL = never expires',
    revoked_at       TIMESTAMP NULL,
    last_accessed_at TIMESTAMP NULL,
    access_count     INT DEFAULT 0,

    UNIQUE KEY uq_visit (visit_id),
    INDEX idx_token_hash (token_hash),

    FOREIGN KEY (visit_id)   REFERENCES job_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Verification queries (run manually to confirm) ───────────────────────────
-- SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
--   WHERE TABLE_NAME = 'visit_photos' AND TABLE_SCHEMA = DATABASE()
--   ORDER BY ORDINAL_POSITION;
-- SHOW CREATE TABLE visit_share_tokens\G
