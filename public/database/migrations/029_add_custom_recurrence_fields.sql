-- Add custom recurrence fields to jobs table for supporting custom intervals
-- Migration: Add recurrence_interval and recurrence_interval_unit
-- NOTE: These columns may already exist if added manually during development

-- Add columns only if they don't exist
-- (MySQL 5.7 doesn't support IF NOT EXISTS for columns, so we'll wrap in error handling)

ALTER TABLE jobs ADD COLUMN recurrence_interval INT DEFAULT 1 COMMENT 'Every X intervals (e.g., every 2 weeks)' AFTER recurrence_pattern;

ALTER TABLE jobs ADD COLUMN recurrence_interval_unit ENUM('days', 'weeks', 'months') DEFAULT 'weeks' COMMENT 'Unit for recurrence interval' AFTER recurrence_interval;

-- Add index for better query performance on recurrence queries
-- Use CREATE INDEX instead of ALTER TABLE ADD INDEX for safety
CREATE INDEX IF NOT EXISTS idx_recurrence ON jobs (job_type, recurrence_pattern);

-- All done! These columns now exist and are ready for custom recurring job patterns.
