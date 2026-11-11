-- Migration 1200: Mia's channels — Google Business Profile reviews and posts, social drafts from
--   crew photos, and the weekly website (Search Console) report.
-- Date: 2026-10-06
-- Purpose:
--   gbp_reviews        Google reviews (pasted in by Tim, or imported once the GBP API is approved)
--                      with Mia's drafted reply. Tim copies (or, live, clicks Post) — Mia never posts.
--   mia_channel_drafts Mia's weekly Google post draft ('gbp_post') and her social drafts from crew
--                      photos ('social', pointing at the social_posts draft she made).
--   mia_web_reports    One Search Console report per week (28 days vs the 28 before): top queries,
--                      pages losing clicks, low-CTR queries, service pages with no impressions,
--                      stale seasonal pages. Suggestions only.
--   ops_settings       gbp_api_approved = 0 (Tim sets 1 when Google approves API access).
-- Code is guarded: the Channels section shows setup steps until this runs.
-- MySQL 5.7 compatible: TEXT (no JSON type), no generated columns, no window functions,
--   no ADD COLUMN IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS gbp_reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(10) NOT NULL DEFAULT 'pasted' COMMENT 'pasted | api',
  external_id VARCHAR(191) NULL           COMMENT 'Google review resource name (accounts/…/locations/…/reviews/…) when imported',
  reviewer_name VARCHAR(120) NULL,
  rating TINYINT NOT NULL DEFAULT 5       COMMENT '1-5 stars',
  comment TEXT NULL,
  review_date DATE NULL,
  reply_draft TEXT NULL                   COMMENT 'Mia''s draft (Tim''s voice: no exclamation marks, thank by name, mention the work, short)',
  reply_text TEXT NULL                    COMMENT 'What Tim actually replied',
  replied_via VARCHAR(10) NULL            COMMENT 'copied (Tim pasted it into Google) | api (posted from the CRM)',
  status VARCHAR(12) NOT NULL DEFAULT 'waiting' COMMENT 'waiting | replied | dismissed',
  replied_by INT NULL,
  replied_at DATETIME NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gbp_review_ext (external_id),
  INDEX idx_gbp_review_status (status, review_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: Google reviews and the replies she drafts for Tim';

CREATE TABLE IF NOT EXISTS mia_channel_drafts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(12) NOT NULL               COMMENT 'gbp_post | social',
  theme_key VARCHAR(40) NULL              COMMENT 'MiaSeasonThemes key (or a calendar theme key later)',
  week_start DATE NOT NULL                COMMENT 'Monday of the week it was drafted for',
  title VARCHAR(200) NULL,
  body TEXT NULL                          COMMENT 'Post text (Google: no phone number or link in the text)',
  cta_type VARCHAR(20) NULL               COMMENT 'Google call-to-action: BOOK | LEARN_MORE | CALL',
  cta_url VARCHAR(500) NULL,
  social_post_id INT NULL                 COMMENT 'social_posts.id of the draft Mia made (kind = social)',
  visit_id INT NULL                       COMMENT 'job_visits.id whose crew photos were used',
  media_id INT NULL                       COMMENT 'media_assets.id of the photo MediaPickerService chose (Google post)',
  photo_url VARCHAR(500) NULL             COMMENT 'Channel-sized variant of that photo',
  status VARCHAR(12) NOT NULL DEFAULT 'draft' COMMENT 'draft | posted (via API, Tim''s click) | copied | dismissed | expired',
  external_id VARCHAR(255) NULL           COMMENT 'Google localPost name when posted from the CRM',
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mcd_kind_week (kind, week_start),
  INDEX idx_mcd_status (kind, status),
  INDEX idx_mcd_post (social_post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: weekly Google post drafts and social drafts from crew photos';

CREATE TABLE IF NOT EXISTS mia_web_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  week_start DATE NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'ok' COMMENT 'ok | not_connected | error',
  source VARCHAR(20) NULL                 COMMENT 'oauth (gsc_properties) | service_account',
  period_json TEXT NULL                   COMMENT '{"cur":[from,to],"prev":[from,to]}',
  report_json MEDIUMTEXT NULL             COMMENT 'WebsiteWatchRules::analyze() output + cms_url per suggestion',
  error VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mwr_week (week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia: weekly Search Console report (suggestions only)';

INSERT INTO ops_settings (setting_key, setting_value, description)
VALUES ('gbp_api_approved', '0', 'Mia: 1 once Google has approved Business Profile API access (until then GBP is drafts only)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
