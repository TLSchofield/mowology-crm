-- Migration 1122: On-site contact — make sure property_contacts exists
-- Date: 2026-09-29
-- Purpose: the crew-facing "person to call" on a stop is a property_contacts row with
--   contact_role = 'site_supervisor' (see OnsiteContactService). The table was defined
--   in the pre-runner migration done-015 and is in the schema file, but nothing ever
--   wrote to it, so whether it actually exists on production has never been proven.
--   This re-creates it idempotently; on a database that already has it, it is a no-op.
-- Code is guarded: OnsiteContactService probes for the table and reads degrade to
--   "no on-site contact" when it is missing, so deploying before this runs is safe.
-- MySQL 5.7 compatible: no JSON, no generated columns.

CREATE TABLE IF NOT EXISTS property_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  contact_id INT NOT NULL,
  contact_role ENUM('owner','tenant','site_supervisor','manager','billing','emergency','other') NOT NULL,
  is_primary TINYINT(1) DEFAULT 0 COMMENT 'Primary contact for this role',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_property (property_id),
  INDEX idx_contact (contact_id),
  INDEX idx_role (contact_role),
  UNIQUE KEY unique_property_contact_role (property_id, contact_id, contact_role),
  CONSTRAINT fk_pc_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_contact  FOREIGN KEY (contact_id)  REFERENCES contacts(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Contacts at a property by role; site_supervisor = who crew call on site';
