-- Migration 900: Cron Run Log
-- Tracks the execution history of all cron jobs so the Database Manager
-- dashboard can display real last-run times, statuses, and durations.
--
-- Each cron script writes a row here after every execution (CLI or web-triggered).

CREATE TABLE IF NOT EXISTS cron_runs (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cron_key      VARCHAR(64)  NOT NULL COMMENT 'Machine key matching dashboard: generate_visits, auto_rollover, etc.',
    triggered_by  ENUM('cron','web') NOT NULL DEFAULT 'cron' COMMENT 'cron = scheduled, web = Run Now button',
    status        ENUM('success','warning','error') NOT NULL DEFAULT 'success',
    duration_ms   INT UNSIGNED NULL     COMMENT 'Wall-clock time in milliseconds',
    summary       VARCHAR(512) NULL     COMMENT 'One-line human-readable outcome',
    error_message TEXT         NULL     COMMENT 'Error detail when status=error',
    ran_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_cron_key_ran_at (cron_key, ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
