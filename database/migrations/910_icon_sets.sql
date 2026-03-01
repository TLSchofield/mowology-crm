-- Migration 910: Icon Sets
-- Creates the icon_sets table and adds icon_set_id to products.
-- Icon sets are reusable, named icon collections that can be assigned to any product.

CREATE TABLE IF NOT EXISTS `icon_sets` (
  `id`             INT          NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(100) NOT NULL,
  `icon_base_path` VARCHAR(255) NOT NULL COMMENT 'Web-root-relative path to the icon directory',
  `created_by`     INT          DEFAULT NULL,
  `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_icon_sets_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add icon_set_id to products (no FK constraint — avoids issues if icon_sets is empty or missing)
ALTER TABLE `products` ADD COLUMN `icon_set_id` INT DEFAULT NULL;
ALTER TABLE `products` ADD KEY `idx_products_icon_set_id` (`icon_set_id`);
