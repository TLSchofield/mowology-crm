-- Migration 1458: let CRM service packages feed the public website listings (Tim, 2026-11-15: "yes, both" —
-- CRM is the source, prices show publicly). Columns only; no rows are flagged here, because migrations 1445+ apply to
-- every business and each business's packages differ. Tim switches listings on per package in the CRM editor.
-- Idempotent: each ADD COLUMN checks information_schema first. MySQL 5.7 compatible.

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_packages' AND COLUMN_NAME = 'web_visible');
SET @sql = IF(@c = 0, 'ALTER TABLE `service_packages` ADD COLUMN `web_visible` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Show this package on the public website service pages''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_packages' AND COLUMN_NAME = 'web_slug');
SET @sql = IF(@c = 0, 'ALTER TABLE `service_packages` ADD COLUMN `web_slug` VARCHAR(100) NULL DEFAULT NULL COMMENT ''Public page slug this package feeds, e.g. spring-cleanup''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_packages' AND COLUMN_NAME = 'web_headline');
SET @sql = IF(@c = 0, 'ALTER TABLE `service_packages` ADD COLUMN `web_headline` VARCHAR(255) NULL DEFAULT NULL COMMENT ''Short public headline; blank = use the package name''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_packages' AND COLUMN_NAME = 'web_bullets');
SET @sql = IF(@c = 0, 'ALTER TABLE `service_packages` ADD COLUMN `web_bullets` TEXT NULL COMMENT ''Public bullet points, one per line''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_packages' AND COLUMN_NAME = 'show_web_price');
SET @sql = IF(@c = 0, 'ALTER TABLE `service_packages` ADD COLUMN `show_web_price` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''Show the package base price on the public page as From $X''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- One public page per web slug. NULLs are allowed to repeat in a MySQL UNIQUE index, so unused packages are fine.
SET @c = (SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_packages' AND INDEX_NAME = 'uq_service_packages_web_slug');
SET @sql = IF(@c = 0, 'ALTER TABLE `service_packages` ADD UNIQUE KEY `uq_service_packages_web_slug` (`web_slug`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
