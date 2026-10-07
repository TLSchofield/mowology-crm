-- Migration 1152: Otto's lessons — what he has learned from the owner's decisions
-- Date: 2026-10-05
-- Purpose: one row per thing learned, updated as decisions come in:
--   weather / <service type>   {"keep": [55, 60], "move": [80]}  rain % of each decision
--   silent  / user:<id>        {"wait_minutes": 60}              from a silent_pattern answer
--   duration / plan:<id>       {"minutes": [45, 50]}             visit lengths the owner set
-- Counted for Otto's brain (HeadBrain, ops_settings otto_brain_baseline). Guarded like 1150.
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS otto_lessons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(16) NOT NULL COMMENT 'weather | silent | duration',
  scope_key VARCHAR(120) NOT NULL,
  value_json TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_otto_lesson (scope, scope_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
