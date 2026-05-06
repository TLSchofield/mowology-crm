-- Migration 1030 — Payment flow v2 columns
--
-- Purpose: Support the new "PaymentIntent created at invoice send" flow
-- without breaking the existing "PaymentIntent created on customer click"
-- flow. All columns are nullable / defaulted so v1 code paths ignore them.
--
-- Owned by InvoiceService (app/Modules/Invoices/Services/InvoiceService.php).
--
-- Idempotent — see public/crm/api/run-migration-1030.php for the runner.

ALTER TABLE invoices
  ADD COLUMN stripe_client_secret VARCHAR(255) NULL DEFAULT NULL
  COMMENT 'Stripe PaymentIntent client_secret created at invoice send (v2 flow)';

ALTER TABLE invoices
  ADD COLUMN payment_flow_version TINYINT NOT NULL DEFAULT 1
  COMMENT '1=create PI on customer click (legacy); 2=create PI at invoice send';

ALTER TABLE invoices
  ADD COLUMN pi_created_at DATETIME NULL DEFAULT NULL
  COMMENT 'When the v2 PaymentIntent was created (used to detect staleness)';

CREATE INDEX idx_invoices_payment_flow_version ON invoices(payment_flow_version);
