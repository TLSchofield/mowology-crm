-- Migration 1401: agent_action_policy + agent_activity (Ittai audit 2026-10-27, stage 3)
-- Purpose: autonomy per ACTION (not per head) and one ledger of what every head did and who approved it.
--
-- agent_action_policy: optional override rows. No row = the code default in AgentPolicy::DEFAULTS
--   (level 2: the head prepares, a person approves — exactly how every head already behaves).
--   level 0 observe · 1 recommend · 2 prepare (approval needed) · 3 limited autonomy · 4 delegated autonomy.
-- agent_activity: one row per head action (proposed / approved / executed / blocked / failed / rejected),
--   with the approver, whether it can be undone, its cost and a correlation id that ties a chain together.
--
-- The policy is recorded in SHADOW MODE until ops_settings agents_policy_enforce = 1; the kill switches
-- (agents_kill_all, agents_kill_<head>) always take effect. Safe to re-run. MySQL 5.7-compatible.

CREATE TABLE IF NOT EXISTS agent_action_policy (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    head        VARCHAR(24)  NOT NULL,
    action_key  VARCHAR(64)  NOT NULL,
    level       TINYINT      NOT NULL DEFAULT 2,
    enabled     TINYINT(1)   NOT NULL DEFAULT 1,
    limits_json TEXT         NULL,
    updated_by  INT          NULL,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_agent_policy (head, action_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS agent_activity (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    site_id         INT          NOT NULL DEFAULT 1,
    head            VARCHAR(24)  NOT NULL,
    skill           VARCHAR(48)  NOT NULL DEFAULT '',
    action_key      VARCHAR(64)  NOT NULL,
    entity_type     VARCHAR(32)  NOT NULL DEFAULT '',
    entity_id       VARCHAR(64)  NOT NULL DEFAULT '',
    decision        ENUM('proposed','approved','executed','blocked','failed','rejected') NOT NULL,
    level_required  TINYINT      NULL,
    approver_user_id INT         NULL,
    result          VARCHAR(255) NULL,
    reversible      TINYINT(1)   NULL,
    cost_usd        DECIMAL(10,6) NULL,
    correlation_id  VARCHAR(64)  NULL,
    shadow          TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL,
    INDEX idx_agent_activity_head (head, created_at),
    INDEX idx_agent_activity_action (action_key, created_at),
    INDEX idx_agent_activity_corr (correlation_id),
    INDEX idx_agent_activity_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
