/**
 * Migration: Consolidate Lifecycle Stages
 * Date: 2026-02-08
 * Purpose: Unify lifecycle stage management for contacts and companies using a single lookup table
 *
 * Changes:
 * 1. Adds entity_type column to lifecycle_stages for scope control
 * 2. Migrates contacts.lifecycle_stage from ENUM to VARCHAR(50) with FK reference
 * 3. Adds foreign key constraints to enforce referential integrity
 * 4. Syncs existing data to use canonical stage keys from lifecycle_stages table
 */

-- =========================================================
-- 1. Ensure lifecycle_stages table has entity_type
-- =========================================================
ALTER TABLE `lifecycle_stages`
ADD COLUMN `entity_type` ENUM('contact', 'company', 'both') DEFAULT 'both' AFTER `description`;

-- Update existing stages to indicate they apply to both entities
UPDATE `lifecycle_stages` SET entity_type = 'both' WHERE entity_type IS NULL;

-- =========================================================
-- 2. Migrate contacts.lifecycle_stage from ENUM to VARCHAR(50)
-- =========================================================

-- First, alter column type and add NOT NULL constraint with default
ALTER TABLE `contacts`
MODIFY COLUMN `lifecycle_stage` VARCHAR(50) DEFAULT 'lead' NOT NULL COMMENT 'References lifecycle_stages.stage_key';

-- Add foreign key constraint for contacts
ALTER TABLE `contacts`
ADD CONSTRAINT `fk_contacts_lifecycle_stage`
FOREIGN KEY (`lifecycle_stage`) REFERENCES `lifecycle_stages`(`stage_key`)
ON DELETE RESTRICT ON UPDATE CASCADE;

-- =========================================================
-- 3. Verify companies.lifecycle_stage has proper FK
-- =========================================================

-- Check if FK already exists and remove if needed (for idempotency)
-- This is safe because the constraint name is predictable
ALTER TABLE `companies`
DROP FOREIGN KEY IF EXISTS `fk_companies_lifecycle_stage`;

-- Ensure type is VARCHAR(50)
ALTER TABLE `companies`
MODIFY COLUMN `lifecycle_stage` VARCHAR(50) DEFAULT 'prospect' NOT NULL COMMENT 'References lifecycle_stages.stage_key';

-- Add foreign key constraint for companies
ALTER TABLE `companies`
ADD CONSTRAINT `fk_companies_lifecycle_stage`
FOREIGN KEY (`lifecycle_stage`) REFERENCES `lifecycle_stages`(`stage_key`)
ON DELETE RESTRICT ON UPDATE CASCADE;

-- =========================================================
-- 4. Insert missing stage definitions for contacts
-- =========================================================

-- Add contact-specific stages if not already present
INSERT INTO `lifecycle_stages`
  (`stage_key`, `stage_label`, `stage_order`, `stage_color`, `entity_type`, `description`, `is_active`)
VALUES
  ('lead', 'Lead', 1, '#3B82F6', 'contact', 'New contact or unqualified lead', 1),
  ('opportunity', 'Opportunity', 2, '#F59E0B', 'contact', 'Contact qualified as sales opportunity', 1),
  ('won', 'Won', 3, '#2D8659', 'contact', 'Opportunity converted to client', 1),
  ('lost', 'Lost', 4, '#6B7280', 'contact', 'Opportunity lost or contact unresponsive', 1)
ON DUPLICATE KEY UPDATE entity_type = 'both', is_active = 1;

-- =========================================================
-- 5. Ensure company stages are present
-- =========================================================

-- Company stages (if not present from migration 021)
INSERT INTO `lifecycle_stages`
  (`stage_key`, `stage_label`, `stage_order`, `stage_color`, `entity_type`, `description`, `is_active`)
VALUES
  ('prospect', 'Prospect', 1, '#3B82F6', 'company', 'New inquiry or quote request', 1),
  ('qualified', 'Qualified', 2, '#F59E0B', 'company', 'Prospect has been contacted and qualified', 1),
  ('client', 'Client', 3, '#2D8659', 'company', 'Active paying customer', 1),
  ('inactive', 'Inactive', 4, '#6B7280', 'company', 'No longer active or pursuing', 1)
ON DUPLICATE KEY UPDATE entity_type = 'both', is_active = 1;

-- =========================================================
-- 6. Data validation - ensure no orphaned references
-- =========================================================

-- Remove any lifecycle_stage values that don't exist in lifecycle_stages
DELETE FROM `contacts`
WHERE `lifecycle_stage` NOT IN (SELECT `stage_key` FROM `lifecycle_stages`);

DELETE FROM `companies`
WHERE `lifecycle_stage` NOT IN (SELECT `stage_key` FROM `lifecycle_stages`);

-- =========================================================
-- 7. Create index for faster lifecycle_stage lookups
-- =========================================================

CREATE INDEX IF NOT EXISTS `idx_contacts_lifecycle_stage_fk`
ON `contacts` (`lifecycle_stage`);

CREATE INDEX IF NOT EXISTS `idx_companies_lifecycle_stage_fk`
ON `companies` (`lifecycle_stage`);

-- =========================================================
-- 8. Log completion
-- =========================================================

-- Migration complete - all lifecycle_stage references now use centralized lookup table
-- Both contacts and companies share the same stage definitions for consistency
COMMIT;
