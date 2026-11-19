-- Migration 1301: Penny asks e-Transfer payers to use the Auto-deposit address
-- Date: 2026-10-08
-- Purpose: when a client pays by Interac e-Transfer to an address WITHOUT Auto-deposit
--   (Tim has to claim it with a security answer), Penny emails them once to say thank
--   you and ask them to send future payments to info@mowology.ca. One email per payer,
--   ever: the unique email_key is the guard. No table = no email (the code refuses to
--   send when it can't record that it sent).
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS etransfer_address_nudges (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  notification_id  BIGINT UNSIGNED NULL     COMMENT 'etransfer_notifications.id that triggered it',
  contact_id       INT           NULL       COMMENT 'contacts.id emailed (NULL for an email-only PM inbox)',
  email            VARCHAR(255)  NOT NULL,
  email_key        VARCHAR(190)  NOT NULL   COMMENT 'lower(trim(email)) - once per address, ever',
  sender_key       VARCHAR(190)  NULL       COMMENT 'normalised Interac sender name',
  sender_name      VARCHAR(255)  NULL,
  invoice_id       INT           NULL,
  invoice_number   VARCHAR(32)   NULL,
  amount           DECIMAL(10,2) NULL,
  trigger_source   VARCHAR(20)   NOT NULL DEFAULT 'ingest' COMMENT 'ingest / recorded / merged',
  status           VARCHAR(10)   NOT NULL DEFAULT 'sending' COMMENT 'sending / sent',
  sent_at          DATETIME      NULL,
  created_at       DATETIME      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_key (email_key),
  KEY idx_contact (contact_id),
  KEY idx_sender (sender_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Penny: one thank-you per payer asking them to e-Transfer to info@ (Auto-deposit)';

INSERT IGNORE INTO ops_settings (setting_key, setting_value, description)
VALUES ('penny_etransfer_nudge_enabled', '1',
        'Penny: email e-Transfer payers who needed a security answer, once each, asking them to use info@mowology.ca (1 = on, 0 = off)');
