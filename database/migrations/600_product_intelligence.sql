-- Migration 600: Product Intelligence
-- Adds structured application data, SDS sheets, seasonality, and crew talking points to products.
-- Part of the Products → Sales Engine upgrade.
--
-- Run via: /crm/database_appstack.php → Migrations tab, or manually.

-- Structured application data
ALTER TABLE products
  ADD COLUMN sds_sheet_url VARCHAR(500) NULL AFTER image_url,
  ADD COLUMN application_notes TEXT NULL AFTER sds_sheet_url,
  ADD COLUMN best_season VARCHAR(100) NULL COMMENT 'comma-separated months: jan,feb,mar,...' AFTER application_notes,
  ADD COLUMN trigger_month TINYINT NULL COMMENT 'month number (1-12) to trigger marketing campaigns' AFTER best_season,
  ADD COLUMN ph_range_min DECIMAL(3,1) NULL AFTER trigger_month,
  ADD COLUMN ph_range_max DECIMAL(3,1) NULL AFTER ph_range_min,
  ADD COLUMN dilution_rate VARCHAR(100) NULL AFTER ph_range_max,
  ADD COLUMN application_rate VARCHAR(100) NULL AFTER dilution_rate,
  ADD COLUMN safety_warnings TEXT NULL AFTER application_rate,
  ADD COLUMN crew_talking_points TEXT NULL COMMENT 'soft-sell notes for crew to reference on-site' AFTER safety_warnings;
