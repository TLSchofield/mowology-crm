-- Migration 202: Weather-Aware Scheduling System
-- Adds weather policy to service_packages, weather eval to job_visits,
-- ops_settings table, and weather_action_log dedup/audit table.
-- MySQL 5.7 compatible.

-- ============================================================================
-- A) ops_settings — key-value table for operational configuration
-- ============================================================================
CREATE TABLE IF NOT EXISTS ops_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(100) NOT NULL,
  setting_value LONGTEXT,
  description VARCHAR(255),
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by INT NULL,
  UNIQUE KEY uq_setting_key (setting_key),
  INDEX idx_key (setting_key),
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default weather ops constraints
INSERT IGNORE INTO ops_settings (setting_key, setting_value, description) VALUES (
  'weather_ops_constraints',
  '{"default_max_precip_chance_pct":50,"default_max_precip_mm_per_hr":2.5,"default_min_temp_c":-5,"default_max_temp_c":40,"default_max_wind_kph":50,"borderline_precip_chance_low":30,"borderline_precip_chance_high":50,"default_move_window_hours":48,"default_timeband_start":"07:00","default_timeband_end":"18:00","auto_reschedule_enabled":false,"swap_suggestions_enabled":true,"decision_time":"12:00","lookahead_days":2}',
  'Weather operations constraints: thresholds, borderline bands, reschedule defaults'
);

-- ============================================================================
-- B) service_packages — add weather policy columns
-- ============================================================================
ALTER TABLE service_packages
  ADD COLUMN weather_policy VARCHAR(20) NOT NULL DEFAULT 'ANY',
  ADD COLUMN max_precip_chance_pct TINYINT UNSIGNED NULL,
  ADD COLUMN max_precip_mm_per_hr DECIMAL(6,2) NULL,
  ADD COLUMN min_temp_c DECIMAL(5,2) NULL,
  ADD COLUMN max_temp_c DECIMAL(5,2) NULL,
  ADD COLUMN max_wind_kph DECIMAL(6,2) NULL,
  ADD COLUMN move_window_hours SMALLINT UNSIGNED NULL,
  ADD COLUMN move_timeband_start TIME NULL,
  ADD COLUMN move_timeband_end TIME NULL,
  ADD COLUMN auto_reschedule TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN require_manual_if_uncertain TINYINT(1) NOT NULL DEFAULT 1;

-- ============================================================================
-- C) job_visits — add weather evaluation fields
-- ============================================================================
ALTER TABLE job_visits
  ADD COLUMN weather_ok TINYINT(1) NULL,
  ADD COLUMN weather_status VARCHAR(20) NULL,
  ADD COLUMN weather_reason VARCHAR(100) NULL,
  ADD COLUMN weather_decision_at DATETIME NULL,
  ADD COLUMN weather_snapshot_raw LONGTEXT NULL,
  ADD COLUMN weather_card_path VARCHAR(255) NULL;

ALTER TABLE job_visits
  ADD INDEX idx_weather_status (weather_status);

-- ============================================================================
-- D) weather_action_log — dedup + audit table
-- ============================================================================
CREATE TABLE IF NOT EXISTS weather_action_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  action_date DATE NOT NULL,
  action_type VARCHAR(30) NOT NULL,
  entity_type VARCHAR(20) NOT NULL,
  entity_id INT NOT NULL,
  details LONGTEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_by INT NULL,
  UNIQUE KEY uq_action_dedup (action_date, action_type, entity_type, entity_id),
  INDEX idx_entity (entity_type, entity_id),
  INDEX idx_date (action_date),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT 'Migration 202: Weather scheduling tables created successfully' AS status;
