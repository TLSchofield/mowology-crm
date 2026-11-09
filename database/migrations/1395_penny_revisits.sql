-- Migration 1395: Penny's revisit list
-- Date: 2026-10-21
-- Purpose: when Penny learns something new (a vendor is added, a payee is identified, a rule is learned) she
--   looks again at bank lines she (or the owner) already filed or left on the default account, and PROPOSES a
--   better account, grouped by payee. One row per (line, proposed account, trigger). A new table on purpose:
--   bank_line_reviews is one-row-per-line history ("the line never comes back") and is never rewritten.
--   tier: A = would be safe to apply without a click (owner-authored evidence, default account, open month;
--         applied only when penny_revisit_mode = 'auto_narrow', which ships OFF),
--         B = proposed for the owner to approve (one decision per payee),
--         C = line is in a locked month: never moved, listed for the accountant.
-- Code is guarded: the revisit page stays hidden until this runs.
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS penny_revisits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT NOT NULL,
  trigger_kind VARCHAR(20) NOT NULL DEFAULT 'scan' COMMENT 'scan | vendor | receipt | payee | payroll',
  trigger_ref VARCHAR(100) NULL COMMENT 'what changed (vendor name, payee key ...)',
  payee_key VARCHAR(160) NOT NULL DEFAULT '' COMMENT 'BankImportService::descriptionKey of the line',
  from_account_id INT NULL COMMENT 'the line''s account when proposed (undo goes back here)',
  to_account_id INT NOT NULL COMMENT 'the account Penny now proposes',
  tier CHAR(1) NOT NULL COMMENT 'A | B | C',
  source VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'vendor | learned | rule | fact',
  reason VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'why, in words, for the memo and the accountant',
  amount DECIMAL(10,2) NULL,
  line_date DATE NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'proposed' COMMENT 'proposed | applied | dismissed | undone',
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pr_line_target (transaction_id, to_account_id, trigger_kind),
  INDEX idx_pr_status_tier (status, tier),
  INDEX idx_pr_payee (payee_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Penny revisit proposals: bank lines to look at again, grouped by payee';
