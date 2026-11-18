-- Migration 1402: Mia content drafts (Phase 1.10 of docs/mia/MIA-IMPLEMENTATION-PLAN.md)
-- Date: 2026-10-25
-- Purpose:
--   mia_content_drafts   The wording Mia proposes for a page (title and description today). The approval of a
--                        mia_actions row is tied to the exact text here by hash, so an edit voids the approval.
--                        Nothing in this table is ever published by Phase 1.
-- Tenancy: site_id leads every index. No foreign keys. MySQL 5.7 compatible.
-- Comments contain no semicolons (the web runner splits statements on them).

CREATE TABLE IF NOT EXISTS mia_content_drafts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  website_id INT NOT NULL,
  action_id INT NULL                       COMMENT 'the mia_actions row this draft belongs to',
  kind VARCHAR(20) NOT NULL DEFAULT 'seo_meta' COMMENT 'seo_meta (title and description). More kinds later.',
  page_url VARCHAR(1000) NOT NULL,
  current_title VARCHAR(300) NULL,
  current_desc VARCHAR(500) NULL,
  title VARCHAR(300) NULL,
  meta_desc VARCHAR(500) NULL,
  rationale VARCHAR(600) NULL              COMMENT 'plain-English reason shown with the draft',
  input_hash CHAR(40) NOT NULL             COMMENT 'sha1 of what the model was given, so the same input is not paid for twice',
  status VARCHAR(12) NOT NULL DEFAULT 'draft' COMMENT 'draft | superseded | applied',
  version INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  INDEX idx_mia_drafts_action (site_id, action_id, version),
  INDEX idx_mia_drafts_page (site_id, website_id, kind, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: proposed page wording, never published by Phase 1';
