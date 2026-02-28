-- =====================================================
-- Migration: 301_pricing_rules_and_bundles
-- Description: Measurement-linked auto-quoting system
--   - Measurement groups (type → pricing group mapping)
--   - Product pricing rules (per_sqft, min_plus_unit, etc.)
--   - Product upsells (base → add-on links)
--   - Product bundles (Good/Better/Best packages)
--   - Extended property_measurements (polyline, group_key)
--   - Extended quote_line_items (pricing snapshot columns)
--   - Extended properties (extra measurement totals)
-- MySQL 5.7 compatible — no JSON functions, no window functions
-- =====================================================

-- ---------------------------------------------------
-- 1. Measurement Groups
-- ---------------------------------------------------
CREATE TABLE IF NOT EXISTS `measurement_groups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `group_key` varchar(50) NOT NULL,
  `group_label` varchar(100) NOT NULL,
  `measurement_types` varchar(500) NOT NULL COMMENT 'Comma-separated property_measurements.measurement_type values',
  `unit` varchar(20) NOT NULL DEFAULT 'sqft' COMMENT 'sqft or linear_ft',
  `sort_order` int DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_group_key` (`group_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `measurement_groups` (`group_key`, `group_label`, `measurement_types`, `unit`, `sort_order`) VALUES
('lawn_area',     'Lawn & Garden Area',  'lawn,garden',                        'sqft',      1),
('hard_surface',  'Hard Surface Area',   'driveway,walkway,patio,parking',     'sqft',      2),
('hedge_linear',  'Hedge / Edge Linear', 'hedge',                              'linear_ft', 3),
('other_area',    'Other Area',          'other',                              'sqft',      4);

-- ---------------------------------------------------
-- 2. Product Pricing Rules
-- ---------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_pricing_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `measurement_group_id` int NOT NULL,
  `pricing_model` enum('flat','per_sqft','per_linear_ft','min_plus_sqft','min_plus_linear_ft') NOT NULL DEFAULT 'per_sqft',
  `price_per_unit` decimal(10,4) DEFAULT 0.0000 COMMENT 'Price per sqft or linear ft',
  `minimum_price` decimal(10,2) DEFAULT 0.00 COMMENT 'Minimum charge regardless of area',
  `included_units` decimal(10,2) DEFAULT 0.00 COMMENT 'Units included in minimum (for min_plus models)',
  `default_frequency` enum('one_off','7_day','14_day','21_day','monthly','seasonal') DEFAULT 'one_off',
  `is_default_for_group` tinyint(1) DEFAULT 0 COMMENT 'Auto-select this product when populating quotes for this group',
  `priority` int DEFAULT 0 COMMENT 'Higher priority wins if multiple rules match',
  `notes` text,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_group` (`measurement_group_id`),
  KEY `idx_default` (`measurement_group_id`, `is_default_for_group`, `is_active`),
  CONSTRAINT `fk_pricing_rules_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pricing_rules_group` FOREIGN KEY (`measurement_group_id`) REFERENCES `measurement_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------
-- 3. Product Upsells
-- ---------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_upsells` (
  `id` int NOT NULL AUTO_INCREMENT,
  `base_product_id` int NOT NULL,
  `upsell_product_id` int NOT NULL,
  `upsell_type` enum('recommended','addon','upgrade') DEFAULT 'recommended',
  `display_text` varchar(255) DEFAULT NULL COMMENT 'Override text shown to customer',
  `default_checked` tinyint(1) DEFAULT 0 COMMENT 'Pre-selected on customer view',
  `sort_order` int DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_upsell` (`base_product_id`, `upsell_product_id`),
  KEY `idx_base` (`base_product_id`),
  KEY `idx_upsell` (`upsell_product_id`),
  CONSTRAINT `fk_upsell_base` FOREIGN KEY (`base_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_upsell_product` FOREIGN KEY (`upsell_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------
-- 4. Product Bundles
-- ---------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_bundles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `bundle_name` varchar(200) NOT NULL,
  `tier` enum('good','better','best','custom') DEFAULT 'custom',
  `description` text,
  `discount_type` enum('percentage','fixed') DEFAULT 'percentage',
  `discount_value` decimal(10,2) DEFAULT 0.00 COMMENT 'Percentage off or fixed dollar off',
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------
-- 5. Product Bundle Items
-- ---------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_bundle_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `bundle_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity_multiplier` decimal(10,2) DEFAULT 1.00 COMMENT 'Multiplier against measurement-derived quantity',
  `override_price` decimal(10,2) DEFAULT NULL COMMENT 'NULL = use product pricing rule',
  `sort_order` int DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bundle_item` (`bundle_id`, `product_id`),
  KEY `idx_bundle` (`bundle_id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `fk_bundle_item_bundle` FOREIGN KEY (`bundle_id`) REFERENCES `product_bundles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bundle_item_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------
-- 6. Extend property_measurements
-- ---------------------------------------------------
-- Add polyline shape support + measurement group key
-- Using separate ALTER statements for MySQL 5.7 IF NOT EXISTS compatibility

-- Check and add measurement_shape column
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'property_measurements' AND COLUMN_NAME = 'measurement_shape');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `property_measurements` ADD COLUMN `measurement_shape` enum(''polygon'',''rectangle'',''polyline'') DEFAULT ''polygon'' AFTER `measurement_type`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Check and add linear_ft column
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'property_measurements' AND COLUMN_NAME = 'linear_ft');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `property_measurements` ADD COLUMN `linear_ft` decimal(10,2) DEFAULT NULL AFTER `perimeter_ft`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Check and add measurement_group_key column
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'property_measurements' AND COLUMN_NAME = 'measurement_group_key');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `property_measurements` ADD COLUMN `measurement_group_key` varchar(50) DEFAULT NULL AFTER `measurement_shape`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------
-- 7. Extend quote_line_items — pricing snapshot columns
-- ---------------------------------------------------

-- product_id may already exist on some setups; add if missing
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_line_items' AND COLUMN_NAME = 'product_id');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `quote_line_items` ADD COLUMN `product_id` int DEFAULT NULL AFTER `quote_id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_line_items' AND COLUMN_NAME = 'pricing_rule_id');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `quote_line_items` ADD COLUMN `pricing_rule_id` int DEFAULT NULL AFTER `product_id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_line_items' AND COLUMN_NAME = 'measurement_group_key');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `quote_line_items` ADD COLUMN `measurement_group_key` varchar(50) DEFAULT NULL AFTER `pricing_rule_id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_line_items' AND COLUMN_NAME = 'units_used');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `quote_line_items` ADD COLUMN `units_used` decimal(12,2) DEFAULT NULL COMMENT ''sqft or linear_ft used for calc''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_line_items' AND COLUMN_NAME = 'price_per_unit');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `quote_line_items` ADD COLUMN `price_per_unit` decimal(10,4) DEFAULT NULL COMMENT ''Snapshot of rate at time of quote''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_line_items' AND COLUMN_NAME = 'minimum_applied');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `quote_line_items` ADD COLUMN `minimum_applied` tinyint(1) DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_line_items' AND COLUMN_NAME = 'included_units');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `quote_line_items` ADD COLUMN `included_units` decimal(10,2) DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_line_items' AND COLUMN_NAME = 'pricing_snapshot');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `quote_line_items` ADD COLUMN `pricing_snapshot` text COMMENT ''Full JSON snapshot of rule at quote time''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_line_items' AND COLUMN_NAME = 'bundle_id');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `quote_line_items` ADD COLUMN `bundle_id` int DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_line_items' AND COLUMN_NAME = 'is_upsell');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `quote_line_items` ADD COLUMN `is_upsell` tinyint(1) DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------
-- 8. Extend properties — additional measurement totals
-- ---------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND COLUMN_NAME = 'total_hard_surface_sqft');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `properties` ADD COLUMN `total_hard_surface_sqft` decimal(12,2) DEFAULT 0 AFTER `total_driveway_sqft`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND COLUMN_NAME = 'total_hedge_linear_ft');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `properties` ADD COLUMN `total_hedge_linear_ft` decimal(10,2) DEFAULT 0 AFTER `total_hard_surface_sqft`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND COLUMN_NAME = 'total_other_sqft');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `properties` ADD COLUMN `total_other_sqft` decimal(12,2) DEFAULT 0 AFTER `total_hedge_linear_ft`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------
-- 9. Backfill measurement_group_key on existing data
-- ---------------------------------------------------
UPDATE `property_measurements` SET `measurement_group_key` = 'lawn_area'
  WHERE `measurement_type` IN ('lawn', 'garden') AND `measurement_group_key` IS NULL;

UPDATE `property_measurements` SET `measurement_group_key` = 'hard_surface'
  WHERE `measurement_type` IN ('driveway', 'walkway', 'patio', 'parking') AND `measurement_group_key` IS NULL;

UPDATE `property_measurements` SET `measurement_group_key` = 'hedge_linear'
  WHERE `measurement_type` = 'hedge' AND `measurement_group_key` IS NULL;

UPDATE `property_measurements` SET `measurement_group_key` = 'other_area'
  WHERE `measurement_type` = 'other' AND `measurement_group_key` IS NULL;
