-- Migration 1286: Otto on the schedule — the day strip cache
-- Date: 2026-10-08
-- Purpose: the desktop Schedule (day + week), the Territory Map and the dashboard's 7-Day Operations
--   show an Otto strip for the day(s) in view: unscheduled / extra work found that day, visits done on
--   another day, plan lengths that look wrong, an overbooked crew day, completed visits with no time,
--   empty calendar stops, and properties on the day with no pin or border. Building it reads Otto's
--   whole desk, so each day's answer is kept here for 5 minutes (/crm/api/otto-schedule.php).
--   Never the 14-day GPS scan: unscheduled work comes from the evidence cache (migration 1265).
-- Code is guarded: without this table the strip is computed on every call (still read-only).
-- Safe to TRUNCATE at any time. MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS otto_day_strip (
  day DATE NOT NULL PRIMARY KEY,
  payload_json MEDIUMTEXT NOT NULL,
  computed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
