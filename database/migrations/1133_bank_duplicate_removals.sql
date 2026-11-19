-- Migration 1133: an audit trail for bank lines taken out as duplicates
-- Date: 2026-10-05
-- Purpose: overlapping Vancity statement imports (sessions 33-40) re-added the same lines
--   2-4 times. BankDuplicateCleanup snapshots each extra copy here before taking it out
--   of accounting_transactions (its journal entry is reversed, its statement row is kept
--   and marked duplicate), so nothing is lost and every removal can be traced or restored.
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS bank_duplicate_removals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT NOT NULL COMMENT 'the copy taken out',
  kept_transaction_id INT NOT NULL COMMENT 'the line kept',
  snapshot_json MEDIUMTEXT NOT NULL COMMENT 'the full row as it was',
  removed_by INT NULL,
  removed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bdr_kept (kept_transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Duplicate bank lines taken out, with a snapshot of each';
