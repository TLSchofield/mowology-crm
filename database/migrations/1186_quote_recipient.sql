-- Migration 1186: Quote recipient — quotes go to a "quotes" contact, not the billing contact
-- Date: 2026-10-06
-- Purpose: properties.site_contact_id is the BILLING contact (invoices). For Vancouver
--   Management buildings that is Jodi Peacock, the accountant; quotes must go to Alena
--   Radosovska, who takes them to the property managers. See QuoteRecipientService.
--
--   companies.quote_contact_id     "Quotes go to" for every building the company manages
--   properties.quote_contact_id    per-building override
--   quotes.recipient_chosen        1 = quotes.contact_id was deliberately chosen (picker or
--                                  the settings above) and beats every other source on send
--   quotes.cc_property_manager     per-quote "copy the building's property manager" (default on)
--
-- No foreign keys (contacts can be merged/deleted; code guards a missing contact).
-- Code is guarded: deploying before this runs is safe — new quotes keep going to the site
-- contact, and the settings pickers say the migration is needed.
-- Idempotent: every ADD COLUMN checks information_schema first. MySQL 5.7 compatible.

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'quote_contact_id');
SET @sql = IF(@c = 0, 'ALTER TABLE `companies` ADD COLUMN `quote_contact_id` INT NULL DEFAULT NULL COMMENT ''Quotes go to this contact (not the billing contact)''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND COLUMN_NAME = 'quote_contact_id');
SET @sql = IF(@c = 0, 'ALTER TABLE `properties` ADD COLUMN `quote_contact_id` INT NULL DEFAULT NULL COMMENT ''Per-building override: quotes go to this contact''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'recipient_chosen');
SET @sql = IF(@c = 0, 'ALTER TABLE `quotes` ADD COLUMN `recipient_chosen` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = contact_id deliberately chosen; beats every other recipient source''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'cc_property_manager');
SET @sql = IF(@c = 0, 'ALTER TABLE `quotes` ADD COLUMN `cc_property_manager` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''Copy the building property manager on quote emails''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
