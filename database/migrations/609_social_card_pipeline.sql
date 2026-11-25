-- ============================================================================
-- Migration 609: Social Card Pipeline
-- ============================================================================
-- Purpose:
--   Adds the columns required for the Phase 3 field-to-social-card pipeline:
--   crew taps heart → auto-draft with GD-generated posting card.
--
-- Changes:
--   job_visits       + social_draft_id, flagged_at
--   social_posts     + card_derivative_id, card_template_type, best_time_suggestion, auto_generated
--   media_derivatives + post_id (link card derivative back to the social post)
--   social_templates + service_type (per-service template filtering for copy variety)
--
-- NOTE: social_posts.cta_url already exists from migration 605 — NOT added here.
-- MySQL 5.7+ compatible (no JSON functions, no window functions).
-- ============================================================================

-- ── job_visits: track draft linkage + when crew flagged ───────────────────────
ALTER TABLE job_visits
    ADD COLUMN social_draft_id INT NULL DEFAULT NULL
        COMMENT 'social_posts.id created when crew flagged this visit'
        AFTER is_flagged,
    ADD COLUMN flagged_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'When is_flagged was set to 1'
        AFTER social_draft_id;

CREATE INDEX idx_jv_social_draft ON job_visits (social_draft_id);

-- ── social_posts: card metadata + auto-generation flag ───────────────────────
ALTER TABLE social_posts
    ADD COLUMN card_derivative_id  INT          NULL DEFAULT NULL
        COMMENT 'media_derivatives.id of the generated social card image'
        AFTER visit_id,
    ADD COLUMN card_template_type  VARCHAR(30)  NULL DEFAULT NULL
        COMMENT 'before_after | hero_after | multi_grid | engagement_showcase'
        AFTER card_derivative_id,
    ADD COLUMN best_time_suggestion VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'e.g. "Tue 10:00 AM" — populated by SocialHashtagEngine'
        AFTER card_template_type,
    ADD COLUMN auto_generated      TINYINT(1)  NOT NULL DEFAULT 0
        COMMENT '1 = created automatically by SocialDraftPipeline (visit flag trigger)'
        AFTER best_time_suggestion;

-- ── media_derivatives: link card derivative back to social post ───────────────
ALTER TABLE media_derivatives
    ADD COLUMN post_id INT NULL DEFAULT NULL
        COMMENT 'social_posts.id — set for derivative_type=social_card rows';

CREATE INDEX idx_md_post ON media_derivatives (post_id);

-- ── social_post_media: add photo_type for before/after card logic ────────────
ALTER TABLE social_post_media
    ADD COLUMN photo_type VARCHAR(20) NULL DEFAULT NULL
        COMMENT 'before | after | during — copied from visit_photos.photo_type';

-- ── social_templates: per-service filtering for copy variety ─────────────────
-- NULL = generic, applies to all service types (fallback)
ALTER TABLE social_templates
    ADD COLUMN service_type VARCHAR(100) NULL DEFAULT NULL
        COMMENT 'Service type slug this template is optimised for (NULL = all services)'
        AFTER category;

CREATE INDEX idx_st_service_type ON social_templates (service_type);

-- ── Track migration ───────────────────────────────────────────────────────────
INSERT INTO migrations_log (migration_filename, executed_at, status)
VALUES ('609_social_card_pipeline.sql', NOW(), 'success')
ON DUPLICATE KEY UPDATE executed_at = NOW(), status = 'success';
