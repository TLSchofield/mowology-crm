-- ============================================================
-- Migration 1035: Template Caption Variants
-- ============================================================
-- Adds caption_variants TEXT to social_templates so campaign
-- posts don't all use identical copy. Stored as a JSON array
-- of up to 5 alternate caption strings. The scheduler cycles
-- through variants when generating campaign posts.
--
-- Example: ["Your hedges are ready...", "Another season...", "Fall is the perfect time..."]
-- If NULL or empty, all posts use caption_template (existing behaviour).
-- ============================================================

ALTER TABLE social_templates
    ADD COLUMN caption_variants TEXT NULL DEFAULT NULL
        COMMENT 'JSON array of alternate captions; scheduler cycles through these'
        AFTER caption_template;
