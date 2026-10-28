-- Migration 1400: llm_calls — one row per call made through LlmGateway
-- Purpose: a single record of every language-model call (which head, which skill, which model,
-- tokens, cost, outcome) so cost can be totalled per head/skill/model/day. Written by
-- app/Services/Llm/LlmGateway.php; logging never blocks an answer.
--
-- site_id is the tenant label the call was made for (1 until TenantContext lands, Stage 2).
-- cost_usd is NULL for a model with no known price (ModelCatalog), never a guess.
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS. MySQL 5.7-compatible.

CREATE TABLE IF NOT EXISTS llm_calls (
    id                 BIGINT AUTO_INCREMENT PRIMARY KEY,
    site_id            INT          NOT NULL DEFAULT 1,
    head               VARCHAR(24)  NOT NULL DEFAULT '',
    skill              VARCHAR(48)  NOT NULL DEFAULT '',
    model              VARCHAR(48)  NOT NULL DEFAULT '',
    input_tokens       INT          NOT NULL DEFAULT 0,
    output_tokens      INT          NOT NULL DEFAULT 0,
    cache_read_tokens  INT          NOT NULL DEFAULT 0,
    cache_write_tokens INT          NOT NULL DEFAULT 0,
    cost_usd           DECIMAL(10,6) NULL,
    http_code          SMALLINT     NOT NULL DEFAULT 0,
    outcome            ENUM('ok','error','refused','empty','capped','killed') NOT NULL DEFAULT 'ok',
    attempts           TINYINT      NOT NULL DEFAULT 1,
    duration_ms        INT          NOT NULL DEFAULT 0,
    error              VARCHAR(255) NULL,
    created_at         DATETIME     NOT NULL,
    INDEX idx_llm_calls_created (created_at),
    INDEX idx_llm_calls_head_skill (head, skill, created_at),
    INDEX idx_llm_calls_site (site_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
