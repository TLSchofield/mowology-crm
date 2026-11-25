-- Migration 1266: Otto — real visit lengths (every plan-length change he was told to make)
-- Date: 2026-10-07
-- Purpose: Otto compares each recurring lawn plan's estimated_duration_minutes with the median of
--   its last ~8 timed visits and proposes a new length. Nothing changes until the owner clicks
--   Apply (one plan) or Apply all confident (≥ 5 samples, stable spread — one batch_key). Every
--   change is kept here with the evidence it rested on, so it can be undone (only while nobody
--   has changed the plan since). Suggestions go in otto_suggestions (kind 'duration',
--   subject_type 'plan').
-- Code is guarded: the duration items and buttons stay hidden until this has run.
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS otto_duration_changes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  old_minutes INT NULL,
  new_minutes INT NOT NULL,
  median_crew INT NULL COMMENT 'Median on-site minutes (union of timers) of the samples used',
  median_person INT NULL COMMENT 'Median person-minutes (timers added up) of the samples used',
  samples TINYINT UNSIGNED NULL,
  confident TINYINT(1) NOT NULL DEFAULT 0,
  batch_key VARCHAR(40) NULL COMMENT 'Shared by every change of one Apply-all click',
  applied_by INT NULL,
  applied_at DATETIME NOT NULL,
  undone_by INT NULL,
  undone_at DATETIME NULL,
  INDEX idx_odc_plan (plan_id),
  INDEX idx_odc_batch (batch_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
