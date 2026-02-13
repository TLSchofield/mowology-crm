-- Migration 025: Contact Merge Tracking
-- Adds columns to track when contacts are merged together
-- The "loser" contact gets merged_into_id pointing to the "winner"

ALTER TABLE contacts ADD COLUMN merged_into_id INT NULL;
ALTER TABLE contacts ADD COLUMN merged_at TIMESTAMP NULL;
ALTER TABLE contacts ADD COLUMN merged_by INT NULL;
CREATE INDEX idx_contacts_merged_into ON contacts (merged_into_id);
