-- ============================================================================
-- Migration 607: Visit Flag + Has Reviewed
-- ============================================================================
-- Purpose: Add visit-level crew endorsement flag and a permanent review stop
--          flag on contacts.
--
-- job_visits:
--   is_flagged   TINYINT — crew hearted this visit (quality gate for review
--                          requests; feeds BA marketing import)
--
-- contacts:
--   has_reviewed TINYINT — client has left a Google review; permanently stops
--                          all future review request emails/SMS
--
-- MySQL 5.7+ compatible
-- ============================================================================

-- ── job_visits ────────────────────────────────────────────────────────────────
ALTER TABLE `job_visits`
    ADD COLUMN `is_flagged` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Crew endorsed this visit — quality gate for review requests + BA marketing'
    AFTER `review_request_sent_at`;

CREATE INDEX `idx_job_visits_flagged` ON `job_visits` (`is_flagged`);

-- ── contacts ──────────────────────────────────────────────────────────────────
ALTER TABLE `contacts`
    ADD COLUMN `has_reviewed` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Client has left a Google review — permanently stops all future review requests'
    AFTER `review_request_opted_out`;
