-- Migration 1031: Job time entry anomaly flags
-- Adds flagged_anomaly and auto_stopped columns to job_time_entries
-- so the shift-bounding cap and orphaned-timer auto-stop are auditable.
-- Safe: additive only, existing rows default to 0.

ALTER TABLE job_time_entries
    ADD COLUMN flagged_anomaly TINYINT NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN auto_stopped    TINYINT NOT NULL DEFAULT 0 AFTER flagged_anomaly;

ALTER TABLE job_time_entries
    ADD INDEX idx_jte_anomaly (flagged_anomaly),
    ADD INDEX idx_jte_auto_stopped (auto_stopped);
