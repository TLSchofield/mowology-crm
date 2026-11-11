-- Migration 1141: Sam the Closer — rate card + pricing (step 1)
-- Date: 2026-10-05
-- Purpose: Sam shows "Closer's price" beside each quote's own price and flags lines whose
--   quoted price earns less than the margin floor. He never writes a price and never changes
--   a rate. This migration adds:
--   closer_site_models — minutes per service: 'fit' rows from the weekly recalibration on
--                        Tim's timed visits (+ realised margin = drift), 'manual' rows Tim types.
--   ops_settings closer_* keys — seeded from the CRM's own numbers (INSERT IGNORE: never
--                        overwrites a value Tim has set).
--   properties.edge_linear_ft, properties.obstacle_count — lot complexity, typed by hand for now.
-- The target margin is NOT added: it stays overhead_settings.profit_margin.
-- Code is guarded: the Closer panel shows nothing until closer_site_models exists.
-- MySQL 5.7 compatible: no JSON type, no window functions, guarded ADD COLUMN.

CREATE TABLE IF NOT EXISTS closer_site_models (
  id INT AUTO_INCREMENT PRIMARY KEY,
  service_key VARCHAR(30) NOT NULL         COMMENT 'mow | edge | cleanup | aeration | overseed | hedge | beds',
  source VARCHAR(10) NOT NULL              COMMENT 'fit (weekly recalibration) | manual (Tim)',
  fixed_minutes DECIMAL(8,2) NULL          COMMENT 'Person-minutes per visit regardless of size',
  per_unit_minutes DECIMAL(9,3) NULL       COMMENT 'Person-minutes per 1000 sq ft lawn, or per 100 ft edge/hedge',
  per_obstacle_minutes DECIMAL(8,2) NULL,
  n INT NOT NULL DEFAULT 0                 COMMENT 'Good timed + measured visits behind a fit',
  mae_pct DECIMAL(6,2) NULL                COMMENT 'Mean absolute error of the fit, % of actual minutes',
  realised_margin_pct DECIMAL(6,2) NULL    COMMENT 'Drift: margin actually earned over the last 56 days at the hourly cost',
  realised_n INT NOT NULL DEFAULT 0,
  updated_by INT NULL,
  fitted_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_service_source (service_key, source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Lot complexity -------------------------------------------------------------
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND COLUMN_NAME = 'edge_linear_ft');
SET @sql = IF(@c = 0,
  'ALTER TABLE properties ADD COLUMN edge_linear_ft DECIMAL(10,2) NULL COMMENT ''Edging length along hard surfaces (ft) — Closer''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND COLUMN_NAME = 'obstacle_count');
SET @sql = IF(@c = 0,
  'ALTER TABLE properties ADD COLUMN obstacle_count SMALLINT NULL COMMENT ''Trees, beds, play sets to trim around — Closer''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Fixed settings ---------------------------------------------------------------
INSERT IGNORE INTO ops_settings (setting_key, setting_value, description) VALUES
  ('closer_truck_kmh', '40', 'Closer: Might-E average speed for drive minutes (km/h)'),
  ('closer_detour_factor', '1.4', 'Closer: road distance / straight-line distance'),
  ('closer_calibration_min', '15', 'Closer: good timed + measured visits before a service uses its own fit'),
  ('closer_season_plan', '{"visits":28,"cleanup":2,"aeration":1,"overseed":1,"hedge":2,"beds":4}',
   'Closer: visits per season and how often each seasonal service happens (spreads them per visit)');

-- Hourly cost: no cost factor is a fully loaded cost/hour, so seed from the closest one —
-- the Owner/Manager labour row if there is one, else the highest labour rate — burdened
-- rate when the column exists. The Closer flags this as a seed until Tim changes it.
SET @t = (SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cost_factors');
SET @b = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cost_factors' AND COLUMN_NAME = 'rate_with_burden');
SET @rate = IF(@b > 0, 'COALESCE(NULLIF(rate_with_burden, 0), rate)', 'rate');
SET @sql = IF(@t = 0, 'SELECT 1', CONCAT(
  'INSERT IGNORE INTO ops_settings (setting_key, setting_value, description) ',
  'SELECT ''closer_hourly_cost'', CAST(', @rate, ' AS CHAR), ',
  'LEFT(CONCAT(''Closer: seeded from cost factor "'', factor_name, ''" — labour only, no equipment or overhead. Tim to confirm.''), 255) ',
  'FROM cost_factors WHERE active = 1 AND factor_type = ''labor'' AND unit LIKE ''%hour%'' ',
  'ORDER BY (factor_name LIKE ''%owner%'') DESC, ', @rate, ' DESC LIMIT 1'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Margin floor: target (overhead_settings.profit_margin, default 35) minus 10 points.
SET @t = (SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'overhead_settings');
SET @sql = IF(@t = 0,
  'INSERT IGNORE INTO ops_settings (setting_key, setting_value, description) VALUES (''closer_margin_floor_pct'', ''25'', ''Closer: margin floor (target 35 minus 10 points)'')',
  'INSERT IGNORE INTO ops_settings (setting_key, setting_value, description) SELECT ''closer_margin_floor_pct'', CAST(COALESCE((SELECT setting_value - 10 FROM overhead_settings WHERE setting_key = ''profit_margin'' LIMIT 1), 25) AS CHAR), ''Closer: margin floor = target margin minus 10 points'' FROM DUAL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Minimum visit: the lowest min_price on an active mowing product.
SET @s = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'service_type');
SET @m = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'min_price');
SET @sql = IF(@m = 0, 'SELECT 1', CONCAT(
  'INSERT IGNORE INTO ops_settings (setting_key, setting_value, description) ',
  'SELECT ''closer_min_visit'', CAST(MIN(min_price) AS CHAR), ''Closer: seeded from the lowest mowing product min_price'' ',
  'FROM products WHERE active = 1 AND min_price > 0 AND (name LIKE ''%mow%''',
  IF(@s > 0, ' OR service_type LIKE ''%mow%''', ''), ') HAVING MIN(min_price) IS NOT NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
