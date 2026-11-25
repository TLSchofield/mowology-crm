-- Migration 1206: the clues check — facts hidden in incoming money and mail, as one-click suggestions.
-- Date: 2026-10-07
-- Purpose:
--   clue_suggestions    one row per suggestion a department head made (Penny from money, Yui from
--                       mail): what was spotted, the evidence, and the exact change Apply would make.
--                       The unique key (kind, subject, proposed hash) means nothing is suggested twice:
--                       a dismissed clue never comes back, an accepted one is never re-offered.
--   clue_patterns       a per-pattern counter (strata:etransfer, title:strata manager, company:new, ...)
--                       of Tim's Apply / Not right decisions; a pattern he keeps rejecting goes quiet.
--   clue_scan_state     when the daily scan last ran (the first run looks back 90 days).
--   sales_messages.signature  the last lines before the quoted history (max 300 chars), stored at
--                       ingest for inbound mail from known contacts. Older messages stay NULL.
-- Nothing here changes a record: every change waits for Tim's click (ClueService::decide()).
-- MySQL 5.7 compatible. No ADD COLUMN IF NOT EXISTS: if sales_messages.signature already exists on a
--   server, skip that one statement (the runner reports "Duplicate column" and carries on).

CREATE TABLE IF NOT EXISTS clue_suggestions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(30) NOT NULL              COMMENT 'strata_plan | strata_mismatch | role | details | quote_signer',
  owner VARCHAR(10) NOT NULL             COMMENT 'penny (from money) | yui (from mail)',
  subject_type VARCHAR(20) NOT NULL      COMMENT 'property | contact',
  subject_id INT NOT NULL,
  contact_id INT NULL                    COMMENT 'The person it is about (contact page, activity log)',
  pattern VARCHAR(60) NOT NULL           COMMENT 'What spotted it (clue_patterns.pattern)',
  summary VARCHAR(500) NOT NULL          COMMENT 'The one-line suggestion, as Charlie and the card show it',
  evidence TEXT NOT NULL                 COMMENT 'What was seen, where and when',
  source VARCHAR(20) NOT NULL            COMMENT 'etransfer | bank | email',
  source_ref VARCHAR(60) NULL            COMMENT 'etransfer_notifications.id / bank_import_rows.id / sales_messages.id',
  source_at DATETIME NULL,
  proposed_change TEXT NOT NULL          COMMENT 'JSON {ops:[...]}: exactly what Apply does (TEXT, not JSON: MySQL 5.7 rule)',
  proposed_hash CHAR(40) NOT NULL        COMMENT 'sha1 of the ops without their before-values',
  status ENUM('open','accepted','dismissed') NOT NULL DEFAULT 'open',
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_clue (kind, subject_type, subject_id, proposed_hash),
  INDEX idx_clue_open (status, owner),
  INDEX idx_clue_contact (contact_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Clues check: suggested one-click fixes spotted in payments and mail';

CREATE TABLE IF NOT EXISTS clue_patterns (
  pattern VARCHAR(60) NOT NULL PRIMARY KEY,
  accepted INT NOT NULL DEFAULT 0,
  dismissed INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Clues check: which detector patterns Tim says are right';

CREATE TABLE IF NOT EXISTS clue_scan_state (
  source VARCHAR(20) NOT NULL PRIMARY KEY,
  last_run DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Clues check: when the daily scan last ran';

ALTER TABLE sales_messages
  ADD COLUMN signature VARCHAR(300) NULL COMMENT 'Inbound only: the sender signature block, for the clues check' AFTER snippet;
