-- Migration 1160: Mia, the marketing & relationships head (dashboard department heads deck)
-- Date: 2026-10-05
-- Purpose: Mia suggests who Tim should reconnect with (past customers, last year's seasonal
--   services, property managers who have gone quiet, referral asks) with a drafted message.
--   Tim sends, edits or skips every one — Mia never sends on her own. What he does is kept
--   here and taught back: his edited wording becomes her template, "never" / "not now"
--   become mutes and snoozes, and work booked within 30 days of a message is her score.
-- Code is guarded: the card and API probe for mia_suggestions and do nothing until this runs.
-- Reserved range 1160–1169. MySQL 5.7 compatible. No foreign keys (collation drift on prod).

CREATE TABLE IF NOT EXISTS mia_suggestions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(20) NOT NULL COMMENT 'reconnect | seasonal | pm_quiet | referral',
  subject_key VARCHAR(40) NOT NULL COMMENT 'mia:contact:<id> or mia:company:<id> — stable for Charlie',
  contact_id INT NULL,
  company_id INT NULL,
  property_id INT NULL,
  reason_json TEXT NULL COMMENT 'why Mia picked them (last visit, service, counts)',
  draft_subject VARCHAR(200) NULL,
  draft_body TEXT NULL,
  draft_sms VARCHAR(200) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'open' COMMENT 'open | sent | skipped | expired',
  edited TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Tim changed the wording before sending',
  sent_subject VARCHAR(200) NULL,
  sent_body TEXT NULL,
  sent_channels VARCHAR(20) NULL COMMENT 'email | email,sms',
  skip_reason VARCHAR(20) NULL COMMENT 'not_fit | talked | not_now | never',
  decided_by INT NULL,
  decided_at DATETIME NULL,
  outcome VARCHAR(12) NULL COMMENT 'booked | quote | reply | none (30 days after sending)',
  outcome_at DATETIME NULL,
  outcome_ref VARCHAR(60) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mia_status (status),
  INDEX idx_mia_subject (subject_key),
  INDEX idx_mia_contact (contact_id),
  INDEX idx_mia_decided (decided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Mia''s suggestions and what Tim did with them (what she learns from)';

CREATE TABLE IF NOT EXISTS mia_mutes (
  subject_key VARCHAR(40) NOT NULL PRIMARY KEY,
  until_date DATE NULL COMMENT 'NULL = never suggest again',
  reason VARCHAR(20) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Who Mia leaves alone, and until when (from Tim''s skips)';

CREATE TABLE IF NOT EXISTS mia_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(30) NOT NULL COMMENT 'review_check | pm_contact | consent_gap',
  subject_key VARCHAR(60) NOT NULL,
  question TEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open | answered',
  answer VARCHAR(20) NULL,
  answered_by INT NULL,
  answered_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mq_kind_subject (kind, subject_key),
  INDEX idx_mq_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Questions Mia asks Tim when she is unsure, and his answers';

INSERT IGNORE INTO ops_settings (setting_key, setting_value, description) VALUES
  ('mia_lapsed_months', '12', 'Mia: months with no completed work before a past customer is worth reconnecting with'),
  ('mia_seasonal_before_days', '21', 'Mia: seasonal window — days before this date last year'),
  ('mia_seasonal_after_days', '30', 'Mia: seasonal window — days after this date last year');

-- Campaigns Mia proposes (e.g. post-drought lawn recovery after the watering restrictions
-- lift). Always a proposal: Tim approves with one tap, and only then is a
-- marketing_campaigns row created (status 'sending') with campaign_sends for the people
-- who passed the consent ledger. The campaign sender cron does the sending.
CREATE TABLE IF NOT EXISTS mia_campaigns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  campaign_key VARCHAR(60) NOT NULL COMMENT 'e.g. post_drought_2026 — one proposal per key',
  name VARCHAR(200) NOT NULL,
  why TEXT NULL,
  subject VARCHAR(200) NOT NULL,
  body_text TEXT NOT NULL COMMENT 'plain text; {{first_name}} is filled per person by the campaign sender',
  photo_json TEXT NULL COMMENT 'Tim''s own published before/after pair, if one fits',
  audience_json TEXT NULL COMMENT 'counts when proposed: clients, neighbours, consented',
  status VARCHAR(12) NOT NULL DEFAULT 'proposed' COMMENT 'proposed | approved | dismissed | expired',
  marketing_campaign_id INT NULL,
  recipients INT NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mia_campaign_key (campaign_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Campaigns Mia proposes; nothing is sent until Tim approves';
