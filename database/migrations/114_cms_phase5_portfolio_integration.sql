-- ============================================================================
-- Phase 5: Portfolio → Marketing Integration
-- ============================================================================
-- Links portfolio photos to CMS system for auto-populating proof sections
-- and generating case study pages

-- Add portfolio photo tagging fields
ALTER TABLE portfolio_photos ADD COLUMN (
    service_key VARCHAR(100) COMMENT 'Service associated with photo (e.g., lawn-care)',
    neighbourhood_key VARCHAR(100) COMMENT 'Neighbourhood associated with photo (e.g., burnaby)',
    is_featured BOOLEAN DEFAULT FALSE COMMENT 'Mark as featured for proof sections',
    featured_order INT UNSIGNED COMMENT 'Order when displaying featured photos',
    created_at_photo TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When photo was added',
    updated_at_photo TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When photo was last updated'
);

-- Create table for case study generation tracking
CREATE TABLE IF NOT EXISTS cms_case_studies_generated (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    page_id INT UNSIGNED NOT NULL,
    job_id INT UNSIGNED COMMENT 'Original job this case study was created from',
    service_key VARCHAR(100),
    neighbourhood_key VARCHAR(100),
    photo_count INT UNSIGNED,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    generated_by INT UNSIGNED,
    published BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (page_id) REFERENCES cms_pages(id) ON DELETE CASCADE,
    INDEX idx_job_id (job_id),
    INDEX idx_service (service_key),
    INDEX idx_published (published)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- Create indexes for fast queries
CREATE INDEX idx_portfolio_featured ON portfolio_photos(is_featured, service_key, neighbourhood_key);
CREATE INDEX idx_portfolio_service_neighbourhood ON portfolio_photos(service_key, neighbourhood_key);
CREATE INDEX idx_portfolio_featured_order ON portfolio_photos(featured_order DESC);

-- Create view for easy querying of featured photos
CREATE OR REPLACE VIEW v_portfolio_featured_photos AS
SELECT
    p.id,
    p.job_id,
    p.photo_path,
    p.thumb_path,
    p.alt_text,
    p.service_key,
    p.neighbourhood_key,
    p.featured_order,
    j.title as job_title,
    j.status as job_status
FROM portfolio_photos p
LEFT JOIN jobs j ON p.job_id = j.id
WHERE p.is_featured = TRUE AND p.service_key IS NOT NULL
ORDER BY p.featured_order ASC, p.created_at_photo DESC;

-- Track schema migration
INSERT INTO migrations_log (migration_file, executed_at)
VALUES ('114_cms_phase5_portfolio_integration.sql', NOW())
ON DUPLICATE KEY UPDATE executed_at = NOW();
