-- Migration 1422: llm_usage_log — one row per paid AI call (Tim, 2026-10-26: audit of the receipt AI layer found no per-call record
-- for the line-item extractor and no per-purpose view). Tokens only, no receipt text. One database per business, so this is per tenant.
-- MySQL 5.7 safe: utf8mb4_general_ci, inline keys. No semicolons inside comments (the runners split on the semicolon character).
CREATE TABLE IF NOT EXISTS llm_usage_log (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    purpose       VARCHAR(40) NOT NULL COMMENT 'receipt_bookkeeper, receipt_llm, ...',
    model         VARCHAR(60) NOT NULL,
    input_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    ok            TINYINT(1) NOT NULL DEFAULT 1,
    expense_id    INT NULL,
    note          VARCHAR(120) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_llm_usage_day (created_at),
    KEY idx_llm_usage_purpose (purpose, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
