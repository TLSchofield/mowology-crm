-- ============================================================================
-- Migration 300: Unified Media System
-- ============================================================================
-- Extends media_assets with context linking, GPS, dedup, and variant tracking.
-- Creates media_links (junction), media_variants, media_derivatives tables.
--
-- MySQL 5.7 compatible: no JSON functions, no generated columns
-- All tables use utf8mb4_general_ci to match existing FK references
-- Run each ALTER outside of a transaction (DDL auto-commits in MySQL)
-- ============================================================================

-- ============================================================================
-- 1. ALTER media_assets — add unified media system columns
-- ============================================================================

ALTER TABLE media_assets
    ADD COLUMN uuid CHAR(36) NULL AFTER id,
    ADD COLUMN sha256 CHAR(64) NULL COMMENT 'SHA-256 hash for dedup',
    ADD COLUMN context_type VARCHAR(50) DEFAULT 'cms' COMMENT 'Primary context: cms, job_visit, quote_visit, portfolio, marketing_general',
    ADD COLUMN captured_at DATETIME NULL COMMENT 'When photo was taken (from EXIF)',
    ADD COLUMN gps_lat DECIMAL(10,8) NULL,
    ADD COLUMN gps_lng DECIMAL(11,8) NULL,
    ADD COLUMN gps_accuracy_m DECIMAL(8,2) NULL,
    ADD COLUMN gps_source VARCHAR(20) DEFAULT 'none' COMMENT 'exif, browser, manual, none',
    ADD COLUMN address_text VARCHAR(500) NULL COMMENT 'Resolved address',
    ADD COLUMN address_source VARCHAR(20) DEFAULT 'none' COMMENT 'property, geocode, manual, none',
    ADD COLUMN pow_stamp_applied TINYINT(1) DEFAULT 0,
    ADD COLUMN original_bytes BIGINT UNSIGNED NULL,
    ADD COLUMN status VARCHAR(20) DEFAULT 'ready' COMMENT 'uploaded, processing, ready, error';

ALTER TABLE media_assets ADD UNIQUE INDEX idx_ma_uuid (uuid);
ALTER TABLE media_assets ADD INDEX idx_ma_sha256 (sha256);
ALTER TABLE media_assets ADD INDEX idx_ma_context_type (context_type);
ALTER TABLE media_assets ADD INDEX idx_ma_captured_at (captured_at);
ALTER TABLE media_assets ADD INDEX idx_ma_status (status);


-- ============================================================================
-- 2. media_links — Junction table for flexible context linking
-- ============================================================================
-- One media asset can link to multiple contexts (job_visit + marketing, etc.)

CREATE TABLE IF NOT EXISTS media_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    media_id INT NOT NULL COMMENT 'FK to media_assets.id',
    context_type VARCHAR(50) NOT NULL COMMENT 'job_visit, quote_visit, job_plan, property, portfolio, marketing_general, cms_block',
    context_id INT NOT NULL DEFAULT 0 COMMENT 'ID in the context table (0 for general)',
    category VARCHAR(50) NULL COMMENT 'before, during, after, issue, hero, gallery, access, equipment, damage, other',
    sort_order INT DEFAULT 0 COMMENT 'Display ordering within context',
    visibility VARCHAR(20) DEFAULT 'internal' COMMENT 'internal, client_visible, marketing_eligible',
    notes TEXT NULL,
    linked_by INT NULL COMMENT 'User who created the link',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_ml_media (media_id),
    INDEX idx_ml_context (context_type, context_id),
    INDEX idx_ml_category (category),
    INDEX idx_ml_sort (context_type, context_id, sort_order),

    FOREIGN KEY (media_id) REFERENCES media_assets(id) ON DELETE CASCADE,
    FOREIGN KEY (linked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============================================================================
-- 3. media_variants — Responsive image variants (new, separate from cms_media_variants)
-- ============================================================================

CREATE TABLE IF NOT EXISTS media_variants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    media_id INT NOT NULL COMMENT 'FK to media_assets.id',
    variant_type VARCHAR(30) NOT NULL COMMENT 'responsive, thumb_square',
    format VARCHAR(10) NOT NULL COMMENT 'jpeg, webp, avif',
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    file_path VARCHAR(500) NOT NULL COMMENT 'Relative path from web root',
    file_size BIGINT UNSIGNED NULL,
    quality TINYINT UNSIGNED NULL COMMENT 'Compression quality used (0-100)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_mv_media_type (media_id, variant_type),
    INDEX idx_mv_media_format (media_id, format),
    UNIQUE INDEX idx_mv_unique (media_id, variant_type, width, format),

    FOREIGN KEY (media_id) REFERENCES media_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============================================================================
-- 4. media_derivatives — PoW stamps, annotated versions, etc.
-- ============================================================================

CREATE TABLE IF NOT EXISTS media_derivatives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    media_id INT NOT NULL,
    derivative_type VARCHAR(50) NOT NULL COMMENT 'pow_stamp, watermarked, annotated',
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT UNSIGNED NULL,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    stamp_data TEXT NULL COMMENT 'Serialized stamp details: address, date, crew, service',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_md_media (media_id),
    INDEX idx_md_type (derivative_type),

    FOREIGN KEY (media_id) REFERENCES media_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============================================================================
-- 5. Track migration
-- ============================================================================

INSERT INTO migrations_log (migration_file, executed_at)
VALUES ('300_unified_media_system.sql', NOW())
ON DUPLICATE KEY UPDATE executed_at = NOW();
