-- Migration 1366: signed quotes that arrive by email (2026-10-15)
-- Plan: docs/plans/2026-10-15-signed-quotes-from-email.md
--   sam_acceptances  — a client email that looks like it accepts quotes (signed / approved words or a signed PDF),
--                      one row per quote it matched; Tim taps Accept (QuoteAcceptService) or Not this one.
--   quote_documents  — documents filed on a quote: the signed copy the client sent back (an inbound attachment).
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS sam_acceptances (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  message_key    VARCHAR(191) NOT NULL COMMENT 'sales_messages.message_key of the client email',
  quote_id       INT          NOT NULL,
  attachment_id  INT          NULL     COMMENT 'inbound_message_attachments.id — the signed copy for this quote, when one matched',
  evidence       VARCHAR(255) NULL     COMMENT 'why it matched: the filename or the words',
  status         VARCHAR(12)  NOT NULL DEFAULT 'open' COMMENT 'open | accepted | dismissed',
  decided_by     INT          NULL,
  decided_at     DATETIME     NULL,
  created_at     DATETIME     NOT NULL,
  UNIQUE KEY uq_sa_message_quote (message_key, quote_id),
  KEY idx_sa_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS quote_documents (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  quote_id       INT          NOT NULL,
  kind           VARCHAR(20)  NOT NULL DEFAULT 'signed' COMMENT 'signed',
  attachment_id  INT          NOT NULL COMMENT 'inbound_message_attachments.id',
  note           VARCHAR(255) NULL,
  added_by       INT          NULL,
  created_at     DATETIME     NOT NULL,
  UNIQUE KEY uq_qd_quote_attachment (quote_id, attachment_id),
  KEY idx_qd_quote (quote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- An inbound email whose attachments have been fully read (any count, even 0): the readers stop re-checking it.
CREATE TABLE IF NOT EXISTS inbound_attachment_scans (
  message_key  VARCHAR(191) NOT NULL PRIMARY KEY,
  files        INT          NOT NULL DEFAULT 0,
  scanned_at   DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
