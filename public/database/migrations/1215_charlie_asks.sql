-- Migration 1215: "Ask Charlie" on the iOS Team tab
-- Date: 2026-10-07
-- Purpose: the owner types a question about his business on Charlie's card ("has Linda's
--   quote been sent?") and Charlie answers from CRM data (CharlieAskService). Only a tap
--   calls Claude; context is looked up with prepared statements, the model never writes SQL.
--   Every ask is kept in charlie_asks with its tokens and cost; a daily cap lives in
--   ops_settings (charlie_ask_daily_cap, default 30). Asks where nothing in the CRM matched
--   are answered for free (source = 'none') and don't count against the cap.
-- Code is guarded: the Ask box says "needs migration 1215" until charlie_asks exists.
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS charlie_asks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  question VARCHAR(300) NOT NULL,
  terms VARCHAR(255) NULL COMMENT 'names / streets / document numbers taken from the question',
  matched VARCHAR(255) NULL COMMENT 'JSON: how many contacts, companies, properties, quotes, invoices, visits matched',
  source VARCHAR(10) NOT NULL COMMENT 'claude | none (nothing matched, answered free)',
  answer TEXT NULL,
  model VARCHAR(60) NULL,
  input_tokens INT NULL,
  output_tokens INT NULL,
  cost_usd DECIMAL(10,5) NULL,
  error VARCHAR(255) NULL,
  requested_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ca_created (created_at),
  INDEX idx_ca_source_created (source, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Ask Charlie: one row per question the owner asked (Claude call or free no-match answer)';

INSERT IGNORE INTO ops_settings (setting_key, setting_value, description)
VALUES ('charlie_ask_daily_cap', '30', 'Charlie: Claude-answered questions per day on the iOS Team tab Ask box');
