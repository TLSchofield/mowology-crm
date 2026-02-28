-- ============================================================================
-- Migration 603: compliance_events table
-- ============================================================================
-- Moves the compliance_events DDL from runtime (tracking-sync.php used
-- CREATE TABLE IF NOT EXISTS inside every API request) to a proper migration.
--
-- Safe to run on production: IF NOT EXISTS guards prevent errors if the
-- table was already created by the old runtime DDL.
-- ============================================================================

CREATE TABLE IF NOT EXISTS compliance_events (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT          NOT NULL,
    event_type       VARCHAR(50)  NOT NULL,
    latitude         DECIMAL(10,8) DEFAULT 0,
    longitude        DECIMAL(11,8) DEFAULT 0,
    accuracy_meters  INT           DEFAULT NULL,
    visit_id         INT           DEFAULT NULL,
    job_id           INT           DEFAULT NULL,
    reason           VARCHAR(255)  DEFAULT NULL,
    metadata         TEXT          DEFAULT NULL,
    device_timestamp DATETIME      NOT NULL,
    created_at       DATETIME      NOT NULL,

    INDEX idx_compliance_user  (user_id),
    INDEX idx_compliance_type  (event_type),
    INDEX idx_compliance_visit (visit_id),
    INDEX idx_compliance_time  (device_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
