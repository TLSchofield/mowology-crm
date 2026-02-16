-- Migration 502: Add auto-rollover columns
-- Enables automatic rollover of incomplete recurring visits to the next day

-- service_packages: per-service rollover toggle + max days override (used by cron)
ALTER TABLE service_packages
  ADD COLUMN auto_rollover TINYINT(1) NOT NULL DEFAULT 1 AFTER auto_reschedule,
  ADD COLUMN max_rollover_days SMALLINT DEFAULT NULL AFTER auto_rollover;

-- Disable rollover for snow/salt services (doesn't make sense to defer)
UPDATE service_packages SET auto_rollover = 0
  WHERE slug IN ('snow-removal-per-visit', 'snow-removal-seasonal');

-- products: same columns for UI management (products manager)
ALTER TABLE products
  ADD COLUMN auto_rollover TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN max_rollover_days SMALLINT DEFAULT NULL;

-- job_visits: track rollover state
ALTER TABLE job_visits
  ADD COLUMN rollover_count TINYINT NOT NULL DEFAULT 0 AFTER completion_notes,
  ADD COLUMN original_scheduled_date DATE DEFAULT NULL AFTER rollover_count;

-- ops_settings: global default max rollover days
INSERT INTO ops_settings (setting_key, setting_value, description)
VALUES ('max_rollover_days', '3', 'Max days an incomplete visit can roll forward before being auto-skipped')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
