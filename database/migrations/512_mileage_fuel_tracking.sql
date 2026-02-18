-- ============================================================================
-- Migration 512: Mileage and fuel tracking columns on expenses
-- ============================================================================
-- Purpose: When category = 'Fuel', these fields capture odometer readings
--          and fuel details for CRA-compliant vehicle expense tracking.
--
--          Auto-calculates: distance = odometer_end - odometer_start
--          Fuel economy: fuel_litres / (distance / 100) = L/100km
--
-- MySQL 5.7 compatible — run outside of a transaction (DDL auto-commits)
-- ============================================================================

ALTER TABLE `expenses` ADD COLUMN `odometer_start` int UNSIGNED NULL
    COMMENT 'Starting odometer reading (km) — for fuel/mileage tracking'
    AFTER `notes`;

ALTER TABLE `expenses` ADD COLUMN `odometer_end` int UNSIGNED NULL
    COMMENT 'Ending odometer reading (km) — for fuel/mileage tracking'
    AFTER `odometer_start`;

ALTER TABLE `expenses` ADD COLUMN `fuel_litres` decimal(8,2) NULL
    COMMENT 'Litres of fuel purchased'
    AFTER `odometer_end`;

ALTER TABLE `expenses` ADD COLUMN `fuel_price_per_litre` decimal(6,3) NULL
    COMMENT 'Price per litre at pump (e.g., 1.699)'
    AFTER `fuel_litres`;
