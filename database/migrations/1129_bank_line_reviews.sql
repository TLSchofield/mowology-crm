-- Migration 1129: Penny reviews bank lines
-- Date: 2026-10-05
-- Purpose: imported bank lines left on the default account (Miscellaneous / Other
--   Services) come to Penny's card; she suggests a category from code alone and the
--   owner approves, picks another, or keeps it. Each decision is kept here so a line
--   never comes back, and her suggestions get a scorecard (accepted vs edited).
-- Code is guarded: the bank review stays hidden until this runs.
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS bank_line_reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT NOT NULL,
  suggested_account_id INT NULL COMMENT 'what Penny proposed (null = she did not know)',
  final_account_id INT NULL,
  outcome VARCHAR(20) NOT NULL COMMENT 'accepted | edited | kept',
  decided_by INT NULL,
  decided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_blr_tx (transaction_id),
  INDEX idx_blr_outcome (outcome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Penny bank-line reviews: one row per imported line the owner decided on';
