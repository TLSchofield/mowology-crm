-- Migration 1116: GPS tracking — consent record, device health, device-time pings
-- Date: 2026-09-19
-- Purpose (2026-09-19 GPS audit, Phase 1):
--   * tracking_consents      — WHO agreed to WHAT disclosure text and WHEN. Until now
--                              users.location_tracking_enabled was an admin toggle the
--                              employee never touched; there was no consent record at all.
--   * device_tracking_health — last-known permission/battery/app state per device, so the
--                              office can see "clocked in but silent" instead of guessing.
--   * crew_location_history  — point_uuid (idempotent batch replay), received_at (so
--                              `timestamp` can finally be the DEVICE fix time), and the
--                              fields needed to judge a fix: speed, heading, mock flag, tier.
-- Code is guarded: TrackingIngestService probes for these and degrades gracefully, so
-- deploying before this runs is safe (consent simply cannot be required until it has).
-- MySQL 5.7 compatible: no JSON columns, no IF NOT EXISTS on ALTER/INDEX.

CREATE TABLE IF NOT EXISTS tracking_consents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    disclosure_version VARCHAR(16) NOT NULL COMMENT 'Version of the disclosure text shown — see TrackingConsentService::DISCLOSURE_VERSION',
    consented_at DATETIME NOT NULL,
    withdrawn_at DATETIME NULL DEFAULT NULL,
    platform VARCHAR(16) NULL COMMENT 'ios | android | web',
    device_id VARCHAR(64) NULL,
    app_version VARCHAR(24) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tc_user (user_id, withdrawn_at),
    CONSTRAINT fk_tc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS device_tracking_health (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    device_id VARCHAR(64) NOT NULL,
    platform VARCHAR(16) NULL,
    app_version VARCHAR(24) NULL,
    location_permission VARCHAR(16) NULL COMMENT 'always | when_in_use | denied | unknown',
    precise_location TINYINT(1) NULL,
    battery_optimization_exempt TINYINT(1) NULL COMMENT 'Android only',
    low_power_mode TINYINT(1) NULL,
    battery_percent TINYINT UNSIGNED NULL,
    queue_depth INT UNSIGNED NULL COMMENT 'Fixes waiting on the device',
    last_point_at DATETIME NULL COMMENT 'Device time of the newest accepted fix',
    last_seen_at DATETIME NOT NULL COMMENT 'Server time of the last report',
    silent_alerted_at DATETIME NULL COMMENT 'Last time the office was alerted this device went quiet',
    UNIQUE INDEX idx_dth_user_device (user_id, device_id),
    INDEX idx_dth_seen (last_seen_at),
    CONSTRAINT fk_dth_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE crew_location_history
    ADD COLUMN point_uuid CHAR(36) NULL DEFAULT NULL COMMENT 'Client-generated id — makes batch replay idempotent',
    ADD COLUMN received_at DATETIME NULL DEFAULT NULL COMMENT 'Server receipt time; `timestamp` is the device fix time',
    ADD COLUMN speed_mps DECIMAL(6,2) NULL DEFAULT NULL,
    ADD COLUMN heading_deg SMALLINT NULL DEFAULT NULL,
    ADD COLUMN is_mock TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Device reported a simulated/mock location',
    ADD COLUMN tier VARCHAR(12) NULL DEFAULT NULL COMMENT 'baseline | enhanced';

CREATE UNIQUE INDEX idx_clh_point_uuid ON crew_location_history (crew_id, point_uuid);

INSERT INTO time_clock_settings (setting_key, setting_value)
SELECT 'tracking_consent_required', '0' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM time_clock_settings WHERE setting_key = 'tracking_consent_required');

INSERT INTO time_clock_settings (setting_key, setting_value)
SELECT 'auto_arrival_lead_minutes', '240' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM time_clock_settings WHERE setting_key = 'auto_arrival_lead_minutes');
