-- Migration 1151: Otto's questions — asked when he is unsure
-- Date: 2026-10-05
-- Purpose: when Otto has no lean of his own he asks the owner, and the answer becomes a
--   lesson (migration 1152):
--   weather_tolerance  "Rain 55% on 3 mowing visits tomorrow. Do you usually keep mowing
--                       in rain like that?"  keep | move | depends
--   silent_pattern     "Nigel's phone has gone quiet 3 times and you said it was fine each
--                       time. Wait an hour before I flag him?"  yes | no
-- One question per subject (scope_key). Guarded like 1150.
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS otto_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(24) NOT NULL COMMENT 'weather_tolerance | silent_pattern',
  scope_key VARCHAR(120) NOT NULL COMMENT 'What the answer teaches: service type, user id…',
  question VARCHAR(500) NOT NULL,
  context_json TEXT NULL,
  answer VARCHAR(24) NULL,
  answered_by INT NULL,
  answered_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_otto_q (kind, scope_key),
  INDEX idx_otto_q_open (answer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
