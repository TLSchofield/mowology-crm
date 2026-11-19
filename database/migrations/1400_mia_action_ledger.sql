-- Migration 1400: Mia action ledger (Phase 1.2 of docs/mia/MIA-IMPLEMENTATION-PLAN.md)
-- Date: 2026-10-25
-- Purpose:
--   mia_actions         One row per thing Mia proposes to change. The owner approves or rejects it here.
--                       A uniform ledger OVER the existing draft tables (mia_suggestions, mia_campaigns,
--                       mia_channel_drafts, seo_recommendations) which stay as they are.
--   mia_action_events   Append-only audit trail of everything that happens to an action. Never deleted.
--   mia_decisions       What the owner decided and why, so a rejected idea is not proposed again
--                       unless the evidence changes (evidence_hash).
--   mia_memory          Durable brand / messaging / priority facts.
--   mia_llm_usage       One row per model call: tokens and cost, for per-tenant caps and costing.
-- Tenancy: every table carries site_id (1 = mowology) as the leading index column. There is no
--   current-tenant lookup yet (docs/plans/ittai-backlog.md), so the value is 1 for now.
-- No foreign keys (collation drift on production) - the services enforce integrity.
-- MySQL 5.7 compatible: TEXT for JSON, no generated columns, no window functions.
-- Comments contain no semicolons (the web runner splits statements on them).

CREATE TABLE IF NOT EXISTS mia_actions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  opportunity_key VARCHAR(120) NULL        COMMENT 'subject the action is about, e.g. page:<sha1> or service:strata',
  type VARCHAR(40) NOT NULL                COMMENT 'seo_meta_update | page_draft | project_story | gbp_post | review_reply | internal_link | fix_technical',
  side_effect VARCHAR(24) NOT NULL DEFAULT 'none' COMMENT 'none | internal | external_reversible | external_irreversible | spend',
  status VARCHAR(12) NOT NULL DEFAULT 'proposed' COMMENT 'proposed | approved | rejected | executing | done | failed | rolled_back | expired',
  title VARCHAR(160) NOT NULL,
  why TEXT NULL                            COMMENT 'plain-English reason shown to the owner',
  expected_benefit VARCHAR(400) NULL       COMMENT 'always an estimate, never a promise',
  risk VARCHAR(400) NULL,
  evidence TEXT NULL                       COMMENT 'JSON text: what the numbers were',
  score DECIMAL(6,2) NULL                  COMMENT 'deterministic priority score',
  score_inputs TEXT NULL                   COMMENT 'JSON text: the inputs that produced the score',
  evidence_hash CHAR(40) NULL              COMMENT 'sha1 of the evidence, used to tell new evidence from the same evidence',
  draft_ref_table VARCHAR(60) NULL         COMMENT 'table holding the draft, from a fixed whitelist',
  draft_ref_id INT NULL,
  draft_hash CHAR(40) NULL                 COMMENT 'sha1 of the draft at the moment it was approved - an edit afterwards voids the approval',
  preview_url VARCHAR(500) NULL,
  autonomy_level TINYINT NOT NULL DEFAULT 1 COMMENT 'level in force when proposed (0 observe, 1 recommend, 2 controlled, 3 delegated)',
  proposed_by VARCHAR(20) NOT NULL DEFAULT 'mia',
  proposed_at DATETIME NOT NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(400) NULL,
  executed_at DATETIME NULL,
  result TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mia_actions_status (site_id, status, proposed_at),
  INDEX idx_mia_actions_opp (site_id, opportunity_key),
  INDEX idx_mia_actions_draft (site_id, draft_ref_table, draft_ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: every proposed change and the owner decision on it';

CREATE TABLE IF NOT EXISTS mia_action_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  action_id INT NOT NULL,
  event VARCHAR(30) NOT NULL               COMMENT 'proposed | approved | rejected | edited | executed | failed | rolled_back | expired | reopened',
  actor VARCHAR(30) NOT NULL               COMMENT 'user:<id> | mia | system',
  detail TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mia_events_action (site_id, action_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: append-only audit trail, never deleted';

CREATE TABLE IF NOT EXISTS mia_decisions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  subject_key VARCHAR(120) NOT NULL        COMMENT 'what was decided about, matches mia_actions.opportunity_key',
  action_type VARCHAR(40) NULL,
  evidence_hash CHAR(40) NULL,
  decision VARCHAR(10) NOT NULL            COMMENT 'approved | rejected | later',
  reason_code VARCHAR(30) NULL             COMMENT 'not_our_service | not_our_voice | bad_timing | other',
  reason_text VARCHAR(400) NULL,
  decided_by INT NULL,
  decided_at DATETIME NOT NULL,
  suppress_until DATE NULL                 COMMENT 'do not propose this again before this date',
  INDEX idx_mia_decisions_subject (site_id, subject_key, decided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: owner decisions, so rejected ideas stay rejected unless the evidence changes';

CREATE TABLE IF NOT EXISTS mia_memory (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  kind VARCHAR(20) NOT NULL                COMMENT 'brand | approved_message | prohibited | priority | style',
  mem_key VARCHAR(80) NOT NULL,
  mem_value TEXT NOT NULL,
  source VARCHAR(10) NOT NULL DEFAULT 'owner' COMMENT 'owner | learned',
  updated_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mia_memory (site_id, kind, mem_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: durable marketing facts the owner gave or confirmed';

CREATE TABLE IF NOT EXISTS mia_llm_usage (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  task VARCHAR(40) NOT NULL,
  model VARCHAR(60) NOT NULL,
  input_tokens INT NOT NULL DEFAULT 0,
  output_tokens INT NOT NULL DEFAULT 0,
  cost_usd DECIMAL(10,5) NOT NULL DEFAULT 0,
  ok TINYINT(1) NOT NULL DEFAULT 1,
  error VARCHAR(200) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mia_usage_month (site_id, created_at),
  INDEX idx_mia_usage_task (site_id, task, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: one row per model call, for caps and costing';
