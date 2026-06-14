-- Migration 1006: is_favorite flag on visit_photos
-- Allows crew to star a photo so it surfaces highlighted in the Media Library.
-- Safe to run multiple times (SHOW COLUMNS check in runner).

ALTER TABLE visit_photos ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0;
CREATE INDEX idx_visit_photos_is_favorite ON visit_photos (is_favorite);
