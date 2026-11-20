-- ============================================================================
-- Migration 619: First-Comment Hashtags for Instagram
-- ============================================================================
-- Adds hashtags_in_comment flag to social_posts.
-- When 1: publisher posts hashtags as the first comment on the Instagram
-- media rather than appending them to the caption.
-- Caption stays clean; Instagram algorithm treats them identically.
-- Default 0 = existing behaviour unchanged.
-- ============================================================================

ALTER TABLE social_posts
    ADD COLUMN hashtags_in_comment TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = post hashtags as first comment (Instagram); 0 = append to caption'
        AFTER hashtags;
