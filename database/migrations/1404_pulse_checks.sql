-- Migration 1404: pulse_checks (Ittai, 2026-10-28)
-- Purpose: one row per Pulse check per run (every 5 minutes), so the owner is told when something stops working
-- instead of finding out from a complaint. Pulse (app/Modules/Technology/Services/PulseService.php) checks that each
-- head's card builds, key pages render, the database answers, AI calls are succeeding, nothing is stuck, and that the
-- web server is not returning errors. Two bad runs in a row raise one alert (once per incident) through Charlie's
-- urgent email, and every check shows on Ada's page. Rows older than 14 days are pruned by the cron.
-- Safe to re-run. MySQL 5.7-compatible.

CREATE TABLE IF NOT EXISTS pulse_checks (
    id        BIGINT AUTO_INCREMENT PRIMARY KEY,
    check_key VARCHAR(40)  NOT NULL,
    status    ENUM('ok','warn','fail','unknown') NOT NULL,
    detail    VARCHAR(255) NOT NULL DEFAULT '',
    ms        INT          NOT NULL DEFAULT 0,
    run_at    DATETIME     NOT NULL,
    INDEX idx_pulse_key_time (check_key, run_at),
    INDEX idx_pulse_time (run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
