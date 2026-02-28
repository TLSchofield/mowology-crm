-- ============================================================================
-- Phase 4: Template-Driven Landing Page Generation
-- ============================================================================
-- Supports service + neighbourhood → auto-generated pages

-- Add fields for tracking auto-generated pages
ALTER TABLE cms_pages ADD COLUMN (
    is_template_generated BOOLEAN DEFAULT FALSE COMMENT 'Page was auto-generated from template',
    template_source_key VARCHAR(100) COMMENT 'Source template key if auto-generated',
    generated_variables JSON COMMENT 'Variables used for generation: {"service": "lawn-care", "neighbourhood": "burnaby"}'
);

-- Create generator configuration table
CREATE TABLE IF NOT EXISTS cms_page_generator_config (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_label VARCHAR(255) NOT NULL,
    config_data JSON NOT NULL COMMENT 'Generator configuration and templates',
    enabled BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    INDEX idx_enabled (enabled)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- Create table for tracking generations for analytics
CREATE TABLE IF NOT EXISTS cms_page_generations_log (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    page_id INT UNSIGNED,
    generator_config_id INT UNSIGNED,
    variables JSON,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    generated_by INT UNSIGNED,
    FOREIGN KEY (page_id) REFERENCES cms_pages(id) ON DELETE SET NULL,
    FOREIGN KEY (generator_config_id) REFERENCES cms_page_generator_config(id),
    INDEX idx_generated_at (generated_at DESC),
    INDEX idx_page_id (page_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- Track schema migration
INSERT INTO migrations_log (migration_file, executed_at)
VALUES ('113_cms_phase4_template_generation.sql', NOW())
ON DUPLICATE KEY UPDATE executed_at = NOW();
