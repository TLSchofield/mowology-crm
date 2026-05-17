-- Migration 1036: Stripe Autopay
-- Adds autopay infrastructure to contacts, job_plans, and a new autopay_attempts audit table.
--
-- Run once. Safe to re-inspect: all ALTER TABLEs add new columns; CREATE TABLE is new.
-- Next migration: 1037

-- ── contacts: payment method ID + autopay consent ────────────────────────────
ALTER TABLE contacts
  ADD COLUMN stripe_payment_method_id VARCHAR(100)  NULL DEFAULT NULL
    AFTER stripe_card_exp,
  ADD COLUMN autopay_enabled          TINYINT(1)    NOT NULL DEFAULT 0
    AFTER stripe_payment_method_id,
  ADD COLUMN autopay_enrolled_at      TIMESTAMP     NULL DEFAULT NULL
    AFTER autopay_enabled;

CREATE INDEX idx_contacts_stripe_pm ON contacts (stripe_payment_method_id);

-- ── job_plans: per-plan autopay override ─────────────────────────────────────
-- NULL = inherit from contact.autopay_enabled
-- 1    = always autopay for this plan
-- 0    = never autopay for this plan (manual invoice only)
ALTER TABLE job_plans
  ADD COLUMN autopay_override TINYINT(1) NULL DEFAULT NULL
    AFTER is_recurring;

-- ── autopay_attempts: off-session charge audit trail ─────────────────────────
CREATE TABLE autopay_attempts (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id         INT          NOT NULL,
  contact_id         INT          NOT NULL,
  payment_intent_id  VARCHAR(100) NULL,
  amount_cents       INT          NOT NULL,
  status             ENUM(
                       'pending',
                       'succeeded',
                       'failed',
                       'authentication_required',
                       'skipped'
                     )            NOT NULL DEFAULT 'pending',
  failure_code       VARCHAR(100) NULL,
  failure_message    TEXT         NULL,
  attempt_number     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  next_retry_at      TIMESTAMP    NULL DEFAULT NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_invoice_id (invoice_id),
  INDEX idx_status     (status),
  INDEX idx_next_retry (next_retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
