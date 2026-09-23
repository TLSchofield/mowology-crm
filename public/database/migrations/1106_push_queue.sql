-- Migration 1106: APNs push notification queue
--
-- Queues push notifications for asynchronous delivery.
-- The push-drain cron job reads pending rows and sends them via APNs HTTP/2.
--
-- Design notes:
--   status=pending  → waiting to be sent
--   status=sent     → delivered successfully
--   status=failed   → APNs rejected (bad device token, etc.) — see error_message
--   status=expired  → in queue too long without sending — pruned by cron
--   attempts        → incremented on each send attempt; max 3 before marking failed
--   scheduled_at    → allows future-dated pushes (e.g. "send tomorrow morning")
--
-- Ported from an orphaned local commit that never reached feature/language
-- (see [[project_push_notifications_forward_port]]) — renumbered from the
-- original 941 to continue this branch's migration sequence.

CREATE TABLE IF NOT EXISTS push_queue (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    device_token   VARCHAR(255) NOT NULL,
    platform       ENUM('ios','android') NOT NULL DEFAULT 'ios',
    title          VARCHAR(200) NOT NULL,
    body           VARCHAR(500) NOT NULL,
    data_payload   TEXT DEFAULT NULL,       -- JSON: extra key-value pairs sent in APNs data dict
    badge          TINYINT UNSIGNED DEFAULT 1,
    sound          VARCHAR(100) DEFAULT 'default',
    apns_topic     VARCHAR(200) DEFAULT NULL,  -- overrides default bundle ID when set
    status         ENUM('pending','sent','failed','expired') NOT NULL DEFAULT 'pending',
    attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    scheduled_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at        DATETIME DEFAULT NULL,
    error_message  TEXT DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_pending_scheduled (status, scheduled_at),    -- cron query
    INDEX idx_user_recent (user_id, created_at),           -- admin diagnostics
    CONSTRAINT fk_pq_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
