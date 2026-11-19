-- Migration 1191: Yui, the comms / client relations head (dashboard department heads deck)
-- Date: 2026-10-06
-- Purpose: Yui drafts every one-to-one conversation with existing clients (replies that aren't
--   about a quote, approvals with no accepted quote, renewals and check-ins, arrears notes);
--   Tim edits and sends — Yui never sends on her own.
--   yui_actions — every Claude draft (tokens, for the daily cap), every send (what Yui
--                 suggested vs what Tim sent: sent unchanged / edited, his edit kept as the
--                 next draft for that situation) and every "Handled" (the item leaves her list).
--   Message history reuses sales_messages (sends are logged there, mailbox 'yui'), and
--   Charlie's dismiss/snooze (charlie_items) is honoured — no other tables.
-- Code is guarded: Yui's card renders nothing until this runs; her brief for Charlie works
--   without it (Handled then only via Charlie).
-- MySQL 5.7 compatible: TEXT, no JSON type, no generated columns, no window functions.

CREATE TABLE IF NOT EXISTS yui_actions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_key VARCHAR(120) NOT NULL  COMMENT 'Yui''s stable item key, e.g. yui:reply:41:ab12…, yui:arrears:company-12',
  kind VARCHAR(30) NULL           COMMENT 'client_reply | promise | account_* | renewal_ending | renewal_season | checkin | arrears',
  template_key VARCHAR(20) NULL   COMMENT 'reply | checkin | renewal | season | arrears',
  contact_id INT NULL             COMMENT 'Who the message went to (or the item''s contact)',
  channel VARCHAR(10) NULL        COMMENT 'email | sms',
  drafted_by VARCHAR(20) NULL     COMMENT 'template | learned | claude',
  suggested_body TEXT NULL        COMMENT 'What Yui (or Claude) drafted',
  final_subject VARCHAR(255) NULL,
  final_body TEXT NULL            COMMENT 'What Tim actually sent',
  learned_body TEXT NULL          COMMENT 'Tim''s edit with the client''s details put back as {placeholders} — Yui''s next draft for this template',
  status VARCHAR(20) NOT NULL DEFAULT 'drafted'
    COMMENT 'drafted (Claude draft, not sent) | sent (unchanged) | edited | handled | failed',
  input_tokens INT NULL,
  output_tokens INT NULL,
  error VARCHAR(255) NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ya_key (item_key, status),
  INDEX idx_ya_status (status, decided_at),
  INDEX idx_ya_template (template_key, status),
  INDEX idx_ya_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Yui the comms head: drafts, what Tim sent, and what he marked handled';
