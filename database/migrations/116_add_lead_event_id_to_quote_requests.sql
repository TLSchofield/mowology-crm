-- =====================================================
-- Migration 116: Add lead_event_id to quote_requests
-- =====================================================
-- Purpose: Add ROI tracking column to quote_requests table
-- This column links quote requests to lead events for attribution
-- =====================================================

-- Add the lead_event_id column if it doesn't exist
ALTER TABLE `quote_requests`
ADD COLUMN `lead_event_id` INT DEFAULT NULL AFTER `id`,
ADD FOREIGN KEY (`lead_event_id`) REFERENCES `lead_events`(`id`) ON DELETE SET NULL,
ADD INDEX `idx_lead_event` (`lead_event_id`);

-- Verify the column was added
SELECT 'lead_event_id column added to quote_requests' as status;
