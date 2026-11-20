-- Migration 1439: time on site per visit, from GPS (Tim, 2026-11-05)
-- ─────────────────────────────────────────────────────────────────
-- How long the crew was really on the property, measured from GPS fixes ON the property
-- (RouteProofService::onSite — the drawn border or the proximity radius): crew phones and the
-- truck tablet first, the truck tracker (vehicle_location_pings) only when no phone fix was there.
-- Gaps of more than 20 minutes split the visit into separate times on site, which are added up.
--
-- Its own table, not job_visits.gps_dwell_minutes: that column feeds the client salt reports and
-- keeps its meaning. GPS is purged after 90 days; this row is what outlasts the purge, so Otto's
-- duration review (VisitDurationService) can compare timer and GPS over a whole season.
--
-- MySQL 5.7. Run outside a transaction.

CREATE TABLE IF NOT EXISTS visit_onsite_time (
  visit_id     INT          NOT NULL PRIMARY KEY,
  minutes      INT          NOT NULL COMMENT 'time on site, gaps over 20 min removed',
  points       INT          NOT NULL COMMENT 'on-site fixes used',
  sessions     TINYINT      NOT NULL DEFAULT 1 COMMENT 'separate times on site (split at 20 min gaps)',
  source       VARCHAR(10)  NOT NULL COMMENT 'phone | truck',
  first_at     DATETIME     NULL,
  last_at      DATETIME     NULL,
  measured_at  DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
