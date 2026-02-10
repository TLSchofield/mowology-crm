-- =====================================================
-- MOWOLOGY CRM - Quotes Table Alignment
-- Migration: 008_quotes_table_alignment.sql
--
-- Aligns the quotes table with the columns expected by:
--   quote-workflow.php, quotes/view.php, quotes/create.php,
--   customer/quote.php
--
-- Run this once in phpMyAdmin or MySQL CLI.
-- Safe to run multiple times: uses stored procedure to skip
-- columns that already exist.
-- =====================================================

DELIMITER //

DROP PROCEDURE IF EXISTS mowology_add_quote_columns//

CREATE PROCEDURE mowology_add_quote_columns()
BEGIN
    -- title: quote title/summary
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'title') THEN
        ALTER TABLE `quotes` ADD COLUMN `title` VARCHAR(255) DEFAULT NULL AFTER `quote_number`;
    END IF;

    -- service_type: single service type (varchar, not SET)
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'service_type') THEN
        ALTER TABLE `quotes` ADD COLUMN `service_type` VARCHAR(100) DEFAULT NULL AFTER `title`;
    END IF;

    -- amount: total amount (code uses 'amount'; schema had 'total_amount')
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'amount') THEN
        ALTER TABLE `quotes` ADD COLUMN `amount` DECIMAL(10,2) DEFAULT NULL AFTER `subtotal`;
    END IF;

    -- tax_rate: GST rate (usually 0.05)
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'tax_rate') THEN
        ALTER TABLE `quotes` ADD COLUMN `tax_rate` DECIMAL(5,4) DEFAULT 0.0500 AFTER `amount`;
    END IF;

    -- valid_until: quote expiry date
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'valid_until') THEN
        ALTER TABLE `quotes` ADD COLUMN `valid_until` DATE DEFAULT NULL AFTER `tax_amount`;
    END IF;

    -- notes_customer: customer-facing notes
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'notes_customer') THEN
        ALTER TABLE `quotes` ADD COLUMN `notes_customer` TEXT DEFAULT NULL AFTER `terms`;
    END IF;

    -- notes_internal: internal staff notes
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'notes_internal') THEN
        ALTER TABLE `quotes` ADD COLUMN `notes_internal` TEXT DEFAULT NULL AFTER `notes_customer`;
    END IF;

    -- access_token: for customer portal access
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'access_token') THEN
        ALTER TABLE `quotes` ADD COLUMN `access_token` VARCHAR(128) DEFAULT NULL AFTER `notes_internal`;
    END IF;

    -- token_expires_at: token expiry
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'token_expires_at') THEN
        ALTER TABLE `quotes` ADD COLUMN `token_expires_at` DATETIME DEFAULT NULL AFTER `access_token`;
    END IF;

    -- sent_at: when quote was emailed
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'sent_at') THEN
        ALTER TABLE `quotes` ADD COLUMN `sent_at` DATETIME DEFAULT NULL AFTER `token_expires_at`;
    END IF;

    -- sent_via: delivery method (email, etc.)
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'sent_via') THEN
        ALTER TABLE `quotes` ADD COLUMN `sent_via` VARCHAR(50) DEFAULT NULL AFTER `sent_at`;
    END IF;

    -- viewed_at: when customer first viewed
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'viewed_at') THEN
        ALTER TABLE `quotes` ADD COLUMN `viewed_at` DATETIME DEFAULT NULL AFTER `sent_via`;
    END IF;

    -- view_count: number of times customer viewed
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'view_count') THEN
        ALTER TABLE `quotes` ADD COLUMN `view_count` INT UNSIGNED DEFAULT 0 AFTER `viewed_at`;
    END IF;

    -- accepted_at: acceptance timestamp
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'accepted_at') THEN
        ALTER TABLE `quotes` ADD COLUMN `accepted_at` DATETIME DEFAULT NULL AFTER `view_count`;
    END IF;

    -- accepted_by_name: who signed
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'accepted_by_name') THEN
        ALTER TABLE `quotes` ADD COLUMN `accepted_by_name` VARCHAR(200) DEFAULT NULL AFTER `accepted_at`;
    END IF;

    -- accepted_by_email: signer's email
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'accepted_by_email') THEN
        ALTER TABLE `quotes` ADD COLUMN `accepted_by_email` VARCHAR(255) DEFAULT NULL AFTER `accepted_by_name`;
    END IF;

    -- accepted_ip_address: signer's IP
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'accepted_ip_address') THEN
        ALTER TABLE `quotes` ADD COLUMN `accepted_ip_address` VARCHAR(45) DEFAULT NULL AFTER `accepted_by_email`;
    END IF;

    -- signature_data: base64 signature image
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'signature_data') THEN
        ALTER TABLE `quotes` ADD COLUMN `signature_data` MEDIUMTEXT DEFAULT NULL AFTER `accepted_ip_address`;
    END IF;

    -- signature_timestamp: when signed
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'signature_timestamp') THEN
        ALTER TABLE `quotes` ADD COLUMN `signature_timestamp` DATETIME DEFAULT NULL AFTER `signature_data`;
    END IF;

END//

DELIMITER ;

-- Run the procedure
CALL mowology_add_quote_columns();

-- Clean up
DROP PROCEDURE IF EXISTS mowology_add_quote_columns;

-- Add index on access_token (idempotent)
SET @idx_exists = (SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND INDEX_NAME = 'idx_access_token');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `quotes` ADD INDEX `idx_access_token` (`access_token`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================
-- Also add missing columns to activity_log table
-- (logActivityExtended expects job_id, quote_id, invoice_id)
-- =====================================================

DELIMITER //

DROP PROCEDURE IF EXISTS mowology_add_activity_columns//

CREATE PROCEDURE mowology_add_activity_columns()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'job_id') THEN
        ALTER TABLE `activity_log` ADD COLUMN `job_id` INT DEFAULT NULL AFTER `property_id`;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'quote_id') THEN
        ALTER TABLE `activity_log` ADD COLUMN `quote_id` INT DEFAULT NULL AFTER `job_id`;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'invoice_id') THEN
        ALTER TABLE `activity_log` ADD COLUMN `invoice_id` INT DEFAULT NULL AFTER `quote_id`;
    END IF;
END//

DELIMITER ;

CALL mowology_add_activity_columns();
DROP PROCEDURE IF EXISTS mowology_add_activity_columns;
