-- Migration 1050: vehicle_location_pings
-- Stores GPS pings for fleet vehicles (currently one truck via Trackimo).
-- Source enum future-proofs for the eventual webhook or SQS swap.
--
-- Reads:
--   - getLastKnownPosition  → most-recent row WHERE device_id = ?
--   - getTrailForDate       → all rows WHERE device_id = ? AND DATE(recorded_at) = ?
-- Writes:
--   - savePing              → one row per cron cycle (with dedup on recorded_at)
--
-- No FK to a vehicles table — single truck, device_id hardcoded in secrets.php.

CREATE TABLE vehicle_location_pings (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id     VARCHAR(64)     NOT NULL,
    lat           DECIMAL(10, 7)  NOT NULL,
    lng           DECIMAL(10, 7)  NOT NULL,
    speed_kph     DECIMAL(5, 1)   NULL,
    heading       SMALLINT UNSIGNED NULL,
    battery_pct   TINYINT UNSIGNED  NULL,
    source        ENUM('poll', 'webhook', 'sqs') NOT NULL DEFAULT 'poll',
    recorded_at   DATETIME NOT NULL,
    fetched_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_device_recorded (device_id, recorded_at),
    KEY idx_device_recorded_desc (device_id, recorded_at DESC),
    KEY idx_recorded (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
