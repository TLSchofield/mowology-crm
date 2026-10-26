-- Migration 1401: Mia website intelligence (Phase 1.5 of docs/mia/MIA-IMPLEMENTATION-PLAN.md)
-- Date: 2026-10-25
-- Purpose:
--   mia_websites         Sites Mia may read. A site must be VERIFIED (the owner proved control) before any crawl.
--   mia_site_pages       The crawl inventory with change tracking (hash, ETag, Last-Modified), so unchanged pages are skipped.
--   mia_audits           One crawl and audit run.
--   mia_audit_findings   Deterministic findings, de-duplicated across runs by fingerprint.
-- Tenancy: every table carries site_id (1 = mowology) as the leading index column. No foreign keys.
-- MySQL 5.7 compatible. Comments contain no semicolons (the web runner splits statements on them).

CREATE TABLE IF NOT EXISTS mia_websites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  base_url VARCHAR(255) NOT NULL           COMMENT 'scheme://host[:port], no path',
  host VARCHAR(190) NOT NULL,
  mode VARCHAR(10) NOT NULL DEFAULT 'existing' COMMENT 'existing | managed',
  connector VARCHAR(20) NOT NULL DEFAULT 'none' COMMENT 'none | cms | wordpress',
  capabilities VARCHAR(255) NULL           COMMENT 'comma list: canReadPages,canCreateDrafts,canUpdatePages,canUploadMedia,canPublishPages,canRollback,canManageMetadata,canManageNavigation',
  status VARCHAR(14) NOT NULL DEFAULT 'pending' COMMENT 'pending | verified | disconnected',
  verify_token CHAR(32) NOT NULL           COMMENT 'proof-of-control token the owner places on the site',
  verified_via VARCHAR(10) NULL            COMMENT 'meta | file | dns',
  verified_at DATETIME NULL,
  last_verify_attempt_at DATETIME NULL,
  last_verify_error VARCHAR(200) NULL,
  crawl_cap INT NOT NULL DEFAULT 200       COMMENT 'most pages per crawl',
  robots_hash CHAR(40) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mia_websites_host (site_id, host),
  INDEX idx_mia_websites_status (site_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: websites she may read, verified by the owner first';

CREATE TABLE IF NOT EXISTS mia_site_pages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  website_id INT NOT NULL,
  url_hash CHAR(40) NOT NULL               COMMENT 'sha1 of the normalised url',
  url VARCHAR(1000) NOT NULL,
  status_code SMALLINT NULL,
  content_hash CHAR(40) NULL               COMMENT 'sha1 of the extracted text and key tags',
  title VARCHAR(300) NULL,
  meta_desc VARCHAR(500) NULL,
  h1 VARCHAR(300) NULL,
  canonical VARCHAR(1000) NULL,
  robots_meta VARCHAR(100) NULL,
  word_count INT NULL,
  schema_types VARCHAR(255) NULL           COMMENT 'comma list of JSON-LD types found',
  etag VARCHAR(190) NULL,
  last_modified VARCHAR(60) NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  last_changed_at DATETIME NULL,
  last_crawl_at DATETIME NULL,
  UNIQUE KEY uq_mia_pages_url (site_id, website_id, url_hash),
  INDEX idx_mia_pages_changed (site_id, website_id, last_changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: crawl inventory with change tracking';

CREATE TABLE IF NOT EXISTS mia_audits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  website_id INT NOT NULL,
  kind VARCHAR(12) NOT NULL DEFAULT 'crawl' COMMENT 'crawl | rules',
  status VARCHAR(10) NOT NULL DEFAULT 'running' COMMENT 'running | done | partial | failed',
  pages INT NOT NULL DEFAULT 0,
  findings INT NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  summary VARCHAR(400) NULL,
  INDEX idx_mia_audits_site (site_id, website_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: one crawl and audit run';

CREATE TABLE IF NOT EXISTS mia_audit_findings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  website_id INT NOT NULL,
  audit_id INT NOT NULL,
  page_id INT NULL,
  rule_key VARCHAR(60) NOT NULL,
  severity VARCHAR(8) NOT NULL             COMMENT 'high | medium | low | info',
  evidence VARCHAR(500) NULL,
  fingerprint CHAR(40) NOT NULL            COMMENT 'sha1 of rule + url + evidence class, stable across runs',
  first_seen_audit_id INT NOT NULL,
  resolved_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mia_findings_fp (site_id, website_id, fingerprint),
  INDEX idx_mia_findings_audit (site_id, audit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: deterministic audit findings';
