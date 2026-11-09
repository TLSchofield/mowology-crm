-- Supplier lists import for ANY supplier (2026-10-20, Tim: "these email lists are meant to be imported automatically").
-- A first list from an unknown sender is read by SupplierFormatInferrer, imported, and held for one
-- "looks right" tap. Until then its lists stay out of quote pricing (supplier_price_lists.needs_review).
-- MySQL 5.7 safe. One ADD COLUMN per statement so the runner can skip any that already exist.
ALTER TABLE supplier_list_formats ADD COLUMN needs_review TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = read automatically, not yet confirmed by a person';
ALTER TABLE supplier_list_formats ADD COLUMN auto_inferred TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = spec written by SupplierFormatInferrer, not by hand';
ALTER TABLE supplier_list_formats ADD COLUMN inferred_confidence DECIMAL(3,2) NULL;
ALTER TABLE supplier_price_lists ADD COLUMN needs_review TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'copied from the format at import, cleared when the format is confirmed';

-- PRIVATE (site_id): lists that arrived but could not be read, so nothing is skipped silently.
CREATE TABLE IF NOT EXISTS supplier_list_skips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  supplier_account_id INT NULL,
  sender VARCHAR(190) NULL,
  subject VARCHAR(255) NULL,
  file_name VARCHAR(255) NOT NULL DEFAULT '',
  reason VARCHAR(255) NOT NULL,
  message_key VARCHAR(191) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  dismissed_at DATETIME NULL,
  UNIQUE KEY uq_list_skip (site_id, message_key, file_name),
  KEY idx_list_skip_open (site_id, dismissed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
