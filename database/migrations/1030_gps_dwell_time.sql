-- Migration 1030: GPS Dwell Time for Duration Prediction
-- ──────────────────────────────────────────────────────
-- Adds columns to store GPS-derived on-property dwell time per visit,
-- and a rolling GPS average per plan. DwellTimeService computes these
-- from visit_gps_points at visit completion and via backfill.
--
-- GPS dwell time is separate from timer-based actual_duration_minutes:
--   - actual_duration_minutes = sum of job_time_entries (crew manually starts/stops)
--   - gps_dwell_minutes       = elapsed time first→last GPS point on property
--
-- When gps_visit_count >= 2, DwellTimeService promotes gps_avg_dwell_minutes
-- into estimated_duration_minutes so the scheduler uses GPS-derived predictions.
--
-- MySQL 5.7+ compatible. No window functions. Plain ALTER TABLE (run outside transactions).

ALTER TABLE job_visits
  ADD COLUMN gps_dwell_minutes  INT     NULL DEFAULT NULL
    COMMENT 'GPS-derived dwell time: elapsed seconds first→last filtered point / 60',
  ADD COLUMN gps_dwell_quality  TINYINT NULL DEFAULT NULL
    COMMENT '0=insufficient(<3pts) 1=sparse(3-5) 2=moderate(6-9) 3=good(10+)',
  ADD INDEX idx_jv_gps_dwell (gps_dwell_minutes);

ALTER TABLE job_plans
  ADD COLUMN gps_avg_dwell_minutes INT  NULL DEFAULT NULL
    COMMENT 'Rolling avg of gps_dwell_minutes across completed visits with quality>=2',
  ADD COLUMN gps_visit_count       INT  NOT NULL DEFAULT 0
    COMMENT 'Count of completed visits with quality>=2 GPS dwell data';
