-- Migration 1349: Otto's end-of-day missed-visit review (2026-10-13).
-- Purpose: when the last crew member clocks out (or at 18:00, whichever is first) Otto collects
-- today's visits that were not done plus any older ones still open, suggests per visit
-- (tomorrow / another day / skip — never cancel) with a reason, and Tim or a manager decides.
-- Written by MissedVisitReviewService (app/Modules/Operations/Services/MissedVisitReviewService.php);
-- the pick is MissedVisitRules::pick(). auto_rollover leaves alone any visit decided here, and
-- never skips a contract / prepaid visit any more (it stays open for the next review).
-- site_id: tenant-private (Ittai). MySQL 5.7, utf8mb4_general_ci. Idempotent.

CREATE TABLE IF NOT EXISTS otto_missed_reviews (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id INT NOT NULL DEFAULT 1,
  review_date DATE NOT NULL COMMENT 'the working day being reviewed',
  opened_by VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'last_clock_out | six_pm | manual',
  item_count INT NOT NULL DEFAULT 0,
  notified_at DATETIME NULL COMMENT 'push queued to everyone with schedule.edit',
  notified_count INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_site_day (site_id, review_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS otto_missed_review_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  review_id INT UNSIGNED NOT NULL,
  visit_id INT NOT NULL,
  plan_id INT NOT NULL,
  missed_date DATE NOT NULL COMMENT 'the visit''s scheduled_date when the review was built',
  why_missed VARCHAR(255) NULL COMMENT 'weather / started not finished / skipped note / rolled N times',
  pick VARCHAR(10) NULL COMMENT 'tomorrow | date | skip | NULL = Otto has no pick (you decide)',
  pick_date DATE NULL,
  pick_reason VARCHAR(255) NOT NULL DEFAULT '',
  skip_allowed TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = contract / prepaid / one-off: never suggested',
  skip_block VARCHAR(160) NULL,
  decision VARCHAR(10) NULL COMMENT 'move | skip | cancel',
  decision_date DATE NULL,
  decision_reason VARCHAR(20) NULL COMMENT 'skip/cancel: weather | client | access | other',
  decision_note VARCHAR(255) NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  apply_error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_review_visit (review_id, visit_id),
  KEY idx_visit (visit_id),
  KEY idx_decided (decided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
