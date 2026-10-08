-- Migration 1284: bank memos that name a payer (Vancouver Management Ltd's EFTs)
-- Date: 2026-10-08
-- Purpose: Vancouver Management Ltd (VML) pays our contract invoices by cheque today and has
--   offered direct deposit (EFT). When the first VML EFT lands, its bank memo will read
--   "VML ..." or "VANCOUVER MANAGEMENT ..." — words the invoice matcher can't use ("management"
--   is a stop word; "VML" names nobody). A memo matching a pattern here adds that payer to the
--   names Penny's matcher looks for (BankInvoiceMatchService::suggest -> payer_names), exactly
--   as a learned e-Transfer sender does. etransfer_sender_payers (1132) is keyed by Interac
--   sender names, not bank memos, so it can't hold this.
-- Patterns are whole words, case-insensitive. Add a row per payer.
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS bank_memo_payers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pattern VARCHAR(80) NOT NULL           COMMENT 'Whole words in the bank memo, e.g. VML',
  payer_name VARCHAR(255) NOT NULL       COMMENT 'The payer as invoices name it',
  company_id INT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_bmp_pattern (pattern)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Bank memo words -> the payer they name (invoice matching)';

-- VML: the company as it is on file (first match), else the name as they sign it.
SET @co_id = (SELECT id FROM companies WHERE company_name LIKE 'Vancouver Management%' ORDER BY id LIMIT 1);
SET @co_name = COALESCE((SELECT company_name FROM companies WHERE id = @co_id), 'Vancouver Management Ltd');

INSERT IGNORE INTO bank_memo_payers (pattern, payer_name, company_id, note, created_at) VALUES
  ('VML', @co_name, @co_id, 'VML EFT direct deposit (offered 2026-10, replaces cheques)', NOW()),
  ('VANCOUVER MANAGEMENT', @co_name, @co_id, 'VML EFT direct deposit', NOW()),
  ('VANCOUVER MGMT', @co_name, @co_id, 'VML EFT direct deposit', NOW());
