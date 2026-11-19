-- Migration 1154: Which equipment each service type uses
-- Date: 2026-10-05
-- Purpose: the bylaw check needs to know whether a visit runs a leaf blower; the
--   maintenance count needs to know which visits wear a mower. Tim ticks these on
--   /crm/ops/municipal-rules.php. A service type with no rows is treated as general
--   power equipment (no blower) until he does.
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS service_equipment (
  id INT AUTO_INCREMENT PRIMARY KEY,
  service_type VARCHAR(80) NOT NULL COMMENT 'job_plans.service_type, compared case-insensitively',
  equipment_class VARCHAR(20) NOT NULL COMMENT 'mower | trimmer | blower | other',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_se (service_type, equipment_class)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
