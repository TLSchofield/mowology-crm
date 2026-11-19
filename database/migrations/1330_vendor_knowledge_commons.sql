-- Migration 1330: the vendor knowledge commons (shared, cross-tenant, trade-neutral)
-- Date: 2026-10-11
-- Purpose: once one tenant has taught Penny how to read a vendor's receipt, every tenant gets
--   it for free. The commons holds ONLY universal facts about a vendor — identity (GST/BN number,
--   phone digits, web domain, name key), receipt layout, SKU dictionary, a category default in a
--   neutral taxonomy and the tax treatment. Never a tenant's jobs, cards, amounts or frequency.
--   Mowology's install is tenant #1 and the hub; other installs call /api/knowledge/vendor.php.
-- Design: docs/architecture/vendor-knowledge-commons.md. Services: app/Modules/Knowledge/Services/.
-- Code is guarded: every reader probes for kb_vendors and does nothing until this has run.
-- MySQL 5.7 compatible: no JSON type, no generated columns, no CREATE INDEX IF NOT EXISTS.

-- Canonical vendors. One row per real-world vendor, keyed by what their own paper prints.
CREATE TABLE IF NOT EXISTS kb_vendors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name_key VARCHAR(160) NOT NULL COMMENT 'VendorIdentity::nameKey — lower case, no legal suffix / store number',
  display_name VARCHAR(160) NOT NULL,
  gst_number VARCHAR(20) NULL COMMENT '123456789RT0001 or the 9-digit BN',
  phone_digits VARCHAR(10) NULL COMMENT 'last 10 digits',
  domain VARCHAR(120) NULL COMMENT 'lower case, no www',
  country CHAR(2) NOT NULL DEFAULT 'CA',
  region VARCHAR(8) NULL COMMENT 'province/state code when known',
  kind VARCHAR(32) NULL COMMENT 'trade-neutral: fuel_station | hardware | supplier | dealer | waste | government | restaurant | online | utility | other',
  status VARCHAR(12) NOT NULL DEFAULT 'candidate' COMMENT 'candidate | shared | curated | blocked',
  tenant_count INT NOT NULL DEFAULT 0 COMMENT 'distinct tenants that have confirmed this vendor',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_kbv_name (name_key),
  KEY idx_kbv_gst (gst_number),
  KEY idx_kbv_phone (phone_digits),
  KEY idx_kbv_domain (domain),
  KEY idx_kbv_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Other spellings the same vendor prints ("THE HOME DEPOT", "HOME DEPOT #7128").
CREATE TABLE IF NOT EXISTS kb_vendor_aliases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kb_vendor_id INT NOT NULL,
  alias_key VARCHAR(160) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_kba (alias_key, kb_vendor_id),
  KEY idx_kba_vendor (kb_vendor_id),
  CONSTRAINT fk_kba_vendor FOREIGN KEY (kb_vendor_id) REFERENCES kb_vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Universal facts. fact: receipt_layout | sku | category_default | tax_treatment | kind
--   value_json: the fact (layout = tax labels/positions/date format/suffix patterns;
--   sku = {sku, name, kind}; category_default = {code}; tax_treatment = {gst_exempt, pst_applies})
--   Promotion: candidate (1 tenant) → shared (>= 2 tenants, or curated by the hub owner).
--   receipt_layout and sku facts are shared at 1 tenant: they describe the vendor's paper, not a business.
CREATE TABLE IF NOT EXISTS kb_vendor_facts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kb_vendor_id INT NOT NULL,
  fact VARCHAR(24) NOT NULL,
  value_hash CHAR(40) NOT NULL,
  value_json TEXT NOT NULL,
  confirmations INT NOT NULL DEFAULT 1 COMMENT 'total confirmations across tenants',
  tenants INT NOT NULL DEFAULT 1 COMMENT 'distinct tenants confirming (kb_fact_tenants)',
  status VARCHAR(12) NOT NULL DEFAULT 'candidate' COMMENT 'candidate | shared | blocked',
  first_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_kbf (kb_vendor_id, fact, value_hash),
  KEY idx_kbf_fact (fact, status),
  CONSTRAINT fk_kbf_vendor FOREIGN KEY (kb_vendor_id) REFERENCES kb_vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Which tenants confirmed a fact — hashed, so the commons never knows who. Drives `tenants`.
CREATE TABLE IF NOT EXISTS kb_fact_tenants (
  fact_id INT NOT NULL,
  tenant_hash CHAR(40) NOT NULL,
  confirmations INT NOT NULL DEFAULT 1,
  last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (fact_id, tenant_hash),
  CONSTRAINT fk_kbft_fact FOREIGN KEY (fact_id) REFERENCES kb_vendor_facts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Installs allowed to read/contribute over the API (the hub itself needs no row).
CREATE TABLE IF NOT EXISTS kb_tenants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  discipline VARCHAR(60) NULL COMMENT 'landscaping | plumbing | electrical | cleaning | … (free text, their words)',
  api_key_hash CHAR(64) NOT NULL COMMENT 'sha256 of the key; the key itself is shown once',
  tenant_hash CHAR(40) NOT NULL COMMENT 'VendorIdentity::tenantHash(name)',
  calls_today INT NOT NULL DEFAULT 0,
  calls_day DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_kbt_key (api_key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- This install's bridge: neutral taxonomy code → the category name this tenant uses.
CREATE TABLE IF NOT EXISTS kb_category_map (
  neutral_code VARCHAR(24) NOT NULL PRIMARY KEY,
  local_category VARCHAR(50) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO kb_category_map (neutral_code, local_category) VALUES
  ('materials', 'Materials'), ('fuel', 'Fuel'), ('tools_equipment', 'Tools/Equipment'),
  ('parts_repairs', 'Repairs/Maintenance'), ('disposal', 'Disposal/Dump'), ('permits_licences', 'Licenses/Permits'),
  ('subcontract', 'Subcontractors'), ('marketing', 'Marketing'), ('office_admin', 'Office/Admin'),
  ('software', 'Office/Admin'), ('meals', 'Meals'), ('vehicle', 'Vehicle'), ('safety_uniforms', 'Safety'),
  ('insurance', 'Overhead'), ('utilities', 'Overhead'), ('other', 'Other');

-- Remote installs cache commons answers for a day so a slow hub never slows a receipt.
CREATE TABLE IF NOT EXISTS kb_cache (
  cache_key CHAR(40) NOT NULL PRIMARY KEY,
  payload_json MEDIUMTEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY idx_kbc_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Link this install's vendors to the commons and carry the identity fields locally.
ALTER TABLE vendors
  ADD COLUMN kb_vendor_id INT NULL COMMENT 'kb_vendors.id on the hub (same DB here)',
  ADD COLUMN gst_number VARCHAR(20) NULL,
  ADD COLUMN name_key VARCHAR(160) NULL COMMENT 'VendorIdentity::nameKey(name)',
  ADD COLUMN domain VARCHAR(120) NULL,
  ADD KEY idx_vendors_kb (kb_vendor_id),
  ADD KEY idx_vendors_name_key (name_key);

-- Who made a suggestion: the model, or code from learned vendor facts (no API call).
ALTER TABLE expense_suggestions
  ADD COLUMN made_by VARCHAR(10) NOT NULL DEFAULT 'model' COMMENT 'model | code';

-- Gate for code-made suggestions: off | shadow (stored, never shown — measures agreement) | on
INSERT IGNORE INTO ops_settings (setting_key, setting_value) VALUES ('penny_code_suggestions', 'shadow');
