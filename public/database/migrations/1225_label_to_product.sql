-- Migration 1225: Photo of a label → product or machine; receipts feed the product catalogue;
--                 Otto knows how to look after it
-- Date: 2026-10-07
-- Purpose:
--   1. label_captures         — a "Product / machine label" photo from the receipt capture
--                               (web, Android app, iOS). Same media upload + OCR, but it never
--                               makes an expense. LabelReaderService reads the text for free.
--   2. product_proposals      — one-tap cards: Penny (products) and Otto (machines). Proposals
--                               come from label photos AND from receipt line items (email,
--                               receipts@, photo). Nothing is ever created without Tim's click.
--                               "Not now" is remembered (status dismissed).
--   3. product_proposal_lines — the receipt lines behind a proposal. line_ref is unique, so a
--                               receipt line is proposed once, ever.
--   4. product_proposal_scans — which expenses the receipt scan has looked at (backfill cursor).
--   5. equipment_manual_reads — "Ask Otto to read the manual": one row per Claude call with its
--                               tokens and cost; each proposed interval waits for Tim's confirm.
--   6. products.care_notes / label_media_id / photo_marketing_ok — storage & handling read from
--                               the label only (never invented); the label photo; label photos
--                               are internal, so Mia may only use a product picture once Tim
--                               ticks photo_marketing_ok. Existing pictures (CMS images) are
--                               marked OK once, when the column is first added.
--   7. ops_settings otto_manual_daily_cap (default 10).
-- MySQL 5.7 compatible (no JSON functions, no generated columns, no window functions);
-- safe to re-run.

-- 1. Label photos
CREATE TABLE IF NOT EXISTS label_captures (
  id INT AUTO_INCREMENT PRIMARY KEY,
  media_id INT NULL                       COMMENT 'media_assets.id (context_type = label)',
  captured_by INT NULL,
  captured_at DATETIME NOT NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  ocr_text TEXT NULL,
  ocr_source VARCHAR(12) NULL             COMMENT 'vision | tesseract | none',
  parsed_json TEXT NULL                   COMMENT 'LabelReaderService::read() result',
  status VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending | done | dismissed',
  result_type VARCHAR(12) NULL            COMMENT 'product | equipment',
  result_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_lc_status (status, captured_at),
  INDEX idx_lc_media (media_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Photos of product / machine labels — never an expense';

-- 2. Proposals (Penny: products · Otto: machines)
CREATE TABLE IF NOT EXISTS product_proposals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  head VARCHAR(10) NOT NULL               COMMENT 'penny | otto',
  kind VARCHAR(20) NOT NULL               COMMENT 'product_new | product_match | equipment_new | equipment_match',
  proposal_key VARCHAR(160) NOT NULL      COMMENT 'De-dup key: label:N, new:v12:cbm, new:sku:X, link:p11',
  status VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending | accepted | dismissed',
  title VARCHAR(255) NOT NULL,
  product_id INT NULL,
  equipment_id INT NULL,
  label_capture_id INT NULL,
  vendor_id INT NULL,
  payload_json TEXT NULL                  COMMENT 'Proposed fields (explicit columns are written on accept)',
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  INDEX idx_pp_key (proposal_key, status),
  INDEX idx_pp_queue (head, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Product / machine proposals for Penny and Otto — accepted only by a click';

CREATE TABLE IF NOT EXISTS product_proposal_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  proposal_id INT NOT NULL,
  line_ref VARCHAR(40) NOT NULL           COMMENT 'li:<expense_line_items.id> or ocr:<expense_id>:<index>',
  expense_id INT NULL,
  line_item_id INT NULL,
  name VARCHAR(255) NULL,
  quantity DECIMAL(10,2) NULL,
  unit_price DECIMAL(10,2) NULL,
  line_date DATE NULL,
  vendor_name VARCHAR(120) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ppl_ref (line_ref),
  INDEX idx_ppl_proposal (proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Receipt lines behind each product proposal (each line proposed once)';

CREATE TABLE IF NOT EXISTS product_proposal_scans (
  expense_id INT NOT NULL PRIMARY KEY,
  scanned_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Receipts the product scan has read (backfill cursor)';

-- 5. Otto reads a manual
CREATE TABLE IF NOT EXISTS equipment_manual_reads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  equipment_id INT NOT NULL,
  media_id INT NULL                       COMMENT 'The uploaded manual / maintenance-table photo',
  file_name VARCHAR(255) NULL,
  source VARCHAR(10) NOT NULL DEFAULT 'claude',
  proposals_json TEXT NULL                COMMENT 'Intervals Otto read, each pending | saved | skipped',
  note VARCHAR(500) NULL,
  model VARCHAR(60) NULL,
  input_tokens INT NULL,
  output_tokens INT NULL,
  cost_usd DECIMAL(10,5) NULL,
  error VARCHAR(255) NULL,
  requested_by INT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_emr_item (equipment_id),
  INDEX idx_emr_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Otto reading equipment manuals: one row per Claude call';

-- 6. products: care notes, label photo, marketing flag (no ADD COLUMN IF NOT EXISTS in 5.7)
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'care_notes');
SET @sql = IF(@c > 0, 'SELECT 1',
  'ALTER TABLE products ADD COLUMN care_notes TEXT NULL COMMENT ''Storage / handling / safety read from the label only''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'label_media_id');
SET @sql = IF(@c > 0, 'SELECT 1',
  'ALTER TABLE products ADD COLUMN label_media_id INT NULL COMMENT ''media_assets.id of the label photo (internal)''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'photo_marketing_ok');
SET @sql = IF(@c > 0, 'SELECT 1',
  'ALTER TABLE products ADD COLUMN photo_marketing_ok TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = Mia may use the picture; label photos stay 0 until Tim ticks it''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- Only on the run that added the column: the pictures products already had are CMS images.
SET @sql = IF(@c > 0, 'SELECT 1',
  'UPDATE products SET photo_marketing_ok = 1 WHERE image_url IS NOT NULL AND image_url <> ''''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Otto's manual-reading cap
INSERT IGNORE INTO ops_settings (setting_key, setting_value, description)
VALUES ('otto_manual_daily_cap', '10', 'Otto: "read the manual" Claude calls per day');
