-- ============================================================================
-- Migration 201: Drop Legacy Jobs Tables + Columns
-- ============================================================================
-- Now that all PHP code references job_plans/job_visits/calendar_stops,
-- remove the old jobs table and its dependent tables/columns.
--
-- Prerequisites:
--   - Migration 200 (job_plans, job_visits, calendar_stops) already run
--   - All PHP code updated to use new tables
--
-- MySQL 5.7 compatible
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 1. Drop dependent tables (these have FK to jobs)
-- ============================================================================
DROP TABLE IF EXISTS job_proof_of_work;
DROP TABLE IF EXISTS job_photos;
DROP TABLE IF EXISTS job_notes;

-- ============================================================================
-- 2. Drop job_id FK constraints + columns from tables that reference jobs
-- ============================================================================

-- job_time_entries: has FK to jobs(id)
-- MySQL 5.7: need to find and drop the FK constraint by name
-- Auto-generated names follow pattern: tablename_ibfk_N
-- job_id was the first FK defined, so likely job_time_entries_ibfk_1
ALTER TABLE job_time_entries DROP FOREIGN KEY job_time_entries_ibfk_1;
ALTER TABLE job_time_entries DROP INDEX idx_job;
ALTER TABLE job_time_entries DROP COLUMN job_id;

-- crew_location_history: has FK to jobs(id)
-- crew_id FK was ibfk_1, job_id FK was ibfk_2
ALTER TABLE crew_location_history DROP FOREIGN KEY crew_location_history_ibfk_2;
ALTER TABLE crew_location_history DROP INDEX idx_job;
ALTER TABLE crew_location_history DROP COLUMN job_id;

-- invoices: job_id is a plain column (no FK constraint)
ALTER TABLE invoices DROP COLUMN job_id;

-- invoice_line_items: job_id is a plain column (no FK constraint)
ALTER TABLE invoice_line_items DROP COLUMN job_id;

-- activity_log: job_id is a plain column (no FK constraint)
ALTER TABLE activity_log DROP INDEX idx_job;
ALTER TABLE activity_log DROP COLUMN job_id;

-- roi_attribution: had job_id column (no FK in original schema)
ALTER TABLE roi_attribution DROP COLUMN job_id;

-- ============================================================================
-- 3. Drop the jobs table itself
-- ============================================================================
DROP TABLE IF EXISTS jobs;

SET FOREIGN_KEY_CHECKS = 1;
