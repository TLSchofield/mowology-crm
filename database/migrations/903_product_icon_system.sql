-- ============================================================================
-- Migration 502: Product Icon System
-- ============================================================================
-- Adds icon generation and sold-state display support to the products table.
--
-- icon_base_path: web-root-relative path to the generated icon directory
--                e.g. /uploads/products/icons/5/
-- is_sold:       0 = show greyscale (unsold/unavailable) icon
--                1 = show full colour (sold/available) icon
--
-- MySQL 5.7 compatible — run outside of a transaction (DDL auto-commits)
-- ============================================================================

ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `icon_base_path` VARCHAR(255) DEFAULT NULL
    COMMENT 'Web-root-relative path to generated icon set directory',
  ADD COLUMN IF NOT EXISTS `is_sold` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '0 = greyscale/unsold display, 1 = full-colour/sold display';
