-- Migration 1189: "Per yard from area" pricing model (mulch, sold in whole cubic yards)
-- Date: 2026-10-06
-- Purpose: Tim's rule for Black Composted Bark Mulch — $175 per yard, 2-yard minimum, spread
--   3 inches deep (1 yd covers 108 sq ft). Yards = max(min_units, ceil(sqft / (27 / (depth/12)))).
--   The maths lives in QuoteCalculator::yardsFromArea() (JS mirrors in quotes/create.php and
--   quote-workflow.php).
--
--   product_pricing_rules.pricing_model  + 'per_yard_area'  (price_per_unit = price per yard)
--   product_pricing_rules.depth_inches   spread depth in inches (NULL = 3)
--   product_pricing_rules.min_units      minimum whole units, i.e. yards (NULL = 2)
--   quote_line_items.unit_type / plan_line_items.unit_type  ENUM -> VARCHAR(20), so a line can
--                                        carry unit 'yd' (the old ENUM had no 'yd'; MySQL 8 strict
--                                        mode rejects it). Existing values are kept as they are.
--
-- Idempotent: every change checks information_schema first. The ENUM is extended from its live
-- COLUMN_TYPE, so any value production has that the repo does not is kept. MySQL 5.7 compatible.
-- Code is guarded: deploying before this runs is safe — the new model just cannot be saved yet.

-- 1. pricing_model ENUM + 'per_yard_area'
SET @ct = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_pricing_rules' AND COLUMN_NAME = 'pricing_model');
SET @sql = IF(@ct IS NOT NULL AND LOWER(LEFT(@ct, 5)) = 'enum(' AND @ct NOT LIKE '%''per_yard_area''%',
  CONCAT('ALTER TABLE `product_pricing_rules` MODIFY COLUMN `pricing_model` ',
         LEFT(@ct, CHAR_LENGTH(@ct) - 1), ',''per_yard_area'') NOT NULL DEFAULT ''per_sqft'''),
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. depth_inches
SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_pricing_rules' AND COLUMN_NAME = 'depth_inches');
SET @sql = IF(@c = 0, 'ALTER TABLE `product_pricing_rules` ADD COLUMN `depth_inches` DECIMAL(5,2) NULL DEFAULT NULL COMMENT ''per_yard_area: spread depth in inches (NULL = 3)''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. min_units
SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_pricing_rules' AND COLUMN_NAME = 'min_units');
SET @sql = IF(@c = 0, 'ALTER TABLE `product_pricing_rules` ADD COLUMN `min_units` INT NULL DEFAULT NULL COMMENT ''per_yard_area: minimum whole yards (NULL = 2)''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. quote_line_items.unit_type ENUM -> VARCHAR(20), keeping its nullability
SET @dt = (SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_line_items' AND COLUMN_NAME = 'unit_type');
SET @nn = (SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_line_items' AND COLUMN_NAME = 'unit_type');
SET @sql = IF(@dt = 'enum',
  CONCAT('ALTER TABLE `quote_line_items` MODIFY COLUMN `unit_type` VARCHAR(20) ', IF(@nn = 'NO', 'NOT NULL', 'NULL'), ' DEFAULT ''each'''),
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. plan_line_items.unit_type ENUM -> VARCHAR(20) (quote lines are copied here on conversion)
SET @dt = (SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_line_items' AND COLUMN_NAME = 'unit_type');
SET @nn = (SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_line_items' AND COLUMN_NAME = 'unit_type');
SET @sql = IF(@dt = 'enum',
  CONCAT('ALTER TABLE `plan_line_items` MODIFY COLUMN `unit_type` VARCHAR(20) ', IF(@nn = 'NO', 'NOT NULL', 'NULL'), ' DEFAULT ''visit'''),
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
