-- ============================================================
-- CMS Phase 2 — Block Types + Full-Text Index (P2-E / P2-H)
-- Migration: 923_cms_phase2_blocks.sql
-- ============================================================

-- Register new dynamic block types
INSERT INTO cms_block_types (block_type, label, description, is_active, supports_variants, available_variants_json, created_at)
VALUES
    ('recent_projects', 'Recent Projects', 'Dynamically pulls latest portfolio items from the database', 1, 0, NULL, NOW()),
    ('quote_cta', 'Quote CTA', 'Dynamic call-to-action with live phone number and seasonal offers', 1, 1, '["green","dark","light"]', NOW()),
    ('service_area_map', 'Service Area Map', 'Interactive Leaflet.js map of service areas (no API key required)', 1, 0, NULL, NOW())
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_active = 1;

-- Add FULLTEXT index to cms_pages for P2-H full-text search
-- (Only if it doesn't already exist — use procedure to check)
-- Note: ADD INDEX IF NOT EXISTS not available in MySQL 5.7; use CREATE INDEX + ignore error in code
ALTER TABLE cms_pages ADD FULLTEXT INDEX ft_cms_pages_search (title, meta_description, meta_keywords);
