-- =====================================================
-- Migration: 002_property_measurements
-- Description: Add property_measurements table to store
--              area measurements from the measurement tool
-- =====================================================

CREATE TABLE IF NOT EXISTS `property_measurements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `property_id` int NOT NULL,
  `measurement_name` varchar(100) NOT NULL COMMENT 'e.g., Front Lawn, Backyard, Driveway',
  `measurement_type` enum('lawn','garden','driveway','walkway','patio','parking','hedge','other') NOT NULL DEFAULT 'lawn',
  `area_sqft` decimal(12,2) NOT NULL COMMENT 'Area in square feet',
  `area_sqm` decimal(12,2) DEFAULT NULL COMMENT 'Area in square meters',
  `perimeter_ft` decimal(10,2) DEFAULT NULL COMMENT 'Perimeter in feet',
  `polygon_coords` json DEFAULT NULL COMMENT 'GeoJSON coordinates of the measured area',
  `notes` text,
  `measured_by` int DEFAULT NULL COMMENT 'User who took measurement',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_property` (`property_id`),
  KEY `idx_type` (`measurement_type`),
  KEY `idx_measured_by` (`measured_by`),
  CONSTRAINT `property_measurements_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_measurements_ibfk_2` FOREIGN KEY (`measured_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Add summary fields to properties table for quick access
ALTER TABLE `properties`
  ADD COLUMN IF NOT EXISTS `total_lawn_sqft` decimal(12,2) DEFAULT NULL COMMENT 'Total lawn area from measurements',
  ADD COLUMN IF NOT EXISTS `total_driveway_sqft` decimal(12,2) DEFAULT NULL COMMENT 'Total driveway/walkway area',
  ADD COLUMN IF NOT EXISTS `measurements_updated_at` timestamp NULL DEFAULT NULL;
