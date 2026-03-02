-- ============================================================================
-- Migration 606: Google Review Requests
-- ============================================================================
-- Purpose: Add review request tracking to contacts and job_visits, and seed
--          the google_review_url in ops_settings.
--
-- contacts:
--   review_request_sent_at      DATETIME — timestamp of last request (cooldown)
--   review_request_sent_count   INT      — total requests sent (rate limit)
--   review_request_opted_out    TINYINT  — contact asked not to be asked again
--
-- job_visits:
--   review_request_sent_at      DATETIME — audit trail: which visit triggered it
--
-- ops_settings:
--   google_review_url           VARCHAR  — Google Business Profile review short URL
--
-- MySQL 5.7+ compatible
-- ============================================================================

-- ── contacts ──────────────────────────────────────────────────────────────────
ALTER TABLE `contacts`
    ADD COLUMN `review_request_sent_at`    DATETIME     NULL    COMMENT 'When last review request was sent to this contact'      AFTER `total_lifetime_value`,
    ADD COLUMN `review_request_sent_count` INT          NOT NULL DEFAULT 0 COMMENT 'Total review requests ever sent to this contact' AFTER `review_request_sent_at`,
    ADD COLUMN `review_request_opted_out`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = do not send review requests'                AFTER `review_request_sent_count`;

-- ── job_visits ────────────────────────────────────────────────────────────────
ALTER TABLE `job_visits`
    ADD COLUMN `review_request_sent_at` DATETIME NULL COMMENT 'When a review request was triggered for this visit' AFTER `completion_notes`;

-- ── ops_settings: seed Google Review URL ─────────────────────────────────────
INSERT IGNORE INTO `ops_settings` (`setting_key`, `setting_value`, `description`)
VALUES ('google_review_url', 'https://g.page/r/mowology/review', 'Google Business Profile review short URL — used in post-job review request emails');
