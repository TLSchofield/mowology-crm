/**
 * Migration: Fix migrations_log entry for 1037_gsc_properties_extra_columns
 * Purpose: Migration 1037 ran successfully (ALTER TABLE added the 3 columns)
 *          but its log entry is stuck as 'failed' because the migration file
 *          included its own INSERT INTO migrations_log that conflicted with the
 *          runner's automatic logging. This migration corrects that log entry.
 */

UPDATE migrations_log
    SET status = 'success', error_message = NULL
    WHERE migration_filename = '1037_gsc_properties_extra_columns.sql'
    AND status != 'success';
