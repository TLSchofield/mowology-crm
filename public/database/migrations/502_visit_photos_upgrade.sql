-- ============================================================================
-- Migration 502: Visit Photos System Upgrade
-- ============================================================================
-- Adds variant paths, soft-delete, sort order, and 'additional' photo type
-- to visit_photos. Creates visit_share_tokens for the client gallery.
--
-- IMPORTANT: Plain SQL only — no DELIMITER, no stored procedures.
-- The migration framework logs completion and will not re-run this file.
-- MySQL 5.7+ / 8.x compatible.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Step 1: Add new columns to visit_photos ──────────────────────────────────

ALTER TABLE visit_photos
    ADD COLUMN sort_order INT          NOT NULL DEFAULT 0    AFTER caption,
    ADD COLUMN deleted_at TIMESTAMP    NULL     DEFAULT NULL AFTER sort_order,
    ADD COLUMN thumb_path VARCHAR(500) NULL     DEFAULT NULL AFTER deleted_at,
    ADD COLUMN grid_path  VARCHAR(500) NULL     DEFAULT NULL AFTER thumb_path,
    ADD COLUMN view_path  VARCHAR(500) NULL     DEFAULT NULL AFTER grid_path;

-- ── Step 2: Extend ENUM to include 'additional' ──────────────────────────────
-- Adding a value to an existing ENUM is metadata-only in MySQL 8 (no rebuild).

ALTER TABLE visit_photos
    MODIFY COLUMN photo_type
        ENUM('before','after','additional','during','issue','other')
        NOT NULL DEFAULT 'after';

-- ── Step 3: Index for soft-delete filter ─────────────────────────────────────

ALTER TABLE visit_photos
    ADD INDEX idx_deleted (deleted_at);

-- ── Step 4: Client gallery share-token table ─────────────────────────────────
-- One active token per visit (enforced by UNIQUE KEY on visit_id).
-- Raw token: 64-char hex from random_bytes(32) — sent to client in URL only.
-- Stored value: SHA-256(raw) — never the raw token itself.
-- Verification: hash_equals(stored_hash, hash('sha256', $incoming))

CREATE TABLE IF NOT EXISTS visit_share_tokens (
    id               INT            NOT NULL AUTO_INCREMENT,
    visit_id         INT            NOT NULL,
    token_hash       CHAR(64)       NOT NULL COMMENT 'SHA-256 hex of the raw token',
    token_preview    VARCHAR(12)    NOT NULL COMMENT 'First 8 chars for admin display',
    created_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by       INT                NULL DEFAULT NULL,
    expires_at       TIMESTAMP          NULL DEFAULT NULL COMMENT 'NULL = never expires',
    revoked_at       TIMESTAMP          NULL DEFAULT NULL,
    last_accessed_at TIMESTAMP          NULL DEFAULT NULL,
    access_count     INT            NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    UNIQUE KEY uq_visit      (visit_id),
    INDEX      idx_token_hash (token_hash),

    CONSTRAINT fk_vst_visit FOREIGN KEY (visit_id)
        REFERENCES job_visits(id) ON DELETE CASCADE,
    CONSTRAINT fk_vst_user  FOREIGN KEY (created_by)
        REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
