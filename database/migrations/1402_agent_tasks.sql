-- Migration 1402: agent_tasks + agent_task_events (Ittai audit 2026-10-27, stage 4)
-- Purpose: a durable task per piece of work a head owns, so work is no longer only re-discovered by each
-- desk on each pass. A task survives restarts, is never created twice (dedupe_key is UNIQUE), has bounded
-- retries, a time limit, and an append-only history. Detectors (cron agent_detect) create tasks from the
-- desks' own deterministic lists; heads claim and complete them; stale ones expire; failures escalate.
--
-- States: open, working, waiting_approval, done, failed, cancelled, expired.
-- Delegation depth is capped in TaskService (3) and a parent chain may not repeat an (owner, kind).
-- Safe to re-run. MySQL 5.7-compatible.

CREATE TABLE IF NOT EXISTS agent_tasks (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    site_id        INT          NOT NULL DEFAULT 1,
    kind           VARCHAR(48)  NOT NULL,
    owner_head     VARCHAR(24)  NOT NULL,
    origin_head    VARCHAR(24)  NOT NULL DEFAULT 'system',
    entity_type    VARCHAR(32)  NOT NULL DEFAULT '',
    entity_id      VARCHAR(64)  NOT NULL DEFAULT '',
    title          VARCHAR(200) NOT NULL DEFAULT '',
    priority       ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    state          ENUM('open','working','waiting_approval','done','failed','cancelled','expired') NOT NULL DEFAULT 'open',
    attempts       TINYINT      NOT NULL DEFAULT 0,
    max_attempts   TINYINT      NOT NULL DEFAULT 3,
    escalated      TINYINT(1)   NOT NULL DEFAULT 0,
    due_at         DATETIME     NULL,
    expires_at     DATETIME     NULL,
    claimed_at     DATETIME     NULL,
    completed_at   DATETIME     NULL,
    correlation_id VARCHAR(64)  NULL,
    parent_task_id BIGINT       NULL,
    depth          TINYINT      NOT NULL DEFAULT 0,
    dedupe_key     VARCHAR(120) NOT NULL,
    payload_json   TEXT         NULL,
    result_json    TEXT         NULL,
    error          VARCHAR(255) NULL,
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     NOT NULL,
    UNIQUE KEY uq_agent_task_dedupe (site_id, dedupe_key),
    INDEX idx_agent_task_owner (owner_head, state),
    INDEX idx_agent_task_state_due (state, due_at),
    INDEX idx_agent_task_corr (correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS agent_task_events (
    id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    task_id    BIGINT       NOT NULL,
    event      VARCHAR(24)  NOT NULL,
    from_state VARCHAR(20)  NULL,
    to_state   VARCHAR(20)  NULL,
    head       VARCHAR(24)  NOT NULL DEFAULT '',
    note       VARCHAR(255) NULL,
    created_at DATETIME     NOT NULL,
    INDEX idx_agent_task_events_task (task_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
