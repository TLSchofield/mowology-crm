-- Migration 515: Upload Rate Limiting
-- Tracks per-user receipt upload counts per hour to prevent storage abuse.
-- One row per user per hour window; auto-expires (can be pruned by cron).

CREATE TABLE IF NOT EXISTS upload_rate_limits (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    window_start DATETIME NOT NULL,        -- Truncated to the hour: YYYY-MM-DD HH:00:00
    upload_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uk_user_window (user_id, window_start),
    KEY idx_window_start (window_start),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
