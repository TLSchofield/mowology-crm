-- Migration 987: Store Stripe charge ID on invoices for deterministic bank matching
-- Run once via /crm/api/run-migration-987.php
--
-- When a Stripe payment succeeds, the webhook now stores the charge ID (ch_XXXXXXXX)
-- directly on the invoice. During bank import, if the bank description contains this
-- reference, the system performs an exact match instead of fuzzy amount-based matching.
--
-- Also stores it on accounting_transactions so the ledger row has the full audit trail.

ALTER TABLE invoices
  ADD COLUMN stripe_charge_id VARCHAR(255) NULL AFTER stripe_payment_intent_id;

ALTER TABLE accounting_transactions
  ADD COLUMN payment_reference VARCHAR(255) NULL AFTER matched_by;

CREATE INDEX idx_invoices_stripe_charge ON invoices(stripe_charge_id);
CREATE INDEX idx_at_payment_reference   ON accounting_transactions(payment_reference);
