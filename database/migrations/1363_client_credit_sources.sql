-- Migration 1363: where a client's credit came from (plan docs/plans/2026-10-14-overpayment-to-credit.md)
-- Date: 2026-10-14
-- Purpose (1): an overpayment kept as credit (Alberta 3025 Strata paid INV-0251 twice) must settle its bank
--   deposit like a payment does — otherwise the deposit's leftover reads as income. The credit row
--   records the e-Transfer it came from and, once linked, the bank line it arrived on.
-- MySQL 5.7 compatible. Nullable INTs; client_credits.type keeps its ENUM ('deposit' is used).

ALTER TABLE client_credits
  ADD COLUMN etransfer_notification_id INT NULL DEFAULT NULL AFTER invoice_id,
  ADD COLUMN bank_transaction_id INT NULL DEFAULT NULL AFTER etransfer_notification_id,
  ADD KEY idx_cc_etransfer (etransfer_notification_id),
  ADD KEY idx_cc_bank_tx (bank_transaction_id);

-- Every invoice number an e-Transfer memo names ("Invs 2026-0251 and 0383" → INV-2026-0251,INV-2026-0383):
-- while it waits on Penny's card, nothing chases those invoices (PaymentOnTheWay).
ALTER TABLE etransfer_notifications
  ADD COLUMN invoice_hints VARCHAR(255) NULL DEFAULT NULL AFTER invoice_hint;

UPDATE etransfer_notifications SET invoice_hints = invoice_hint
 WHERE invoice_hints IS NULL AND invoice_hint IS NOT NULL AND invoice_hint <> '';
