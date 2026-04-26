-- Migration 1017: Pre-trip skip tracking + manager override PIN
-- Adds bypass audit columns to vehicle_trip_reports and seeds the manager PIN setting.
--
-- IMMEDIATE DATA FIX: If Nigel (or any driver) has a stale active clock entry,
-- run the resolveStaleClockEntry() call on their next login — or manually:
--   UPDATE time_clock_entries
--   SET clock_out = DATE_ADD(clock_in, INTERVAL 10 HOUR),
--       total_minutes = 600, status = 'completed',
--       notes = CONCAT(COALESCE(notes,''), ' [manually resolved: stale entry]'),
--       edited_at = NOW()
--   WHERE status = 'active' AND clock_out IS NULL
--     AND DATE(clock_in) < CURDATE();

-- Track pre-trip bypasses on vehicle_trip_reports
ALTER TABLE vehicle_trip_reports
    ADD COLUMN IF NOT EXISTS pre_trip_skipped TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS skip_reason VARCHAR(100) NULL;

-- Manager override PIN (admin sets this in Settings → Staff)
INSERT INTO ops_settings (setting_key, setting_value, updated_at)
VALUES ('manager_override_pin', '', NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();
