-- Migration 1350: Otto's equipment input — snap the plate, link the model to the shared catalogue
-- Date: 2026-10-13
-- Purpose (Tim approved 2026-10-13):
--   A machine is two things. The MODEL (Honda HRC216K3HXCH) is shared knowledge every company with
--   that model benefits from; the UNIT (serial MAKA-2058647, bought on a date for a price, on a truck)
--   is this company's own.
--
-- ITTAI SPLIT, as migration 1331:
--   SHARED (no site_id, no prices, no serials):
--     catalog_products (family 'tools')          the model, matched by brand + model number
--     catalog_attribute_values                   specs and parts (part_blade, part_air_filter, …)
--     catalog_service_intervals (NEW)            maintenance intervals READ FROM THE MANUAL, with the
--                                                manual's words and page — facts, not anyone's choice
--   PRIVATE (this company):
--     equipment.catalog_product_id (NEW)         the unit → its model
--     equipment.truck_id (NEW)                   the truck or trailer it rides on
--     equipment.plate_media_id / photo_media_id  the plate photo and a photo of the machine
--     equipment_service_intervals.catalog_product_id (NEW)
--                                                "every unit of this model" — Tim's numbers (an item
--                                                row beats a model row beats a class row)
-- Nothing is watched until Tim confirms it: a shared interval is only an offer ("Use the manual's
-- intervals") on the machine's page.
-- MySQL 5.7 compatible (no JSON functions, no generated columns, no ADD COLUMN IF NOT EXISTS);
-- safe to re-run.

-- 1. equipment: model link, truck, photos
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment' AND COLUMN_NAME = 'catalog_product_id');
SET @sql = IF(@c > 0, 'SELECT 1',
  'ALTER TABLE equipment
     ADD COLUMN catalog_product_id INT NULL COMMENT ''catalog_products.id (family tools) — the model; shared knowledge hangs off it'' AFTER model,
     ADD COLUMN truck_id INT NULL COMMENT ''equipment.id of the truck or trailer it rides on'' AFTER assigned_user_id,
     ADD COLUMN plate_media_id INT NULL COMMENT ''media_assets.id of the model/serial plate photo'' AFTER notes,
     ADD COLUMN photo_media_id INT NULL COMMENT ''media_assets.id of a photo of the machine'' AFTER plate_media_id,
     ADD INDEX idx_eq_catalog (catalog_product_id),
     ADD INDEX idx_eq_serial (serial_no)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Tim's intervals for every unit of one model
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment_service_intervals' AND COLUMN_NAME = 'catalog_product_id');
SET @sql = IF(@c > 0, 'SELECT 1',
  'ALTER TABLE equipment_service_intervals
     ADD COLUMN catalog_product_id INT NULL COMMENT ''Applies to every unit of this model (catalog_products.id)'' AFTER equipment_id,
     ADD INDEX idx_esi_model (catalog_product_id)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. SHARED: what the manual says, per model
CREATE TABLE IF NOT EXISTS catalog_service_intervals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL COMMENT 'catalog_products.id — the model',
  task VARCHAR(60) NOT NULL,
  task_key VARCHAR(60) NOT NULL COMMENT 'lower-case task, one row per model + task',
  every_hours DECIMAL(6,1) NULL,
  every_days INT NULL,
  page VARCHAR(20) NULL COMMENT 'Manual page the interval was read from',
  quote VARCHAR(300) NULL COMMENT 'The manual''s own words',
  source VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual (OttoManualService, confirmed by a person)',
  times_confirmed INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_csi_task (product_id, task_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='SHARED: maintenance intervals from the maker''s manual, per catalogue model (no prices, no serials)';

-- 4. SHARED: what a tools/equipment model can carry (attribute rows, not columns)
INSERT IGNORE INTO catalog_attribute_defs (family, attr_key, label, type, unit, options, sort_order) VALUES
  ('tools', 'equipment_class', 'Kind of machine', 'enum', NULL, 'mower|trimmer|blower|hedge_trimmer|saw|aerator|battery_pack|truck|trailer|other', 1),
  ('tools', 'power_source', 'Power', 'enum', NULL, 'gas|battery|electric|diesel', 2),
  ('tools', 'model_name', 'Name on the machine', 'text', NULL, NULL, 3),
  ('tools', 'engine', 'Engine', 'text', NULL, NULL, 4),
  ('tools', 'deck_width', 'Cutting width', 'text', 'in', NULL, 5),
  ('tools', 'drive', 'Drive', 'text', NULL, NULL, 6),
  ('tools', 'voltage', 'Voltage', 'number', 'V', NULL, 7),
  ('tools', 'oil_capacity', 'Oil capacity', 'text', NULL, NULL, 8),
  ('tools', 'part_blade', 'Blade part no.', 'text', NULL, NULL, 20),
  ('tools', 'part_air_filter', 'Air filter part no.', 'text', NULL, NULL, 21),
  ('tools', 'part_spark_plug', 'Spark plug part no.', 'text', NULL, NULL, 22),
  ('tools', 'part_oil', 'Engine oil', 'text', NULL, NULL, 23),
  ('tools', 'part_belt', 'Belt part no.', 'text', NULL, NULL, 24),
  ('tools', 'part_line', 'Trimmer line / head', 'text', NULL, NULL, 25),
  ('tools', 'part_chain', 'Chain / bar', 'text', NULL, NULL, 26),
  ('tools', 'part_fuel_filter', 'Fuel filter part no.', 'text', NULL, NULL, 27);
