-- Migration 1265: Otto — work with nothing scheduled (evidence cache)
-- Date: 2026-10-07
-- Purpose: Otto flags days the truck (Trackimo stops, the primary signal) and/or the crew's
--   phones and clock punches stayed at a client property with no visit scheduled there that day
--   (the 2448 Larch St hedge job, Mon 2026-10-05). Building a day's evidence reads thousands of
--   GPS rows, so each ENDED day's evidence (truck stops + crew dwells, property-attributed) is
--   stored here once. Whether the property was scheduled is never cached — adding the visit
--   clears the item on the next look. Suggestions themselves go in otto_suggestions
--   (kind 'unscheduled', subject_type 'property'); "not work" answers in otto_lessons
--   (scope 'unscheduled', key 'property:<id>').
-- Code is guarded: nothing is flagged until this has run. Safe to TRUNCATE at any time (it refills).
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS otto_unscheduled_days (
  day DATE NOT NULL PRIMARY KEY,
  evidence_json MEDIUMTEXT NOT NULL COMMENT '{v, truck: [...], crew: {uid: [...]}, meta: {...}}',
  computed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
