-- Migration 985: Bank reconciliation — link bank imports to expense receipts
-- Run once via /crm/api/run-migration-985.php
--
-- Adds:
--   accounting_transactions.matched_expense_id  — FK to the expense this bank tx reconciles
--   accounting_transactions.match_confidence    — 0-100 score from auto-match engine
--   accounting_transactions.matched_at          — when the match was confirmed
--   accounting_transactions.matched_by          — 'auto' or user_id as string
--   bank_import_rows.match_status               — reconciliation state per row
--   bank_import_rows.matched_expense_id         — expense matched in this row

ALTER TABLE accounting_transactions
  ADD COLUMN matched_expense_id INT       NULL AFTER reference_id,
  ADD COLUMN match_confidence   TINYINT   NULL,
  ADD COLUMN matched_at         DATETIME  NULL,
  ADD COLUMN matched_by         VARCHAR(20) NULL;

ALTER TABLE bank_import_rows
  ADD COLUMN match_status       ENUM('unmatched','auto_matched','suggested','manually_matched','new_expense','true_duplicate')
                                NOT NULL DEFAULT 'unmatched' AFTER is_duplicate,
  ADD COLUMN matched_expense_id INT NULL;

CREATE INDEX idx_at_matched_expense ON accounting_transactions(matched_expense_id);
CREATE INDEX idx_bir_match_status   ON bank_import_rows(match_status);
