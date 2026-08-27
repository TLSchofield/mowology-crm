-- Migration 1114: Crew Service Recommendations
--
-- Extends the existing Field Observations feature (migrations 602/605) so a crew
-- member can photograph on-site work that needs doing, tap a service package, and
-- have that turn into a real priced Quote for the client.
--
-- Two additions:
--   1. products.field_* — the office curates which catalogue services appear as
--      tappable chips on the crew's job card, and which are safe to auto-send.
--   2. field_observations.quote_id/recommended_price/auto_sent/source — links an
--      observation to the Quote it generated and records the price at capture time.
--
-- Multiple photos need NO new table: media_links already accepts
-- context_type='field_observation'. photo_media_id stays as the cover image so the
-- existing 605 email templates keep working.
--
-- Depends on: products, field_observations (602), quotes, media_links
-- MySQL 5.7 safe: no window/JSON functions, no generated columns, no
-- CREATE INDEX IF NOT EXISTS. All ADD COLUMNs are guarded in the PHP runner.

-- Safety net: 602 has no run-migration runner, so the tables may never have been
-- applied. These are the 602 definitions verbatim and are no-ops if already present.
CREATE TABLE IF NOT EXISTS field_observations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  visit_id INT NULL COMMENT 'FK to job_visits if logged during a visit',
  property_id INT NOT NULL,
  contact_id INT NOT NULL,
  observation_type VARCHAR(50) NOT NULL,
  observation_value VARCHAR(255) NULL,
  notes TEXT NULL,
  photo_media_id INT NULL COMMENT 'FK to media_assets — cover image',
  recommended_product_id INT NULL COMMENT 'FK to products',
  status VARCHAR(20) DEFAULT 'pending',
  auto_send TINYINT(1) DEFAULT 0,
  email_sent_at DATETIME NULL,
  dismissed_reason VARCHAR(255) NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status (status),
  KEY idx_contact (contact_id),
  KEY idx_property (property_id),
  KEY idx_product (recommended_product_id),
  KEY idx_visit (visit_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS observation_product_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  observation_type VARCHAR(50) NOT NULL,
  condition_match VARCHAR(255) NULL,
  recommended_product_id INT NOT NULL,
  email_subject VARCHAR(255) NOT NULL,
  email_body_template TEXT NOT NULL,
  auto_send TINYINT(1) DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  priority INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_type (observation_type),
  KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── products: which services the crew may offer from the field ───────────────
ALTER TABLE products ADD COLUMN field_recommendable TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = appears as a tappable chip on the crew job card';
ALTER TABLE products ADD COLUMN field_auto_send TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = fixed-price package, emails the client without office review';
ALTER TABLE products ADD COLUMN field_label VARCHAR(80) NULL
  COMMENT 'Short chip text e.g. "Half Day Cleanup"; falls back to name';
ALTER TABLE products ADD COLUMN field_sort_order INT NOT NULL DEFAULT 0;

-- ── field_observations: link to the generated quote ──────────────────────────
ALTER TABLE field_observations ADD COLUMN quote_id INT NULL
  COMMENT 'FK to quotes — the draft quote this recommendation generated';
ALTER TABLE field_observations ADD COLUMN recommended_price DECIMAL(10,2) NULL
  COMMENT 'Price snapshot at capture time';
ALTER TABLE field_observations ADD COLUMN auto_sent TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = skipped the review queue and emailed immediately';
ALTER TABLE field_observations ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'observation'
  COMMENT 'observation = rules-driven (602); service = crew picked a product directly';

CREATE INDEX idx_fo_status_created ON field_observations (status, created_at);
CREATE INDEX idx_fo_dup_guard ON field_observations (property_id, recommended_product_id, created_at);
CREATE INDEX idx_products_field_reco ON products (field_recommendable, field_sort_order);
