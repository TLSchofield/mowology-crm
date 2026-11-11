-- Migration 1227: receipt facts — what is PRINTED on a receipt, kept as columns
-- Date: 2026-10-07
-- Purpose: a receipt's printed facts (date, till time or Time In / Time Out, ticket / invoice /
--   transaction number, card last 4, terminal, store number) only lived inside
--   expenses.raw_ocr_json as OCR text. Penny's duplicate check could only compare total + date,
--   so one landfill ticket photographed 3x looked the same as 3 dump runs (#63 / #119 / #228).
--   ReceiptFactsService parses the OCR text into this table whenever OCR text is saved, and a
--   nightly backfill (penny_prepare cron) fills older receipts and re-queues receipts that were
--   never OCR'd (expense_ocr_jobs, with expense_id set).
--   One row per expense. A separate table, not new columns on expenses (shared schema).
-- MySQL 5.7 compatible (no JSON, no generated columns); safe to re-run.

CREATE TABLE IF NOT EXISTS receipt_facts (
  expense_id    INT NOT NULL PRIMARY KEY,
  printed_date  DATE NULL COMMENT 'date printed on the receipt',
  time_first    CHAR(5) NULL COMMENT 'HH:MM — till time, or Time In',
  time_last     CHAR(5) NULL COMMENT 'HH:MM — Time Out (scale tickets); NULL for a single till time',
  doc_number    VARCHAR(40) NULL COMMENT 'ticket / invoice / transaction / receipt / order / auth number',
  doc_kind      VARCHAR(12) NULL COMMENT 'ticket|invoice|transaction|receipt|order|auth',
  card_last4    CHAR(4) NULL,
  card_brand    VARCHAR(12) NULL COMMENT 'visa|mastercard|amex|debit|discover',
  terminal      VARCHAR(30) NULL COMMENT 'till / register / terminal id',
  store_number  VARCHAR(12) NULL,
  parsed_at     DATETIME NOT NULL,
  source        VARCHAR(10) NOT NULL DEFAULT 'ocr',
  INDEX idx_rf_doc (doc_number),
  INDEX idx_rf_card_date (card_last4, printed_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Printed receipt facts parsed from OCR text (ReceiptFactsService)';

-- The backfill asks "has this expense been queued for OCR already?" — index expense_ocr_jobs.expense_id.
SET @c = (SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expense_ocr_jobs' AND INDEX_NAME = 'idx_eoj_expense');
SET @t = (SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expense_ocr_jobs');
SET @sql = IF(@t = 1 AND @c = 0,
    'CREATE INDEX idx_eoj_expense ON expense_ocr_jobs (expense_id)',
    'SELECT ''expense_ocr_jobs.idx_eoj_expense already exists (or no table)'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
