-- ============================================
-- LOCATION-AWARE JOB CREATION SYSTEM
-- ============================================

-- ─── SPATIAL INDEXING FOR PROPERTIES ───
ALTER TABLE `properties` ADD COLUMN `latitude` DECIMAL(10, 8);
ALTER TABLE `properties` ADD COLUMN `longitude` DECIMAL(11, 8);
ALTER TABLE `properties` ADD COLUMN `location_verified_at` TIMESTAMP NULL;
ALTER TABLE `properties` ADD COLUMN `location_verified_by` INT;

-- Spatial index for efficient nearby queries
ALTER TABLE `properties` ADD SPATIAL INDEX `idx_location` (`latitude`, `longitude`);

-- ─── CREW LOCATION TRACKING ───
CREATE TABLE IF NOT EXISTS `crew_location_history` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `crew_id` INT NOT NULL,
  `latitude` DECIMAL(10, 8),
  `longitude` DECIMAL(11, 8),
  `accuracy_meters` INT,
  `address` VARCHAR(255),
  `job_id` INT,
  `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (`crew_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`),
  INDEX (`crew_id`, `timestamp`),
  INDEX (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─── GEOCODING CACHE ───
CREATE TABLE IF NOT EXISTS `geocoding_cache` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `address` VARCHAR(255) UNIQUE,
  `latitude` DECIMAL(10, 8),
  `longitude` DECIMAL(11, 8),
  `source` ENUM('google_maps', 'manual', 'mapbox') DEFAULT 'google_maps',
  `cached_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NULL,

  INDEX (`address`),
  INDEX (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─── PROPERTY VISIT PATTERNS ───
CREATE TABLE IF NOT EXISTS `property_visit_patterns` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `property_id` INT NOT NULL,
  `crew_id` INT,
  `last_visit_date` DATE,
  `visit_count` INT DEFAULT 0,
  `avg_visit_duration_minutes` INT,
  `recurring_frequency_days` INT,

  FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`crew_id`) REFERENCES `users`(`id`),
  UNIQUE KEY (`property_id`, `crew_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
