-- Migration 1396: texts from numbers that are not a customer yet (Tim, 2026-10-22)
-- Why: the Messages bridge sent the CRM only texts from numbers that matched a customer, so a customer who texted from
--   a NEW number (John Hughes, 2026-10-22) was dropped silently and Yui never saw it. Now every one-to-one text goes up;
--   the ones with no customer wait here until Tim links the number to a customer (rows move into sales_messages) or
--   marks it personal (rows deleted, number blocked so the bridge never sends it again).
--   text_unmatched  — texts from a number with no customer; phone10 = the last 10 digits.
--   text_personal   — numbers marked personal: only a salted hash is kept, never the number.
-- MySQL 5.7 compatible. No ALTER on sales_messages (shared with Sam / Yui / Penny).

CREATE TABLE IF NOT EXISTS text_unmatched (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone10 CHAR(10) NOT NULL,
  message_key VARCHAR(191) NOT NULL COMMENT 'imsg-<guid>; same key it gets in sales_messages once linked',
  direction VARCHAR(10) NOT NULL COMMENT 'inbound | outbound',
  snippet TEXT NULL,
  sent_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tu_key (message_key),
  INDEX idx_tu_phone (phone10, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Texts from numbers that are not a customer yet';

CREATE TABLE IF NOT EXISTS text_personal (
  number_hash CHAR(64) NOT NULL PRIMARY KEY COMMENT 'sha256(salt + last 10 digits), same as the bridge',
  marked_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Numbers Tim marked personal: the bridge never sends them';
