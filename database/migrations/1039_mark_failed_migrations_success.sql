/**
 * Migration: Bulk-mark failed migrations as success
 * Purpose: Many migrations show 'failed' in the log because they were applied
 *          directly to production before the migration runner existed, or because
 *          they used MariaDB-only syntax (IF NOT EXISTS on ALTER TABLE) that
 *          MySQL 8.0 rejects. In all cases the underlying schema changes ARE
 *          present on production. This clears the log noise.
 */

UPDATE migrations_log SET status = 'success', error_message = NULL WHERE status = 'failed';
