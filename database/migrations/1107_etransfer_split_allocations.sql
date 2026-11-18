-- 1107_etransfer_split_allocations.sql
-- Let a single Interac e-Transfer be split across multiple invoices, by reusing
-- the invoice_payment_allocations ledger already built for bank-deposit
-- reconciliation (migration 1062) instead of a second allocation mechanism.
--
-- etransfer_notifications.recorded_invoice_id remains as the pointer to the
-- first invoice recorded (kept for backward compatibility with existing rows
-- and the dashboard pending-count query); the full per-invoice split now lives
-- in invoice_payment_allocations via the new etransfer_notification_id column.
-- allocated_amount tracks how much of the transfer has been assigned so far —
-- status moves 'pending' -> 'partially_recorded' -> 'recorded' as staff assign
-- it (status is already VARCHAR(20), no ALTER needed for the new value).
--
-- Run on production via /crm/run-migration-1107.php (admin only).

ALTER TABLE etransfer_notifications
  ADD COLUMN allocated_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER amount;

ALTER TABLE invoice_payment_allocations
  ADD COLUMN etransfer_notification_id BIGINT UNSIGNED NULL AFTER transaction_id;

CREATE INDEX idx_ipa_etransfer ON invoice_payment_allocations (etransfer_notification_id);

ALTER TABLE invoice_payment_allocations
  ADD CONSTRAINT fk_ipa_etransfer
    FOREIGN KEY (etransfer_notification_id) REFERENCES etransfer_notifications(id) ON DELETE SET NULL;
