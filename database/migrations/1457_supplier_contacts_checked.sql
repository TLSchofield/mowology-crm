-- Migration 1457: when Otto last looked at a supplier's website for contacts (Tim, 2026-11-15: "Automatically").
-- Plan: docs/plans/2026-11-13-supplier-quote-requests.md. SupplierContactService::nightly() (ridden by trip_runs_daily)
-- looks at new suppliers first, then each one again after 30 days. Code is guarded: before this runs, the nightly pass
-- does nothing and says the migration is needed.
-- Idempotent: the ADD COLUMN checks information_schema first. MySQL 5.7 compatible.

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_accounts' AND COLUMN_NAME = 'contacts_checked_at');
SET @sql = IF(@c = 0, 'ALTER TABLE `supplier_accounts` ADD COLUMN `contacts_checked_at` DATETIME NULL DEFAULT NULL COMMENT ''When Otto last looked at their website for contacts''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
