-- Migration 1444: a recurring plan's visit window, per weekday (2026-11-09)
-- Purpose: Otto compares when the crew really finishes with the end time booked, weekday by weekday, and asks to
-- shorten the window ("Mondays: 8:30–3:00?"). A plan has one default end time, so a plan with several weekdays keeps
-- the agreed end for a weekday here and VisitGenerationService uses it for new visits. Every change is kept in
-- otto_window_changes so it can be undone. Written by VisitWindowService only, and only on the owner's tap.
-- MySQL 5.7, utf8mb4_general_ci. Idempotent.
CREATE TABLE IF NOT EXISTS plan_weekday_windows (
  plan_id      INT      NOT NULL,
  day_of_week  TINYINT  NOT NULL COMMENT '0 = Sunday … 6 = Saturday',
  time_end     TIME     NOT NULL,
  updated_by   INT      NULL,
  updated_at   DATETIME NOT NULL,
  PRIMARY KEY (plan_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS otto_window_changes (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  plan_id         INT      NOT NULL,
  day_of_week     TINYINT  NOT NULL,
  old_end         TIME     NOT NULL COMMENT 'end time the upcoming visits had',
  new_end         TIME     NOT NULL,
  old_window_end  TIME     NULL     COMMENT 'plan_weekday_windows value before this change, if any',
  on_plan         TINYINT  NOT NULL DEFAULT 0 COMMENT '1 = one-weekday plan: job_plans.default_time_end was changed',
  visit_ids       TEXT     NULL     COMMENT 'comma list of the job_visits changed',
  basis_json      TEXT     NULL     COMMENT 'the finish times the change was based on',
  changed_by      INT      NULL,
  changed_at      DATETIME NOT NULL,
  undone_at       DATETIME NULL,
  KEY idx_owc_recent (undone_at, changed_at),
  KEY idx_owc_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
