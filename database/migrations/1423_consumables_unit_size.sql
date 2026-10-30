-- Consumables catalogue family and normalized package size (MySQL 5.7).
-- The unique slug makes the INSERT IGNORE ... SELECT idempotent.
INSERT IGNORE INTO catalog_families (slug, name, matcher, default_use_kind, sort_order)
SELECT 'consumables', 'Consumables', 'name', 'consumable', COALESCE(MAX(sort_order), 0) + 1
FROM catalog_families;

SET @has_unit_size := (SELECT COUNT(*) FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'unit_size');
SET @sql := IF(@has_unit_size = 0,
    'ALTER TABLE catalog_products ADD COLUMN unit_size DECIMAL(10,3) NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_unit_size_unit := (SELECT COUNT(*) FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'unit_size_unit');
SET @sql := IF(@has_unit_size_unit = 0,
    'ALTER TABLE catalog_products ADD COLUMN unit_size_unit VARCHAR(8) NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
