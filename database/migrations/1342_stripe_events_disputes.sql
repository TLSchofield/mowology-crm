-- Migration 1342: Stripe disputes and refunds reach the books (security review #21, 2026-10-12).
-- stripe_webhook_events: one row per Stripe event handled by StripeEventLedgerService — redelivered
-- events do nothing (idempotent per event id). object_id = dispute id (disputes) or charge id (refunds);
-- amount_cents = the amount newly booked by that event.
-- invoices.dispute_status: NULL | disputed | won | lost — a flag beside status, so nothing that reads
-- invoices.status changes. Until this runs the webhook only logs the events (as before). MySQL 5.7.
CREATE TABLE IF NOT EXISTS stripe_webhook_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id VARCHAR(80) NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  object_id VARCHAR(80) NULL,
  amount_cents INT NOT NULL DEFAULT 0,
  processed_at DATETIME NOT NULL,
  UNIQUE KEY uq_swe_event (event_id),
  KEY idx_swe_object (event_type, object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE invoices ADD COLUMN dispute_status VARCHAR(20) NULL DEFAULT NULL COMMENT 'Stripe chargeback: disputed | won | lost';
ALTER TABLE invoices ADD COLUMN disputed_at DATETIME NULL DEFAULT NULL;
