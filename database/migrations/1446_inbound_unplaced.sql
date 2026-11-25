-- Migration 1446: office@ mail from people not on file is kept, not dropped (2026-11-09)
-- Purpose: a paralegal asked office@ for the balance on a numbered company; her address was not a contact, so the poll
-- dropped the mail and nobody knew. InboundIdentityService now works out who an unknown sender's mail is about; a
-- strong match goes to sales_messages, everything else lands here (and noise is recorded too, so the count is real).
-- Written only when ops_settings.inbox_place_unknown is on (default off). candidates is JSON text made by PHP.
-- MySQL 5.7, utf8mb4_general_ci. Idempotent.
CREATE TABLE IF NOT EXISTS inbound_unplaced (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  message_key VARCHAR(191) NOT NULL,
  mailbox     VARCHAR(60)  NOT NULL DEFAULT '',
  from_addr   VARCHAR(255) NOT NULL DEFAULT '',
  from_name   VARCHAR(120) NULL,
  subject     VARCHAR(255) NOT NULL DEFAULT '',
  snippet     TEXT NULL,
  sent_at     DATETIME NULL,
  kind        VARCHAR(10)  NOT NULL DEFAULT 'person' COMMENT 'person | noise',
  reason      VARCHAR(255) NOT NULL DEFAULT '',
  strength    VARCHAR(10)  NOT NULL DEFAULT 'none' COMMENT 'medium | weak | none',
  client_id   INT NULL,
  contact_id  INT NULL,
  evidence    VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'comma list: company, address, domain',
  candidates  TEXT NULL COMMENT 'json text: [{client_id, contact_id, name, evidence}]',
  status      VARCHAR(20)  NOT NULL DEFAULT 'open' COMMENT 'open | lead | confirmed | dismissed',
  decided_by  INT NULL,
  decided_at  DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_iu_message (message_key),
  KEY idx_iu_open (status, kind, sent_at),
  KEY idx_iu_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
