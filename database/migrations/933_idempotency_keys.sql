-- Idempotency keys for timer and clock mutations
-- Prevents duplicate DB writes when the offline queue replays a request
-- that already committed on the first attempt (race window on slow DB).
--
-- Keyed by (idem_key, endpoint) — INSERT IGNORE returns rowCount=0 on replay,
-- signalling the endpoint to respond 200 without re-processing.
-- TTL: rows older than 24 hours are pruned on each guard call.

CREATE TABLE IF NOT EXISTS idempotency_keys (
    idem_key   VARCHAR(64)  NOT NULL,
    endpoint   VARCHAR(80)  NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (idem_key, endpoint),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
