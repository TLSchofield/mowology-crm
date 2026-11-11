-- ============================================================
-- Migration 028 — Social post animation fields
-- Run once. Safe to re-run only if columns don't exist yet.
-- ============================================================

-- animation_type: which transition was used (wipe, split, zoom, etc.)
-- video_media_id: FK to media_assets for the generated video blob
ALTER TABLE social_posts
    ADD COLUMN animation_type VARCHAR(20) NULL,
    ADD COLUMN video_media_id INT NULL;
