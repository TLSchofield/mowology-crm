-- Migration 1275: visit_moves — an audit row for every visit moved to another day
-- Date: 2026-10-08
-- Purpose: crew work ahead of schedule (Oakridge Gardens, Mon Oct 5: the Oct 6 and Oct 7 visits
--   were timed on Oct 5). The crew app now offers "Lawn Cut is booked Wed Oct 7. Doing it now?"
--   when a phone arrives at a property with no open visit today; accepting moves THAT visit to
--   today (VisitPullForwardService::pullForward) and starts the timer. Each move is written here:
--   who, when, from → to, why ('pulled_forward_on_site'), and the old stop if it was left empty
--   and removed.
--   request_key is the client's UUID for the attempt — UNIQUE, so an offline replay of the same
--   tap returns the first answer instead of moving twice.
--   The service still works before this runs (the audit insert is skipped, activity_log keeps a
--   line); run it to get the history and replay-safety.
-- MySQL 5.7 compatible (no JSON, no generated columns, no window functions); safe to re-run.

CREATE TABLE IF NOT EXISTS visit_moves (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  visit_id          INT          NOT NULL,
  plan_id           INT          NOT NULL,
  property_id       INT          NULL,
  from_date         DATE         NOT NULL,
  to_date           DATE         NOT NULL,
  from_stop_id      INT          NULL COMMENT 'calendar_stops.id the visit left',
  to_stop_id        INT          NULL COMMENT 'calendar_stops.id the visit joined',
  from_stop_deleted TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = the old stop had nothing left and was removed',
  reason            VARCHAR(40)  NOT NULL DEFAULT 'pulled_forward_on_site',
  moved_by          INT          NULL COMMENT 'users.id',
  request_key       VARCHAR(64)  NULL COMMENT 'client UUID per attempt — replay-safe',
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_visit_moves_request (request_key),
  KEY idx_visit_moves_visit (visit_id),
  KEY idx_visit_moves_property_date (property_id, to_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
