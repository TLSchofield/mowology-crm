/**
 * Migration: Create Migrations Log Table
 * Date: 2026-02-08
 * Purpose: Track database migrations for audit trail and state management
 *
 * This table allows the CRM to:
 * 1. Track which migrations have been applied
 * 2. Detect pending migrations from /database/migrations/ directory
 * 3. Log who executed each migration and when
 * 4. Store error messages from failed migrations
 * 5. Provide audit trail for database schema changes
 */

-- =========================================================
-- Create migrations_log table
-- =========================================================
CREATE TABLE IF NOT EXISTS `migrations_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique log entry ID',
  `migration_filename` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Filename from /database/migrations/, e.g. "023_consolidate_lifecycle_stages.sql"',
  `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When the migration was executed',
  `executed_by` INT COMMENT 'user.id who executed the migration (admin)',
  `status` ENUM('success', 'failed', 'rollback') DEFAULT 'success' COMMENT 'Execution outcome',
  `error_message` TEXT COMMENT 'Error details if status = failed',
  `migration_type` ENUM('sql', 'php') DEFAULT 'sql' COMMENT 'Type of migration file',
  `checksum` VARCHAR(64) COMMENT 'MD5 hash of migration file to detect modifications',
  `notes` TEXT COMMENT 'Optional notes about the migration',

  -- Indexes for efficient querying
  KEY `idx_migration_filename` (`migration_filename`),
  KEY `idx_executed_at` (`executed_at`),
  KEY `idx_status` (`status`),
  KEY `idx_executed_by` (`executed_by`),

  -- Foreign key to users table (optional - SET NULL if user deleted)
  CONSTRAINT `fk_migrations_log_user`
    FOREIGN KEY (`executed_by`) REFERENCES `users`(`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
COMMENT='Audit log for database migrations executed via the admin panel';

-- =========================================================
-- Create index on status and executed_at for history queries
-- =========================================================
CREATE INDEX IF NOT EXISTS `idx_migrations_log_status_date`
ON `migrations_log` (`status`, `executed_at` DESC);

-- =========================================================
-- Log this migration as applied (bootstrap)
-- =========================================================
-- This entry is created by the migrations manager API, not by the migration file itself

COMMIT;
