-- Migration 1403: agent_eval_runs (Ittai audit 2026-10-27, stage 5)
-- Purpose: one row per skill per evaluation run — how many fixed cases the skill got right — so autonomy can be raised
-- on evidence and a change that makes a head worse shows up as a falling score. Cases are fixtures in code
-- (app/Modules/Agents/Services/AgentEvals.php); this table only keeps the results. Safe to re-run. MySQL 5.7-compatible.

CREATE TABLE IF NOT EXISTS agent_eval_runs (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    skill         VARCHAR(48)  NOT NULL,
    version       INT          NOT NULL DEFAULT 1,
    cases         INT          NOT NULL,
    passed        INT          NOT NULL,
    score         DECIMAL(5,2) NOT NULL,
    failures_json TEXT         NULL,
    run_at        DATETIME     NOT NULL,
    INDEX idx_agent_eval_skill (skill, run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
