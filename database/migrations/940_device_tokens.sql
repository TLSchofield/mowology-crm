-- Migration 940: APNs / FCM device token registry
-- Stores push notification tokens per user per device.
-- One active token per user per platform (iOS deactivates old tokens on re-register).

CREATE TABLE IF NOT EXISTS device_tokens (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    device_token   VARCHAR(255) NOT NULL,
    platform       ENUM('ios','android') NOT NULL DEFAULT 'ios',
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_token (user_id, device_token),
    INDEX idx_active_user (user_id, is_active),
    CONSTRAINT fk_dt_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
