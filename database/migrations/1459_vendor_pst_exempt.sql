-- Migration 1459: vendors that do not charge PST (Tim, 2026-11-19: City of Vancouver landfill "doesn't charge PST or GST").
-- Beside vendors.gst_exempt (507). Receipt intake zeroes PST for a flagged vendor so the review screen stops asking for it.
-- Code is guarded: before this runs, no vendor is PST-exempt and nothing else changes.
-- Idempotent: the ADD COLUMN checks information_schema first. MySQL 5.7 compatible.

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'pst_exempt');
SET @sql = IF(@c = 0, 'ALTER TABLE `vendors` ADD COLUMN `pst_exempt` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''Vendor does not charge PST''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
