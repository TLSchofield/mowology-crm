-- Migration 1156: Equipment service intervals and service log
-- Date: 2026-10-05
-- Purpose: "sharpen blades every 25 h", "change oil every 50 h or yearly" — per class or
--   per item (an item row overrides its class). Otto counts hours since the last logged
--   service from job timers and SUGGESTS a maintenance task; he never creates one alone.
--   No intervals are seeded: they are Tim's numbers.
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS equipment_service_intervals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  equipment_class VARCHAR(20) NULL COMMENT 'Applies to every item of this class…',
  equipment_id INT NULL COMMENT '…or to one item (overrides the class row for the same task)',
  task VARCHAR(60) NOT NULL COMMENT 'blade sharpening | oil | air filter | belt | …',
  every_hours DECIMAL(6,1) NULL,
  every_days INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_esi_class (equipment_class),
  INDEX idx_esi_item (equipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS equipment_service_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  equipment_id INT NOT NULL,
  task VARCHAR(60) NOT NULL,
  done_on DATE NOT NULL,
  task_id INT NULL COMMENT 'tasks.id when it came from Otto''s suggestion',
  expense_id INT NULL COMMENT 'Parts/labour receipt, if any',
  note VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_esl_item (equipment_id, task, done_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
