-- Migration 1100: Email-in expense capture (forward receipts → CRM → books)
--
-- Adds the dedup/audit log for receipts arriving by email at receipts@mowology.ca,
-- plus a `source` column on expenses so email-ingested receipts can be flagged and
-- surfaced in the pending-review panel on the Expenses page.
--
-- See app/Modules/Expenses/Cron/receipt_inbox_poll.php (the IMAP poller) and
-- app/Modules/Expenses/Services/ReceiptInboxService.php (ingest + auto-post gate).
--
-- MySQL 5.7/8.0 compatible: no IF NOT EXISTS on ALTER, plain CREATE INDEX.

-- 1) Audit / dedup log — one row per attachment seen on the mailbox.
CREATE TABLE receipt_inbox_messages (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dedup_key        VARCHAR(255) NOT NULL,                 -- message-id:sha256, else sha:sha256
  sender_email     VARCHAR(255) NULL,
  subject          VARCHAR(255) NULL,
  email_date       DATETIME NULL,
  attachment_name  VARCHAR(255) NULL,
  media_id         INT NULL,                              -- → media_assets.id (stored attachment)
  expense_id       INT NULL,                              -- → expenses.id (created record)
  outcome          VARCHAR(20) NOT NULL DEFAULT 'pending',-- auto_posted | pending | dismissed | skipped
  match_confidence INT NULL,
  note             VARCHAR(500) NULL,                     -- e.g. "no attachment", "pdf not OCR-able"
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_receipt_inbox_dedup (dedup_key),
  KEY idx_receipt_inbox_outcome (outcome),
  KEY idx_receipt_inbox_expense (expense_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2) Provenance flag on expenses: NULL/'manual' (legacy + camera), 'ios', 'email_inbox'.
--    The review panel filters on source='email_inbox' AND status='draft'.
ALTER TABLE expenses ADD COLUMN source VARCHAR(20) NULL DEFAULT NULL COMMENT 'manual|ios|email_inbox';
CREATE INDEX idx_expenses_source ON expenses (source);
