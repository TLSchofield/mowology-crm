-- Migration 1192: Pipeline stages — manual pin
-- Date: 2026-10-06
-- Purpose: Sam (sales head) now keeps contacts.lifecycle_stage / companies.lifecycle_stage
--   current from the owner-approved rules (PipelineStageService: lead / opportunity / client /
--   inactive / lost). A stage a human sets by hand — the contact edit form, the kanban drag,
--   companies/edit.php — sets lifecycle_pinned = 1 and the rules never touch that record again
--   until someone clicks "Unpin (let Sam manage it)".
-- Schema only. There is deliberately NO data backfill here: the first sweep is run on purpose
--   after reviewing /crm/api/pipeline-sweep.php?mode=dry_run.
-- Code is guarded: with this not yet run, nothing is pinned and the pin control is hidden.
-- Idempotent; MySQL 5.7 compatible (information_schema probe + prepared ALTER, no IF NOT EXISTS
--   on columns, no JSON, no generated columns).

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'lifecycle_pinned');
SET @sql = IF(@c = 0,
  'ALTER TABLE `contacts` ADD COLUMN `lifecycle_pinned` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = stage set by hand; the pipeline rules leave it alone''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(1) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'lifecycle_pinned');
SET @sql = IF(@c = 0,
  'ALTER TABLE `companies` ADD COLUMN `lifecycle_pinned` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = stage set by hand; the pipeline rules leave it alone''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
