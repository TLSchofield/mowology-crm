-- Migration 1403: Mia executions (Phase 2.1 of docs/mia/MIA-IMPLEMENTATION-PLAN.md)
-- Date: 2026-10-26
-- Purpose (also mia_secrets, the encrypted credential store for later connectors):
--   mia_executions   One row for every time an approved Mia change was applied to a website, with the exact values
--                    before and after so it can be undone. An apply is only possible for an action the owner approved
--                    whose draft hash still matches. Nothing here runs by itself.
-- Tenancy: site_id leads every index. No foreign keys. MySQL 5.7 compatible.
-- Comments contain no semicolons (the web runner splits statements on them).

CREATE TABLE IF NOT EXISTS mia_executions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  action_id INT NOT NULL,
  draft_id INT NULL,
  website_id INT NOT NULL,
  connector VARCHAR(20) NOT NULL           COMMENT 'cms today. wordpress later',
  target_ref VARCHAR(190) NOT NULL         COMMENT 'the connector row changed, e.g. the cms_pages id',
  page_url VARCHAR(1000) NOT NULL,
  before_json TEXT NULL                    COMMENT 'JSON text: the values before the change',
  after_json TEXT NULL                     COMMENT 'JSON text: the values written',
  status VARCHAR(12) NOT NULL DEFAULT 'applied' COMMENT 'applied | verified | unverified | rolled_back | failed',
  verify_note VARCHAR(400) NULL            COMMENT 'what the live page showed after the change',
  applied_by INT NOT NULL,
  applied_at DATETIME NOT NULL,
  rolled_back_by INT NULL,
  rolled_back_at DATETIME NULL,
  error VARCHAR(400) NULL,
  INDEX idx_mia_exec_action (site_id, action_id),
  INDEX idx_mia_exec_status (site_id, status, applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: changes applied to a website, with before and after for undo';

CREATE TABLE IF NOT EXISTS mia_secrets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  website_id INT NOT NULL,
  kind VARCHAR(20) NOT NULL                COMMENT 'wp_app_password | api_token',
  secret_enc TEXT NOT NULL                 COMMENT 'encrypted with the CRM social key, never shown or logged',
  saved_by INT NOT NULL,
  saved_at DATETIME NOT NULL,
  UNIQUE KEY uq_mia_secrets (site_id, website_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: credentials for website connectors, encrypted. Unused until a WordPress connector exists';
