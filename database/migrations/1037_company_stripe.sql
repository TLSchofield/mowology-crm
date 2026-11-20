-- 1037_company_stripe.sql
-- Add Stripe payment columns to companies for company-level autopay.
-- Invoices linked to a company will use the company card; personal/contact-only
-- invoices continue to use the contact card. No existing data is changed.
-- Idempotent: each column is guarded so re-running is safe if some/all
-- columns were already added out-of-band.

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'stripe_customer_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE companies ADD COLUMN stripe_customer_id VARCHAR(100) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'stripe_payment_method_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE companies ADD COLUMN stripe_payment_method_id VARCHAR(100) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'stripe_card_brand');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE companies ADD COLUMN stripe_card_brand VARCHAR(20) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'stripe_card_last4');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE companies ADD COLUMN stripe_card_last4 VARCHAR(4) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'stripe_card_exp');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE companies ADD COLUMN stripe_card_exp VARCHAR(7) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'autopay_enabled');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE companies ADD COLUMN autopay_enabled TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'autopay_enrolled_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE companies ADD COLUMN autopay_enrolled_at TIMESTAMP NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
