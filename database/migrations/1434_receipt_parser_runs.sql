-- Migration 1434 (2026-11-04): the receipt reader's nightly scorecard.
-- ReceiptParserScoreService::run() (from penny_prepare) parses every approved receipt's stored OCR text with the
-- current parser and stores one row per day: per-field right / of, the phone read's total, and the misses grouped by
-- pattern. Penny's brief reports when the parser changes or the score moves. misses_json is stored as text; MySQL
-- never parses it (5.7, no JSON functions).
CREATE TABLE IF NOT EXISTS receipt_parser_runs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_date DATE NOT NULL,
  parser_hash CHAR(32) NOT NULL DEFAULT '',
  receipts INT UNSIGNED NOT NULL DEFAULT 0,
  total_right INT UNSIGNED NOT NULL DEFAULT 0,    total_of INT UNSIGNED NOT NULL DEFAULT 0,
  subtotal_right INT UNSIGNED NOT NULL DEFAULT 0, subtotal_of INT UNSIGNED NOT NULL DEFAULT 0,
  gst_right INT UNSIGNED NOT NULL DEFAULT 0,      gst_of INT UNSIGNED NOT NULL DEFAULT 0,
  pst_right INT UNSIGNED NOT NULL DEFAULT 0,      pst_of INT UNSIGNED NOT NULL DEFAULT 0,
  date_right INT UNSIGNED NOT NULL DEFAULT 0,     date_of INT UNSIGNED NOT NULL DEFAULT 0,
  device_receipts INT UNSIGNED NOT NULL DEFAULT 0,
  device_total_right INT UNSIGNED NOT NULL DEFAULT 0, device_total_of INT UNSIGNED NOT NULL DEFAULT 0,
  misses_json MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_parser_run_date (run_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
