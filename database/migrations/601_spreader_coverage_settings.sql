-- Migration 601: Spreader Coverage Settings
-- Adds structured coverage rate and Scotts spreader setting to products.
-- Enables auto-calculation of total material needed based on lawn area.
--
-- Example: 1 bag of lime covers 1000 sq ft at Scotts spreader setting 8
-- For a 1,500 sq ft lawn → 1.5 bags, set spreader to 8
--
-- Run via: /crm/database_appstack.php → Migrations tab, or manually.

DROP PROCEDURE IF EXISTS _add_coverage_sqft_per_unit;
DELIMITER $$
CREATE PROCEDURE _add_coverage_sqft_per_unit()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'products'
          AND COLUMN_NAME  = 'coverage_sqft_per_unit'
    ) THEN
        ALTER TABLE products
            ADD COLUMN coverage_sqft_per_unit DECIMAL(10,2) NULL
                COMMENT 'sq ft covered by one unit (bag/lb/etc). Used to calculate total needed for a given lawn size.'
                AFTER application_rate;
    END IF;
END$$
DELIMITER ;
CALL _add_coverage_sqft_per_unit();
DROP PROCEDURE IF EXISTS _add_coverage_sqft_per_unit;

DROP PROCEDURE IF EXISTS _add_scotts_spreader_setting;
DELIMITER $$
CREATE PROCEDURE _add_scotts_spreader_setting()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'products'
          AND COLUMN_NAME  = 'scotts_spreader_setting'
    ) THEN
        ALTER TABLE products
            ADD COLUMN scotts_spreader_setting DECIMAL(4,1) NULL
                COMMENT 'Scotts broadcast spreader setting (1–12) for this product'
                AFTER coverage_sqft_per_unit;
    END IF;
END$$
DELIMITER ;
CALL _add_scotts_spreader_setting();
DROP PROCEDURE IF EXISTS _add_scotts_spreader_setting;
