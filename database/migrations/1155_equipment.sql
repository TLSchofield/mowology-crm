-- Migration 1155: Equipment register — every mower, trimmer, blower, battery pack and truck
-- Date: 2026-10-05
-- Purpose: one row per item. Cost is NOT stored twice: link the purchase receipt
--   (expense_id → Penny's expenses / ledger) and the register reads it; cost_manual is
--   only for items bought before the CRM. cca_class is recorded for the accountant, not
--   computed. Truck rows carry range/reserve for the Might-E day check (odometer from
--   vehicle_trip_reports.vehicle_id); battery packs carry their runtime when new.
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS equipment (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  equipment_class VARCHAR(20) NOT NULL COMMENT 'mower | trimmer | blower | battery_pack | truck | other',
  make VARCHAR(80) NULL,
  model VARCHAR(80) NULL,
  serial_no VARCHAR(80) NULL,
  power_source VARCHAR(10) NOT NULL DEFAULT 'battery' COMMENT 'gas | battery | electric | diesel',
  assigned_user_id INT NULL COMMENT 'Crew lead who uses it (usage = their job-timer hours)',
  purchase_date DATE NULL,
  expense_id INT NULL COMMENT 'Purchase receipt — cost comes from expenses',
  cost_manual DECIMAL(10,2) NULL COMMENT 'Only when there is no receipt in the CRM',
  cca_class VARCHAR(10) NULL COMMENT 'Recorded for the accountant; not computed',
  hours_baseline DECIMAL(8,1) NOT NULL DEFAULT 0 COMMENT 'Hours on the item when it was registered',
  runtime_new_min INT NULL COMMENT 'Battery pack: minutes per charge when new',
  range_km INT NULL COMMENT 'Truck: battery range, km',
  reserve_pct TINYINT UNSIGNED NULL COMMENT 'Truck: keep this % of range in reserve',
  top_speed_kph INT NULL,
  vehicle_id VARCHAR(30) NULL COMMENT 'Truck: vehicle_trip_reports.vehicle_id for odometer km',
  base_lat DECIMAL(10,7) NULL COMMENT 'Truck: where the day starts and ends (yard)',
  base_lng DECIMAL(10,7) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'active' COMMENT 'active | retired',
  notes VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_eq_class (equipment_class, status),
  INDEX idx_eq_user (assigned_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
