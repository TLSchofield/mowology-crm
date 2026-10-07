-- Migration 1211: Penny's on-demand guidance on the bank-line card
-- Date: 2026-10-07
-- Purpose: a bank line Penny can't place gets an "Ask Penny for guidance" button. Only a
--   click calls Claude (BankGuidanceService); each call (or free reuse "from earlier") is
--   kept in bank_guidance with its tokens and cost, and a daily cap lives in ops_settings.
--   When the owner approves after asking, the guidance is kept on the decision
--   (bank_line_reviews.guidance_json) and the payee's friendly name is learned
--   (bank_payee_names: "point sale tlnk" → "TransLink").
-- Code is guarded: the button stays hidden until bank_guidance exists.
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS bank_guidance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT NOT NULL,
  payee_key VARCHAR(120) NOT NULL COMMENT 'BankImportService::descriptionKey() — the cache key',
  source VARCHAR(10) NOT NULL COMMENT 'claude | cache',
  note TEXT NULL COMMENT 'what the owner told Penny',
  guidance_json TEXT NULL COMMENT 'validated answer',
  model VARCHAR(60) NULL,
  input_tokens INT NULL,
  output_tokens INT NULL,
  cost_usd DECIMAL(10,5) NULL,
  error VARCHAR(255) NULL,
  requested_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bg_payee (payee_key),
  INDEX idx_bg_created (created_at),
  INDEX idx_bg_tx (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Penny bank-line guidance: one row per ask (Claude call or free reuse)';

CREATE TABLE IF NOT EXISTS bank_payee_names (
  payee_key VARCHAR(120) NOT NULL PRIMARY KEY,
  display_name VARCHAR(120) NOT NULL,
  taught_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Friendly payee names learned from approved guidance (TLNK → TransLink)';

-- bank_line_reviews.guidance_json (nullable). No ADD COLUMN IF NOT EXISTS in MySQL 5.7.
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_line_reviews' AND COLUMN_NAME = 'guidance_json');
SET @sql = IF(@c > 0, 'SELECT 1',
  'ALTER TABLE bank_line_reviews ADD COLUMN guidance_json TEXT NULL COMMENT ''Penny guidance the owner asked for before deciding''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO ops_settings (setting_key, setting_value, description)
VALUES ('penny_guidance_daily_cap', '30', 'Penny: Claude guidance asks per day on the bank-line card');
