-- Migration 1461: weather snapshot history + station observations (Tim, 2026-11-20).
-- Every forecast we pull is kept (per run, per source, per day) and the Vancouver airport station reading is kept
-- hourly, so a salt decision can show the whole trail behind it and what the station actually recorded.
-- Additive: no existing reader changes. Idempotent. MySQL 5.7 compatible, utf8mb4_general_ci.

CREATE TABLE IF NOT EXISTS `weather_forecast_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `captured_at` DATETIME NOT NULL COMMENT 'Server time (America/Vancouver) of the run',
  `run_label` VARCHAR(20) NOT NULL COMMENT 'morning | evening | scheduled | manual',
  `source` VARCHAR(40) NOT NULL COMMENT 'Environment Canada | Open-Meteo | MET Norway',
  `target_date` DATE NOT NULL,
  `low_c` DECIMAL(5,2) NULL,
  `high_c` DECIMAL(5,2) NULL,
  `condition_text` VARCHAR(150) NULL,
  `raw_json` MEDIUMTEXT NULL COMMENT 'The day as the source gave it, unaltered',
  PRIMARY KEY (`id`),
  KEY `idx_wfs_target` (`target_date`, `source`, `captured_at`),
  KEY `idx_wfs_captured` (`captured_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `weather_observations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `observed_at` DATETIME NOT NULL COMMENT 'UTC, as the station reported it',
  `captured_at` DATETIME NOT NULL COMMENT 'Server time (America/Vancouver) we stored it',
  `source` VARCHAR(40) NOT NULL,
  `station` VARCHAR(100) NULL,
  `temp_c` DECIMAL(5,2) NOT NULL,
  `condition_text` VARCHAR(150) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_wo_obs` (`source`, `observed_at`),
  KEY `idx_wo_observed` (`observed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salt_weather_decisions' AND COLUMN_NAME = 'decisive_snapshot_id');
SET @sql = IF(@c = 0, 'ALTER TABLE `salt_weather_decisions` ADD COLUMN `decisive_snapshot_id` BIGINT UNSIGNED NULL COMMENT ''weather_forecast_snapshots.id the morning recheck rests on''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
