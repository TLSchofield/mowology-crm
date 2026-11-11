-- Migration 1311: Monday salt & snow statement per billing inbox (2026-10-08).
-- One row per inbox per week = sent once, whichever generate_visits run gets there first.
CREATE TABLE IF NOT EXISTS snow_weekly_statements (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(190) NOT NULL,
    week_start      DATE NOT NULL,
    runs            INT NOT NULL DEFAULT 0,
    total           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sent_at         DATETIME NOT NULL,
    UNIQUE KEY uq_snow_weekly (recipient_email, week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO ops_settings (setting_key, setting_value, description)
VALUES ('snow_weekly_statement_enabled', '1', 'Snow & salt: email each billing inbox a Monday statement of last week''s runs (1 = on, 0 = off)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
