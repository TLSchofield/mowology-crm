-- Migration 934: Add device_type and location_ping_rate to users table
-- Fixes 500 error on clock-in: time-clock API queries these columns on every request
-- but they were never added to the users table via any prior migration.
--
-- device_type: 'personal' (phone, GPS only during jobs) | 'truck' (tablet, continuous GPS)
-- location_ping_rate: 'low' (10 min) | 'medium' (2 min) | 'high' (30 sec)
--
-- MySQL 8.0 compatible — no IF NOT EXISTS on ADD COLUMN (MariaDB-only syntax)

ALTER TABLE users
    ADD COLUMN device_type       VARCHAR(20)  NOT NULL DEFAULT 'personal' AFTER location_tracking_enabled,
    ADD COLUMN location_ping_rate VARCHAR(10) NOT NULL DEFAULT 'high'     AFTER device_type;

-- Seed missing time_clock_settings rows referenced by the API
-- (auto_arrival_enabled, gps_interval_heightened_ms, auto_arrival_service_types)
INSERT IGNORE INTO time_clock_settings (setting_key, setting_value) VALUES
    ('auto_arrival_enabled',       '1'),
    ('gps_interval_heightened_ms', '10000'),
    ('auto_arrival_service_types', '');
