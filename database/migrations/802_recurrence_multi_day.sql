-- ============================================================================
-- Migration 802: Multi-day recurrence support
-- ============================================================================
-- Changes recurrence_day_of_week from TINYINT to VARCHAR(20) so plans can
-- recur on multiple days per week (e.g. Mon+Wed+Fri stored as "1,3,5").
--
-- MySQL auto-converts existing TINYINT values to their string equivalents:
--   TINYINT 3  →  VARCHAR "3"   (backward-compatible single-day format)
--   TINYINT NULL → VARCHAR NULL (still treated as "use plan start date day")
--
-- No data is lost. Existing visit generation continues to work because
-- the updated calculateRecurrenceDates() handles both "3" and "1,3,5".
-- ============================================================================

ALTER TABLE job_plans
    MODIFY COLUMN recurrence_day_of_week VARCHAR(20) NULL
    COMMENT 'Comma-sep days 0-6: "3"=Wed only, "1,3,5"=Mon/Wed/Fri. NULL=use plan start date day.';

-- End of migration 802
