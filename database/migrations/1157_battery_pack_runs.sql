-- Migration 1157: Battery pack runs, logged by hand
-- Date: 2026-10-05
-- Purpose: there is no telemetry from the packs, so runs are entered on
--   /crm/ops/equipment.php: how long a pack ran on one charge. When the median of a
--   pack's last 3 runs falls under 70% of its runtime when new, Otto flags it as fading.
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS battery_pack_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  equipment_id INT NOT NULL,
  run_date DATE NOT NULL,
  runtime_min INT NOT NULL,
  ran_flat TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = ran until empty (a fair test)',
  logged_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bpr_item (equipment_id, run_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
