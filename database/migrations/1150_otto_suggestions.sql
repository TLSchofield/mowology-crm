-- Migration 1150: Otto's suggestions — the operations head's cards for the owner
-- Date: 2026-10-05
-- Purpose: Otto (dashboard department head, Operations) suggests fixes for today's work:
--   move or keep a visit the weather guard flagged, a clock-out time for a shift nobody
--   closed, a corrected job timer, a duration for a completed visit with no time, a
--   check on a phone that went quiet. One row per thing per day. Nothing is applied
--   until the owner clicks; the decision and what he changed are kept in outcome_json
--   and are what Otto learns from (badges, "right first time", lessons).
-- Code is guarded: Otto's card renders nothing until this has run.
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS otto_suggestions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(24) NOT NULL COMMENT 'weather | clock_out | job_timer | no_time | silent',
  subject_type VARCHAR(16) NOT NULL COMMENT 'visit | clock | timer | user',
  subject_id INT NOT NULL,
  for_date DATE NOT NULL COMMENT 'Visit date, shift date or today (silent phones)',
  user_id INT NULL COMMENT 'Crew member the suggestion is about, if any',
  service_type VARCHAR(80) NULL COMMENT 'Weather suggestions: the plan service type (learning key)',
  rain_pct TINYINT UNSIGNED NULL COMMENT 'Weather suggestions: worst rain chance in the visit window',
  suggestion_json TEXT NULL COMMENT 'What Otto proposed: {lean, clock_out, minutes, ...}',
  status ENUM('open','accepted','edited','dismissed','expired') NOT NULL DEFAULT 'open',
  outcome_json TEXT NULL COMMENT 'What the owner did: {choice, applied, changed, ...}',
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_otto_subject (kind, subject_type, subject_id, for_date),
  INDEX idx_otto_status (status, kind),
  INDEX idx_otto_decided (decided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
