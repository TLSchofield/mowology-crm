-- Migration 1460: same-day recheck of the salt weather decision (Tim, 2026-11-20).
-- The first capture stays untouched (it can be 2-4 days ahead); a second look on the service morning, before the
-- noon cut-off, is written ONCE into these columns. Additive and nullable: no existing reader changes.
-- Code is guarded: before this runs the recheck is simply absent. Idempotent. MySQL 5.7 compatible.

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salt_weather_decisions' AND COLUMN_NAME = 'recheck_at');
SET @sql = IF(@c = 0, 'ALTER TABLE `salt_weather_decisions` ADD COLUMN `recheck_at` DATETIME NULL COMMENT ''Server time of the same-day recheck (once, never overwritten)''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salt_weather_decisions' AND COLUMN_NAME = 'recheck_low_c');
SET @sql = IF(@c = 0, 'ALTER TABLE `salt_weather_decisions` ADD COLUMN `recheck_low_c` DECIMAL(5,2) NULL COMMENT ''Overnight low on the recheck''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salt_weather_decisions' AND COLUMN_NAME = 'recheck_condition');
SET @sql = IF(@c = 0, 'ALTER TABLE `salt_weather_decisions` ADD COLUMN `recheck_condition` VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salt_weather_decisions' AND COLUMN_NAME = 'recheck_source');
SET @sql = IF(@c = 0, 'ALTER TABLE `salt_weather_decisions` ADD COLUMN `recheck_source` VARCHAR(100) NULL COMMENT ''Environment Canada recheck, or the log the value came from''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salt_weather_decisions' AND COLUMN_NAME = 'recheck_raw');
SET @sql = IF(@c = 0, 'ALTER TABLE `salt_weather_decisions` ADD COLUMN `recheck_raw` LONGTEXT NULL COMMENT ''Raw recheck payload, verbatim''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

