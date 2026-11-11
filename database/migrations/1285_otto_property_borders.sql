-- Migration 1285: Otto — a border for every client property
-- Date: 2026-10-08
-- Purpose: Otto audits every active client property (active plan, or a visit in the last 12 months)
--   for a map pin, an arrival border, a pin far from where crews really work, and neighbours whose
--   borders overlap (/crm/api/otto-borders.php?mode=audit, read-only). On the owner's POST
--   (mode=apply, after a dry run) he gives each pinned property with NO arrival border a default one:
--   the convex hull of past crew / truck fixes (buffered 10 m, capped 80 m) when there are enough,
--   else a +/-25 m square round the pin flagged "default - draw me". Defaults are stored in
--   job_geofences exactly as drawn borders are (zone_type 'arrival_border', plan_id NULL), marked
--   with border_source so a drawn border is never overwritten and drawing / editing replaces them.
--
--   job_geofences.border_source   NULL = drawn by a person (every row before this migration)
--                                 'default_hull' | 'default_square' = Otto's default
--                                 'kept' = a default the owner said is fine as it is
--   otto_property_sites           where crews work at each property (median of fixes), measured on
--                                 the owner's apply / refresh, read by Otto's cheap card items.
-- Code is guarded: nothing is written and no border item shows until this has run.
-- MySQL 5.7 compatible. No ADD COLUMN IF NOT EXISTS: if border_source already exists, skip that one
--   statement (the runner reports "Duplicate column" and carries on).

CREATE TABLE IF NOT EXISTS otto_property_sites (
  property_id INT NOT NULL PRIMARY KEY,
  pin_lat DECIMAL(10,8) NULL COMMENT 'The pin when measured (a moved pin makes the row stale)',
  pin_lng DECIMAL(11,8) NULL,
  work_lat DECIMAL(10,8) NULL COMMENT 'Median of the crew / truck fixes during timed visits',
  work_lng DECIMAL(11,8) NULL,
  fixes INT NOT NULL DEFAULT 0,
  visits INT NOT NULL DEFAULT 0,
  offset_m INT NULL COMMENT 'Pin to work median, metres',
  overlaps_json TEXT NULL COMMENT '[property_id, ...] whose arrival border overlaps this one',
  computed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Otto: where crews really work at each client property';

ALTER TABLE job_geofences
  ADD COLUMN border_source VARCHAR(16) NULL COMMENT 'NULL drawn | default_hull | default_square | kept (migration 1285)' AFTER zone_type;
