-- Migration 1140: Sam, the sales head (dashboard department heads deck)
-- Date: 2026-10-05
-- Purpose: Sam suggests follow-ups for quotes nobody has answered and next steps for
--   new leads; Tim sends, edits, skips or snoozes — Sam never sends on his own.
--   sam_followups   — every suggestion Tim acted on, with what Sam proposed and what
--                     Tim actually sent: the edits are Sam's lessons in Tim's voice, and
--                     the quotes' later acceptance is his scorecard.
--   sam_questions   — things Sam asks Tim when he's unsure (expired quotes, …).
--   sales_messages  — a read-only log of customer emails: replies read from office@
--                     (every CRM email has Reply-To office@) and the office's sent mail,
--                     so a quote isn't "waiting" when the customer has already answered.
-- Code is guarded: Sam probes for sam_followups and shows nothing until this runs.
-- MySQL 5.7 compatible: TEXT, no JSON type, no generated columns, no window functions.

CREATE TABLE IF NOT EXISTS sam_followups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(20) NOT NULL DEFAULT 'stale_quote'
    COMMENT 'stale_quote | replied | new_lead',
  contact_id INT NULL,
  quote_ids VARCHAR(255) NULL     COMMENT 'Comma-separated quote ids this follow-up covers',
  quote_request_id INT NULL,
  channel VARCHAR(10) NOT NULL DEFAULT 'email' COMMENT 'email | sms',
  template_key VARCHAR(40) NULL   COMMENT 'Which of Sam''s situations: first_nudge, second_nudge, last_call, multi, viewed, reply',
  drafted_by VARCHAR(20) NOT NULL DEFAULT 'template' COMMENT 'template | learned | claude',
  suggested_subject VARCHAR(255) NULL,
  suggested_body TEXT NULL,
  final_subject VARCHAR(255) NULL,
  final_body TEXT NULL,
  learned_body TEXT NULL          COMMENT 'Tim''s edit with the customer details put back as {placeholders} — Sam''s next draft for this situation',
  status VARCHAR(20) NOT NULL DEFAULT 'pending'
    COMMENT 'pending (Claude draft waiting) | sent | edited | skipped | snoozed | failed',
  snooze_until DATE NULL,
  days_since_sent INT NULL        COMMENT 'Days from the quote being sent to this follow-up',
  amount DECIMAL(10,2) NULL       COMMENT 'Dollars waiting across the quotes it covers',
  service_key VARCHAR(60) NULL,
  input_tokens INT NULL,
  output_tokens INT NULL,
  error VARCHAR(255) NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sf_contact (contact_id),
  INDEX idx_sf_status (status, decided_at),
  INDEX idx_sf_template (template_key, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Sam the sales head: follow-up suggestions and what Tim did with them';

CREATE TABLE IF NOT EXISTS sam_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(30) NOT NULL       COMMENT 'expired_quote',
  dedupe_key VARCHAR(80) NOT NULL COMMENT 'kind + subject id, so a question is asked once',
  quote_id INT NULL,
  contact_id INT NULL,
  amount DECIMAL(10,2) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open | answered',
  answer VARCHAR(30) NULL,
  answered_by INT NULL,
  answered_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sq_dedupe (dedupe_key),
  INDEX idx_sq_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Questions Sam asks the owner, and the answers that teach him';

CREATE TABLE IF NOT EXISTS sales_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mailbox VARCHAR(60) NOT NULL    COMMENT 'Where it was read: office@ INBOX / Sent, or sam (sent from Sam''s card)',
  message_key VARCHAR(191) NOT NULL COMMENT 'Message-ID (or a hash) — each email stored once',
  direction VARCHAR(10) NOT NULL  COMMENT 'inbound | outbound',
  channel VARCHAR(10) NOT NULL DEFAULT 'email' COMMENT 'email | sms',
  contact_id INT NULL,
  from_addr VARCHAR(255) NULL,
  to_addr VARCHAR(255) NULL,
  subject VARCHAR(255) NULL,
  snippet TEXT NULL               COMMENT 'The new text only (quoted history stripped), up to ~800 characters',
  sent_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sm_key (message_key),
  INDEX idx_sm_contact (contact_id, sent_at),
  INDEX idx_sm_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Read-only log of customer email both ways (office@), for Sam';
