-- 993_idempotency_keys.sql
-- Job-timer idempotency store.
-- Clients generate a UUID per timer action and send it as the
-- Idempotency-Key header. The server stores key → response for 24 hours
-- so retried requests (timeout, reconnect, double-tap) never create
-- duplicate timer entries.

CREATE TABLE idempotency_keys (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    key_hash      CHAR(64)        NOT NULL,  -- SHA-256(userId:rawKey)
    user_id       INT             NOT NULL,
    endpoint      VARCHAR(80)     NOT NULL,
    action        VARCHAR(20)     NOT NULL,
    response_json MEDIUMTEXT      NOT NULL,
    created_at    DATETIME        NOT NULL,
    expires_at    DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_key_hash   (key_hash),
    KEY           idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
