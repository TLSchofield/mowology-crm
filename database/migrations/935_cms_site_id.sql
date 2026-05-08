-- ============================================================================
-- Migration 935: Add site_id to CMS tables for multi-tenant support
-- Created: 2026-03-10
-- Purpose: CmsFunctions.php queries now filter by site_id; add the column
--          to cms_pages, cms_menus, media_assets, and cms_page_redirects.
--          All existing rows default to site 1 (Mowology).
-- ============================================================================

ALTER TABLE cms_pages
    ADD COLUMN site_id INT NOT NULL DEFAULT 1 COMMENT 'Tenant site ID' AFTER id,
    ADD KEY idx_site_id (site_id);

ALTER TABLE cms_menus
    ADD COLUMN site_id INT NOT NULL DEFAULT 1 COMMENT 'Tenant site ID' AFTER id,
    ADD KEY idx_site_id (site_id);

ALTER TABLE media_assets
    ADD COLUMN site_id INT NOT NULL DEFAULT 1 COMMENT 'Tenant site ID' AFTER id,
    ADD KEY idx_site_id (site_id);

ALTER TABLE cms_page_redirects
    ADD COLUMN site_id INT NOT NULL DEFAULT 1 COMMENT 'Tenant site ID' AFTER id,
    ADD KEY idx_site_id (site_id);
