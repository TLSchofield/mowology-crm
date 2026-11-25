-- Migration 1380: Invoice refunds (Stripe)
-- Purpose: records refunds issued from the invoice page and the running refunded total.
--
-- One row per refund in invoice_refunds. invoices.amount_refunded is the running
-- total; the cash-basis ledger income row for the invoice is paid minus refunded
-- (see InvoiceRefundService). amount_paid is NOT reduced, so a fully refunded
-- invoice stays "paid" with a $0 balance and no reminders fire.
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS, and the ALTER is guarded by an
-- information_schema check. MySQL 5.7-compatible.

CREATE TABLE IF NOT EXISTS invoice_refunds (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id       INT NOT NULL,
    stripe_refund_id VARCHAR(255) NULL,
    amount           DECIMAL(10,2) NOT NULL,
    reason           VARCHAR(500) NOT NULL,
    status           ENUM('pending','succeeded','failed') NOT NULL DEFAULT 'pending',
    error_message    VARCHAR(500) NULL,
    created_by       INT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoice_refund_stripe (stripe_refund_id),
    INDEX idx_invoice_refunds_invoice (invoice_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'invoices'
      AND COLUMN_NAME  = 'amount_refunded'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE invoices ADD COLUMN amount_refunded DECIMAL(10,2) NOT NULL DEFAULT 0.00',
    'SELECT "amount_refunded already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
