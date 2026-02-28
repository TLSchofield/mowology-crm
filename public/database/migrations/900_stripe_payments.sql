-- ============================================================================
-- Migration 900: Stripe Payments Integration
-- ============================================================================
-- Adds:
--   1. stripe_payments table (idempotent audit trail for online payments)
--   2. stripe_payment_intent_id column on invoices
--   3. Fixes payment_method ENUM to include 'stripe'
--   4. Fixes invoices: payment_date → paid_at (schema uses paid_at; code used payment_date)
-- Safe to run multiple times (uses IF NOT EXISTS / IF column not exists patterns)
-- ============================================================================

-- 1. Add stripe_payment_intent_id to invoices if it doesn't exist
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'invoices'
      AND COLUMN_NAME  = 'stripe_payment_intent_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE invoices ADD COLUMN stripe_payment_intent_id VARCHAR(100) NULL DEFAULT NULL AFTER payment_reference',
    'SELECT "stripe_payment_intent_id already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Expand payment_method ENUM to include stripe
--    (MySQL ALTER TABLE MODIFY COLUMN is safe to re-run with the same definition)
ALTER TABLE invoices
    MODIFY COLUMN payment_method
        ENUM('cash', 'cheque', 'e_transfer', 'credit_card', 'stripe', 'other') NULL DEFAULT NULL;

-- 3. Create stripe_payments audit table
CREATE TABLE IF NOT EXISTS stripe_payments (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id              INT NOT NULL,
    payment_intent_id       VARCHAR(100) NOT NULL COMMENT 'Stripe PaymentIntent ID (pi_...)',
    payment_method_type     VARCHAR(50)  NULL COMMENT 'card, link, etc.',
    amount_cents            INT NOT NULL COMMENT 'Amount in cents as received from Stripe',
    currency                VARCHAR(10)  NOT NULL DEFAULT 'cad',
    status                  ENUM('created','succeeded','failed','cancelled') NOT NULL DEFAULT 'created',
    stripe_customer_id      VARCHAR(100) NULL,
    stripe_charge_id        VARCHAR(100) NULL COMMENT 'ch_... or py_... from succeeded event',
    stripe_receipt_url      VARCHAR(500) NULL,
    raw_event_type          VARCHAR(100) NULL COMMENT 'Stripe event type that triggered this record',
    failure_code            VARCHAR(100) NULL,
    failure_message         TEXT NULL,
    webhook_received_at     TIMESTAMP NULL COMMENT 'When webhook was processed',
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Idempotency: one row per PaymentIntent
    UNIQUE KEY uq_payment_intent (payment_intent_id),
    INDEX idx_invoice (invoice_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at),

    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Audit trail for all Stripe payment attempts';
