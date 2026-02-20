-- ============================================================================
-- Migration 514: Vendor Product → Internal Product Links
-- ============================================================================
-- Purpose: Add a product_id FK to vendor_products so each vendor's catalog
--          item can map to an internal CRM product. Many-to-one: multiple
--          vendor products (e.g., Lawnboy "Garden Mix" + Southlands "Garden Mix")
--          can link to one internal product.
--
-- MySQL 5.7 compatible — run outside of a transaction (DDL auto-commits)
-- ============================================================================

ALTER TABLE `vendor_products`
    ADD COLUMN `product_id` INT NULL COMMENT 'FK to internal products table (many vendor products → one CRM product)' AFTER `ocr_aliases`;

CREATE INDEX `idx_vp_product` ON `vendor_products` (`product_id`);

ALTER TABLE `vendor_products`
    ADD CONSTRAINT `fk_vp_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL;
