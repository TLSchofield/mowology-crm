-- Migration 1118: before/after pairs go through a manager before the website sees them
-- Date: 2026-09-22
-- Purpose: crew endorse a visit (the heart) → a PENDING pair is queued from the visit's
-- before/after photos (media_links) → a manager (portfolio.edit) approves it, records
-- client consent where the property needs it, and only then is it published to the
-- public portfolio. Until now ba_pairs.published defaulted to 1 (creation = publication)
-- and the endorsement inbox read the retired visit_photos table, so nothing endorsed
-- since the crew upload moved to media_links ever reached the page.
-- Existing pairs are grandfathered as approved so the two live ones keep showing.
-- MySQL 5.7 compatible.

ALTER TABLE ba_pairs
    ADD COLUMN visit_id INT NULL COMMENT 'job_visits.id the pair was queued from' AFTER after_id,
    ADD COLUMN status VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending | approved | rejected' AFTER published,
    ADD COLUMN area VARCHAR(80) NULL COMMENT 'neighbourhood shown publicly, never the address' AFTER category,
    ADD COLUMN alt_before VARCHAR(160) NULL AFTER area,
    ADD COLUMN alt_after VARCHAR(160) NULL AFTER alt_before,
    ADD COLUMN web_before_path VARCHAR(255) NULL COMMENT 'web-sized JPEG served publicly' AFTER alt_after,
    ADD COLUMN web_after_path VARCHAR(255) NULL AFTER web_before_path,
    ADD COLUMN consent_state VARCHAR(12) NOT NULL DEFAULT 'not_needed' COMMENT 'not_needed | needed | recorded' AFTER web_after_path,
    ADD COLUMN consent_note VARCHAR(255) NULL COMMENT 'how the client agreed (email, phone, portal)' AFTER consent_state,
    ADD COLUMN queued_by INT NULL COMMENT 'users.id of the crew member who endorsed' AFTER consent_note,
    ADD COLUMN queued_at DATETIME NULL AFTER queued_by,
    ADD COLUMN approved_by INT NULL AFTER queued_at,
    ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
    ADD COLUMN rejected_reason VARCHAR(255) NULL AFTER approved_at;

ALTER TABLE ba_pairs MODIFY COLUMN published TINYINT(1) NOT NULL DEFAULT 0;

CREATE INDEX idx_ba_pairs_status ON ba_pairs (status, published);
CREATE INDEX idx_ba_pairs_visit ON ba_pairs (visit_id);

-- Pairs that already exist were made by hand on the manager page: treat them as approved.
UPDATE ba_pairs SET status = 'approved', approved_at = COALESCE(updated_at, created_at) WHERE published = 1;
UPDATE ba_pairs SET status = 'rejected' WHERE published = 0;
