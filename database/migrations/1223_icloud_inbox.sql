-- Migration 1223: Tim's iCloud mailbox as a read-only source for the department heads
-- Date: 2026-10-07
-- Purpose: IcloudInboxRouter (app/Modules/Comms/Services/IcloudInboxRouter.php) reads
--   mowology@icloud.com READ-ONLY every 15 minutes and hands each message to the existing
--   storage: customer mail → sales_messages (mailbox 'icloud …'), Interac / Yardi notices →
--   etransfer_notifications, vendor receipts → receipt_inbox_messages + a pending expense.
--   This migration adds only what did not exist yet:
--     1. mailbox_poll_state     — per mailbox + folder: UIDVALIDITY, last UID read, first run
--                                 (the 90-day backfill happens once, then only new UIDs).
--     2. email_lead_candidates  — work enquiries from unknown senders. 'created' = a
--                                 quote_requests lead was made (strong), 'maybe' = waiting on
--                                 Sam's card for Tim to accept or dismiss (weak).
--     3. vendor_messages        — mail to/from a known vendor that is not a receipt (there was
--                                 no vendor timeline to reuse; vendors.notes is free text).
--     4. sales_messages.message_key stays the Message-ID dedupe: an email that reached both
--        office@ and iCloud is stored once. Guarded check only — 1140 already made it UNIQUE.
--   Personal mail (everything else) is never stored: the router logs counts only.
--   Only the new text is kept (quoted history stripped, <= 800 characters).
-- MySQL 5.7 compatible (no JSON, no generated columns, no window functions); safe to re-run.

-- 1. Per-mailbox read position
CREATE TABLE IF NOT EXISTS mailbox_poll_state (
  mailbox_key VARCHAR(30) NOT NULL       COMMENT 'MailboxConfig key: icloud, office…',
  folder VARCHAR(120) NOT NULL           COMMENT 'IMAP folder name (INBOX, Sent Messages…)',
  uid_validity BIGINT NULL               COMMENT 'Folder UIDVALIDITY — a change resets last_uid',
  last_uid BIGINT NOT NULL DEFAULT 0     COMMENT 'Highest UID already read',
  first_run_at DATETIME NULL             COMMENT 'When this folder was first read (backfill + money-route floor)',
  last_run_at DATETIME NULL,
  last_summary VARCHAR(255) NULL         COMMENT 'Counts only, never content',
  PRIMARY KEY (mailbox_key, folder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Where each read-only mailbox reader got to';

-- 2. Work enquiries found in email
CREATE TABLE IF NOT EXISTS email_lead_candidates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_key VARCHAR(191) NOT NULL      COMMENT 'Message-ID (or a hash) — each email once',
  mailbox VARCHAR(60) NOT NULL,
  from_name VARCHAR(255) NULL,
  from_addr VARCHAR(255) NOT NULL,
  subject VARCHAR(255) NULL,
  snippet TEXT NULL                      COMMENT 'The new text only, up to ~800 characters',
  address VARCHAR(255) NULL              COMMENT 'Street address spotted in the text, if any',
  services VARCHAR(255) NULL             COMMENT 'Comma-separated service words spotted',
  strength VARCHAR(10) NOT NULL          COMMENT 'strong | weak',
  score INT NOT NULL DEFAULT 0,
  status VARCHAR(12) NOT NULL DEFAULT 'maybe' COMMENT 'maybe | created | dismissed',
  quote_request_id INT NULL,
  contact_id INT NULL,
  received_at DATETIME NOT NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_elc_key (message_key),
  INDEX idx_elc_status (status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Work enquiries read from email (iCloud): leads made, or maybe-leads for Tim';

-- 3. Vendor correspondence (not receipts)
CREATE TABLE IF NOT EXISTS vendor_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_key VARCHAR(191) NOT NULL      COMMENT 'Message-ID (or a hash) — each email once',
  mailbox VARCHAR(60) NOT NULL,
  vendor_id INT NOT NULL,
  direction VARCHAR(10) NOT NULL         COMMENT 'inbound | outbound',
  from_addr VARCHAR(255) NULL,
  to_addr VARCHAR(255) NULL,
  subject VARCHAR(255) NULL,
  snippet TEXT NULL                      COMMENT 'The new text only, up to ~800 characters',
  sent_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vm_key (message_key),
  INDEX idx_vm_vendor (vendor_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Read-only log of mail to/from known vendors that is not a receipt';

-- 4. sales_messages dedupe by Message-ID (already UNIQUE since 1140 — add only if missing)
SET @t = (SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_messages');
SET @u = (SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_messages'
            AND COLUMN_NAME = 'message_key' AND NON_UNIQUE = 0);
SET @sql = IF(@t = 1 AND @u = 0,
    'ALTER TABLE sales_messages ADD UNIQUE KEY uq_sm_key (message_key)',
    'SELECT ''sales_messages.message_key already unique (or table missing — run 1140)'' AS info');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
