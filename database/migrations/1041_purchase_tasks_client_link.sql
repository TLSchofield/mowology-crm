-- Migration 1041 — Purchase Tasks: client + property link
-- ─────────────────────────────────────────────────────────
-- Adds contact_id and property_id to purchase_tasks so a vendor
-- run can be associated with the client/property the supplies
-- are destined for. Both nullable — existing tasks are unaffected.
--
-- Run via: /crm/api/run-migration-1041.php
-- Idempotent: columns, indexes, and FKs are each guarded so re-running
-- is safe if some/all of them were already added out-of-band.

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_tasks' AND COLUMN_NAME = 'contact_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchase_tasks ADD COLUMN contact_id INT NULL DEFAULT NULL AFTER notes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_tasks' AND COLUMN_NAME = 'property_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchase_tasks ADD COLUMN property_id INT NULL DEFAULT NULL AFTER contact_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_tasks' AND INDEX_NAME = 'idx_pt_contact');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE purchase_tasks ADD INDEX idx_pt_contact (contact_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_tasks' AND INDEX_NAME = 'idx_pt_property');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE purchase_tasks ADD INDEX idx_pt_property (property_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_tasks' AND CONSTRAINT_NAME = 'fk_pt_contact' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE purchase_tasks ADD CONSTRAINT fk_pt_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_tasks' AND CONSTRAINT_NAME = 'fk_pt_property' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE purchase_tasks ADD CONSTRAINT fk_pt_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
