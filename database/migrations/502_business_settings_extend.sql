-- ============================================================================
-- Migration 502: Extend business_settings — Tax Rates + Regional Config
-- ============================================================================
-- Purpose: Adds tax rate fields (GST/PST rates + show-on-invoice toggle)
--          and regional preference fields (timezone, date/time format,
--          first day of week, country) to the business_settings table.
--          Also seeds the default business hours in ops_settings.
--
-- MySQL 5.7 compatible — run OUTSIDE of a transaction (DDL auto-commits)
-- ============================================================================

-- Tax Settings columns
ALTER TABLE `business_settings`
  ADD COLUMN `gst_rate`              DECIMAL(5,2) NOT NULL DEFAULT 5.00  COMMENT 'GST rate percentage (e.g. 5.00 for 5%)',
  ADD COLUMN `pst_rate`              DECIMAL(5,2) NOT NULL DEFAULT 0.00  COMMENT 'PST rate percentage (0 if not registered)',
  ADD COLUMN `show_tax_on_invoices`  TINYINT(1)   NOT NULL DEFAULT 1     COMMENT 'Show tax line items on invoices and quotes';

-- Regional Settings columns
ALTER TABLE `business_settings`
  ADD COLUMN `country`           VARCHAR(100) NOT NULL DEFAULT 'Canada'             COMMENT 'Country for locale defaults',
  ADD COLUMN `timezone`          VARCHAR(100) NOT NULL DEFAULT 'America/Vancouver'  COMMENT 'PHP timezone identifier',
  ADD COLUMN `date_format`       VARCHAR(20)  NOT NULL DEFAULT 'M j, Y'            COMMENT 'PHP date() format string',
  ADD COLUMN `time_format`       VARCHAR(10)  NOT NULL DEFAULT '12h'               COMMENT '12h or 24h',
  ADD COLUMN `first_day_of_week` TINYINT(1)   NOT NULL DEFAULT 1                   COMMENT '0=Sunday 1=Monday';

-- Seed default business hours in ops_settings (Mon–Fri 08:00–17:00, Sat/Sun closed)
-- ON DUPLICATE KEY protects against re-running the migration
INSERT INTO `ops_settings` (`setting_key`, `setting_value`, `description`)
VALUES (
  'business_hours',
  '{"show_on_invoices":false,"show_on_quotes":false,"hours":{"monday":{"open":true,"start":"08:00","end":"17:00"},"tuesday":{"open":true,"start":"08:00","end":"17:00"},"wednesday":{"open":true,"start":"08:00","end":"17:00"},"thursday":{"open":true,"start":"08:00","end":"17:00"},"friday":{"open":true,"start":"08:00","end":"17:00"},"saturday":{"open":false,"start":"08:00","end":"17:00"},"sunday":{"open":false,"start":"08:00","end":"17:00"}}}',
  'Company business hours — per-day open/close schedule'
)
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
