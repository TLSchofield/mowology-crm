-- Migration 1110: e-Transfer ↔ bank deposit reconciliation link
--
-- Adds the missing third leg of e-Transfer reconciliation. Bank↔invoice and
-- email↔invoice matching already existed independently; this links the bank
-- deposit directly to the email notification so a transfer can be shown as
-- fully reconciled (bank record + invoice + email) rather than just "pending".
--
-- Idempotent via the run-migration-1110 admin script (tryExec pattern).

ALTER TABLE etransfer_notifications
  ADD COLUMN bank_transaction_id INT NULL AFTER matched_invoice_id,
  ADD COLUMN bank_match_confidence INT NULL AFTER bank_transaction_id,
  ADD INDEX idx_bank_transaction_id (bank_transaction_id);
