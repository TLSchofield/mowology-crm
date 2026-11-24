-- Migration 1117: per-shift driver declarations
-- Date: 2026-09-19
-- Purpose: WHO must complete a commercial vehicle trip inspection is decided per SHIFT,
-- not per person. users.is_driver was a permanent flag: an owner who drives some days
-- was never asked (no log for those days) while a flagged employee was forced through
-- the inspection even as a passenger. Each clock-in now records a declaration — driving
-- (and which vehicle) or NOT driving — and it can change mid-shift in either direction.
-- The rows are append-only: the day's sequence of declarations is itself the record of
-- who had the vehicle when.
-- TripReportService degrades gracefully if this has not been run (declarations are
-- simply not recorded; the inspection reports themselves are unaffected).
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS shift_driver_declarations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    shift_date DATE NOT NULL,
    is_driving TINYINT(1) NOT NULL COMMENT '1 = driving a company vehicle, 0 = declared NOT driving',
    vehicle_id VARCHAR(30) NULL COMMENT 'vehicle_trip_reports.vehicle_id; NULL when not driving',
    source VARCHAR(16) NULL COMMENT 'ios | android | web',
    declared_at DATETIME NOT NULL,
    INDEX idx_sdd_user_date (user_id, shift_date),
    INDEX idx_sdd_date (shift_date),
    CONSTRAINT fk_sdd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
