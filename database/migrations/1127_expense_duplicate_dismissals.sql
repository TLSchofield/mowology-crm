-- Migration 1127: remember "not a duplicate"
-- Date: 2026-10-05
-- Purpose: possible duplicate receipts (same total to the cent within ±3 days, same
--   vendor — ExpenseLookupService::findDuplicates) are held back from Penny's approval
--   line until the owner settles them. "Not a duplicate" is kept here so the pair never
--   comes back. (The receipts page only remembered dismissals for the browser session.)
-- Code is guarded: without this table, pairs can still be merged; only "not a duplicate"
--   is unavailable.
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS expense_duplicate_dismissals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  expense_a INT NOT NULL COMMENT 'lower expense id of the pair',
  expense_b INT NOT NULL COMMENT 'higher expense id of the pair',
  dismissed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_edd_pair (expense_a, expense_b),
  INDEX idx_edd_b (expense_b)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Receipt pairs the owner said are not duplicates';
