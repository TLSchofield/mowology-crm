-- 1450: a visit's own length, set when the owner drags its edge on the calendar.
-- NULL = use the plan's estimated_duration_minutes (every visit today). Safe to run twice.
ALTER TABLE job_visits ADD COLUMN duration_override_minutes INT NULL DEFAULT NULL;
