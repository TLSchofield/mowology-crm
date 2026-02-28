-- ============================================================================
-- Migration 502: Visit Photos System Upgrade
-- ============================================================================
-- Adds variant paths, soft-delete, sort order, and 'additional' photo type
-- to visit_photos.
-- Creates visit_share_tokens for the client gallery system.
-- MySQL 5.7+ compatible.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Step 1: Add new columns to visit_photos ──────────────────────────────────
-- Run outside any transaction: DDL auto-commits in MySQL.

ALTER TABLE visit_photos
    ADD COLUMN IF NOT EXISTS sort_order INT DEFAULT 0 AFTER caption,
    ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL AFTER sort_order,
    ADD COLUMN IF NOT EXISTS thumb_path VARCHAR(500) NULL DEFAULT NULL AFTER deleted_at,
    ADD COLUMN IF NOT EXISTS grid_path  VARCHAR(500) NULL DEFAULT NULL AFTER thumb_path,
    ADD COLUMN IF NOT EXISTS view_path  VARCHAR(500) NULL DEFAULT NULL AFTER grid_path;

-- ── Step 2: Add 'additional' to photo_type ENUM ──────────────────────────────
-- Appending to end of ENUM is a metadata-only change in MySQL (no table rebuild).
ALTER TABLE visit_photos
    MODIFY COLUMN photo_type
        ENUM('before','after','additional','during','issue','other')
        DEFAULT 'after';

-- ── Step 3: Add index for soft-delete filter ─────────────────────────────────
-- Best-effort: ignore error if it already exists (MySQL 5.7 has no CREATE INDEX IF NOT EXISTS).
ALTER TABLE visit_photos ADD INDEX idx_deleted (deleted_at);

-- ── Step 4: visit_share_tokens — client gallery access tokens ────────────────
-- One active token per visit.
-- Raw token (64-char hex) given to client in URL; token_hash = SHA-256(raw).
-- Server verifies: hash('sha256', incoming_token) == token_hash.
CREATE TABLE IF NOT EXISTS visit_share_tokens (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    visit_id        INT NOT NULL,
    token_hash      CHAR(64) NOT NULL    COMMENT 'SHA-256 hex of the 32-byte raw token',
    token_preview   VARCHAR(12) NOT NULL COMMENT 'First 8 chars of raw token (display only)',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by      INT NULL,
    expires_at      TIMESTAMP NULL       COMMENT 'NULL = never expires',
    revoked_at      TIMESTAMP NULL,
    last_accessed_at TIMESTAMP NULL,
    access_count    INT DEFAULT 0,

    UNIQUE KEY uq_visit (visit_id),
    INDEX idx_token_hash (token_hash),

    FOREIGN KEY (visit_id)    REFERENCES job_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by)  REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Verification queries (run manually to confirm) ───────────────────────────
-- SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
--   WHERE TABLE_NAME = 'visit_photos' AND TABLE_SCHEMA = DATABASE()
--   ORDER BY ORDINAL_POSITION;
-- SHOW CREATE TABLE visit_share_tokens\G
