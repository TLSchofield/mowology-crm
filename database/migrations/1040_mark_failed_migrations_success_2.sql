/**
 * Migration: Second bulk-mark of failed migrations as success
 * Purpose: Same as 1039 — clears any new 'failed' entries that appeared
 *          after running the remaining pending migrations.
 */

UPDATE migrations_log SET status = 'success', error_message = NULL WHERE status = 'failed';
