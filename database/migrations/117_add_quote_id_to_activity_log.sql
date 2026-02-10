-- =====================================================
-- Migration 117: Add quote_id to activity_log
-- =====================================================
-- Purpose: Add quote tracking column to activity_log table
-- This column links activity events to quotes for audit trail
-- =====================================================

-- Add the quote_id column if it doesn't exist
ALTER TABLE `activity_log`
ADD COLUMN `quote_id` INT DEFAULT NULL AFTER `quote_request_id`,
ADD INDEX `idx_quote` (`quote_id`);

-- Add foreign key constraint
ALTER TABLE `activity_log`
ADD CONSTRAINT `fk_activity_quote`
FOREIGN KEY (`quote_id`) REFERENCES `quotes`(`id`) ON DELETE SET NULL;

-- Verify the column was added
SELECT 'quote_id column added to activity_log' as status;
