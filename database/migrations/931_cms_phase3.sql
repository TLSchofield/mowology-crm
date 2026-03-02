-- ============================================================
-- CMS Phase 3 — Form Capture, Site Settings, New Block Types
-- Migration: 930_cms_phase3.sql
-- ============================================================

-- 1. Form Submission Storage (P3-D)
CREATE TABLE IF NOT EXISTS cms_form_submissions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    form_id     VARCHAR(100) NOT NULL DEFAULT 'cms-contact' COMMENT 'Block-level form identifier',
    name        VARCHAR(150) NOT NULL DEFAULT '',
    email       VARCHAR(255) NOT NULL DEFAULT '',
    phone       VARCHAR(50)  DEFAULT NULL,
    message     TEXT         DEFAULT NULL,
    source_url  VARCHAR(512) DEFAULT NULL,
    ip_address  VARCHAR(45)  DEFAULT NULL,
    status      ENUM('new','read') NOT NULL DEFAULT 'new',
    read_at     DATETIME     DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cfs_form_id  (form_id),
    INDEX idx_cfs_status   (status),
    INDEX idx_cfs_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Site Settings Key/Value Store (P3-G — Announcement Bar + future settings)
CREATE TABLE IF NOT EXISTS cms_site_settings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(100) NOT NULL,
    setting_value MEDIUMTEXT   DEFAULT NULL,
    updated_at    DATETIME     DEFAULT NULL,
    UNIQUE KEY uq_css_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Register new Phase 3 block types
INSERT INTO cms_block_types (block_type, label, description, is_active, supports_variants, available_variants_json, created_at)
VALUES
    ('form_capture',  'Form Capture',       'Embeds a contact/lead capture form into any page', 1, 0, NULL, NOW()),
    ('columns',       'Two-Column Layout',  'Side-by-side content columns with image, text, and optional button', 1, 0, NULL, NOW()),
    ('before_after',  'Before / After',     'Side-by-side image comparison pairs with captions', 1, 0, NULL, NOW()),
    ('custom',        'Custom HTML',        'Raw HTML block — admin use only', 1, 0, NULL, NOW()),
    ('portfolio_showcase', 'Portfolio Showcase', 'Showcase portfolio items with images and links', 1, 0, NULL, NOW()),
    ('service_grid',  'Service Grid',       '3-column service overview cards with icons and links', 1, 0, NULL, NOW()),
    ('stats_banner',  'Stats Banner',       'High-impact horizontal row of numbers and labels', 1, 1, '[\"green\",\"dark\",\"light\"]', NOW())
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_active = 1;

-- ============================================================
-- END OF MIGRATION 930
-- ============================================================
