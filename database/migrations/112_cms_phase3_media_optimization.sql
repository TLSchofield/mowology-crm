-- ============================================================================
-- Phase 3: Media Optimization & SEO Automation
-- ============================================================================
-- Adds support for responsive images, WebP variants, and SEO automation

-- Add media optimization fields to cms_media
ALTER TABLE cms_media ADD COLUMN (
    webp_path VARCHAR(255) COMMENT 'Path to WebP variant of original image',
    source_width INT UNSIGNED COMMENT 'Original image width in pixels',
    source_height INT UNSIGNED COMMENT 'Original image height in pixels',
    sizes_json JSON COMMENT 'JSON map of responsive sizes: {"640": "/path/640.jpg", "1024": "/path/1024.jpg"}'
);

-- Create table for tracking media variants (for cleanup/management)
CREATE TABLE IF NOT EXISTS cms_media_variants (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    media_id INT UNSIGNED NOT NULL,
    variant_type VARCHAR(50) NOT NULL COMMENT 'thumb, small, medium, large, xlarge, webp, etc.',
    file_path VARCHAR(255) NOT NULL,
    width INT UNSIGNED,
    height INT UNSIGNED,
    file_size BIGINT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (media_id) REFERENCES cms_media(id) ON DELETE CASCADE,
    INDEX idx_media_type (media_id, variant_type)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- Add SEO fields to cms_pages for Phase 3
ALTER TABLE cms_pages ADD COLUMN (
    auto_seo_enabled BOOLEAN DEFAULT TRUE COMMENT 'Auto-populate SEO fields with smart defaults',
    canonical_override VARCHAR(500) COMMENT 'Override auto-generated canonical URL',
    robots_override VARCHAR(100) COMMENT 'Override robots meta tag (e.g., noindex, nofollow)'
);

-- Create alt text suggestions table for media audit
CREATE TABLE IF NOT EXISTS cms_media_alt_suggestions (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    media_id INT UNSIGNED NOT NULL,
    suggested_alt TEXT NOT NULL,
    suggested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (media_id) REFERENCES cms_media(id) ON DELETE CASCADE,
    INDEX idx_media_id (media_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- Add indexes for performance
CREATE INDEX idx_cms_pages_status_published ON cms_pages(status, noindex);
CREATE INDEX idx_cms_pages_updated_at ON cms_pages(updated_at DESC);
CREATE INDEX idx_cms_media_type ON cms_media(media_type);

-- Track schema migration
INSERT INTO migrations_log (migration_file, executed_at)
VALUES ('112_cms_phase3_media_optimization.sql', NOW())
ON DUPLICATE KEY UPDATE executed_at = NOW();
