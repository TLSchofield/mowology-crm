-- =====================================================
-- MOWOLOGY CRM - PDF Generation Support
-- Migration: 010_pdf_generation.sql
--
-- Adds PDF versioning columns to quotes/invoices
-- and PDF attachment preference to companies.
--
-- Run AFTER 008_quotes_table_alignment.sql
-- =====================================================

DELIMITER //

DROP PROCEDURE IF EXISTS mowology_add_pdf_columns//

CREATE PROCEDURE mowology_add_pdf_columns()
BEGIN
    -- quotes: pdf_path
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'pdf_path') THEN
        ALTER TABLE `quotes` ADD COLUMN `pdf_path` VARCHAR(255) DEFAULT NULL;
    END IF;

    -- quotes: pdf_version
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'pdf_version') THEN
        ALTER TABLE `quotes` ADD COLUMN `pdf_version` INT UNSIGNED DEFAULT 0 AFTER `pdf_path`;
    END IF;

    -- quotes: pdf_generated_at
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'pdf_generated_at') THEN
        ALTER TABLE `quotes` ADD COLUMN `pdf_generated_at` TIMESTAMP NULL DEFAULT NULL AFTER `pdf_version`;
    END IF;

    -- invoices: pdf_path
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'pdf_path') THEN
        ALTER TABLE `invoices` ADD COLUMN `pdf_path` VARCHAR(255) DEFAULT NULL;
    END IF;

    -- invoices: pdf_version
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'pdf_version') THEN
        ALTER TABLE `invoices` ADD COLUMN `pdf_version` INT UNSIGNED DEFAULT 0 AFTER `pdf_path`;
    END IF;

    -- invoices: pdf_generated_at
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'pdf_generated_at') THEN
        ALTER TABLE `invoices` ADD COLUMN `pdf_generated_at` TIMESTAMP NULL DEFAULT NULL AFTER `pdf_version`;
    END IF;

    -- companies: pref_attach_pdf (default 1 = always attach)
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'pref_attach_pdf') THEN
        ALTER TABLE `companies` ADD COLUMN `pref_attach_pdf` TINYINT(1) NOT NULL DEFAULT 1 AFTER `payment_terms`;
    END IF;
END//

DELIMITER ;

CALL mowology_add_pdf_columns();
DROP PROCEDURE IF EXISTS mowology_add_pdf_columns;
