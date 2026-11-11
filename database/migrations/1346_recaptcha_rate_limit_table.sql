-- 1346 — recaptcha_rate_limit as a migration (Fable audit #27, 2026-10-12).
-- RecaptchaVerify and jobFlow-getQuote.php created this table with CREATE TABLE IF NOT EXISTS on
-- every request. MySQL checks the CREATE privilege before it checks existence, so under the new
-- data-only app user that statement fails. The table already exists on production; this file
-- only matters for a fresh database. MySQL 5.7 compatible.
CREATE TABLE IF NOT EXISTS recaptcha_rate_limit (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip            VARCHAR(45)  NOT NULL,
    window_start  DATETIME     NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_ip_window (ip, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
