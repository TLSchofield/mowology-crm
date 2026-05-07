-- Migration 518: Add tags column to visit_photos
-- Required by visit-detail.php photo tagging feature (job-photo.php update_tags action).
-- tags stores a JSON array of up to 10 short strings, e.g. '["weeds","edge-work"]'.

ALTER TABLE visit_photos
    ADD COLUMN tags TEXT NULL DEFAULT NULL AFTER view_path;
