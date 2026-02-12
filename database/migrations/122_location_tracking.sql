-- Migration 122: Location Tracking
-- Adds per-user tracking toggle and index for fast latest-position queries

-- Add tracking toggle to users table
ALTER TABLE users ADD COLUMN location_tracking_enabled TINYINT(1) DEFAULT 0 AFTER notes;

-- Add composite index for fast "latest position per user" queries
CREATE INDEX idx_crew_loc_latest ON crew_location_history (crew_id, timestamp);
