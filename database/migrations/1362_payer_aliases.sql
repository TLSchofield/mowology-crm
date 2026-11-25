-- Migration 1362: payer aliases — one record of "who pays as whom" (plan docs/plans/2026-10-14-payer-identity.md)
-- Date: 2026-10-14
-- Purpose: the bank's, Interac's and a texter's wording for a payer lived in two tables that each
--   one reader knew about (etransfer_sender_payers 1132, bank_memo_payers — made on production with
--   no migration), and in nothing at all for Interac alert names ("Jo Gal" = Joseph Gallentine).
--   payer_aliases holds all of them; Penny's bank card, the e-Transfer matcher and the deposit
--   backfill read it. The old tables stay (written too) for one month, then go.
-- Tenant-private: never shared through the vendor knowledge commons.
-- MySQL 5.7 compatible. kind/source are VARCHAR, not ENUM: production is non-strict and silently
--   blanks an ENUM value it doesn't know (Known-Failure-Patterns 2026-10-14).

-- Record the drift table so a fresh install has it (no-op on production).
CREATE TABLE IF NOT EXISTS bank_memo_payers (
  id INT NOT NULL AUTO_INCREMENT,
  pattern VARCHAR(80) NOT NULL COMMENT 'Whole words in the bank memo, e.g. VML',
  payer_name VARCHAR(255) NOT NULL COMMENT 'The payer as invoices name it',
  company_id INT DEFAULT NULL,
  note VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bmp_pattern (pattern)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Bank memo words -> the payer they name (invoice matching)';

CREATE TABLE IF NOT EXISTS etransfer_sender_payers (
  sender_key VARCHAR(190) NOT NULL,
  payer_key VARCHAR(190) NOT NULL,
  sender_name VARCHAR(255) NULL,
  payer_name VARCHAR(255) NULL,
  times INT NOT NULL DEFAULT 1,
  last_seen DATETIME NULL,
  PRIMARY KEY (sender_key, payer_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS payer_aliases (
  id INT NOT NULL AUTO_INCREMENT,
  kind VARCHAR(20) NOT NULL COMMENT 'bank_memo | etransfer_sender | sms_name | email | phone',
  alias_key VARCHAR(190) NOT NULL COMMENT 'PayerIdentityService::key(kind, alias_raw)',
  alias_raw VARCHAR(255) NOT NULL,
  payer_name VARCHAR(255) NOT NULL COMMENT 'The payer as invoices name them',
  payer_key VARCHAR(190) NOT NULL COMMENT 'payer_name, upper-case letters and digits only',
  contact_id INT NULL,
  company_id INT NULL,
  source VARCHAR(10) NOT NULL DEFAULT 'learned' COMMENT 'tim (an owner said so) | learned | import',
  times INT NOT NULL DEFAULT 1,
  last_seen DATETIME NULL,
  note VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  removed_at DATETIME NULL COMMENT 'Set when an owner removes it; never deleted, so it is not re-learned',
  PRIMARY KEY (id),
  UNIQUE KEY uq_payer_alias (kind, alias_key, payer_key),
  KEY idx_pa_payer (payer_key),
  KEY idx_pa_contact (contact_id),
  KEY idx_pa_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Who pays as whom: bank memo words, e-Transfer senders, alert names, emails, phones';

INSERT IGNORE INTO payer_aliases (kind, alias_key, alias_raw, payer_name, payer_key, company_id, source, times, last_seen, note, created_at)
SELECT 'bank_memo',
       TRIM(UPPER(pattern)),
       pattern, payer_name,
       UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(payer_name, ' ', ''), '.', ''), ',', ''), '-', ''), ':', ''), '''', '')),
       company_id, 'learned', 1, created_at, note, created_at
FROM bank_memo_payers;

INSERT IGNORE INTO payer_aliases (kind, alias_key, alias_raw, payer_name, payer_key, source, times, last_seen, note, created_at)
SELECT 'etransfer_sender', sender_key, COALESCE(sender_name, sender_key), COALESCE(payer_name, payer_key), payer_key,
       'learned', times, last_seen, 'From recorded e-Transfers (1132)', COALESCE(last_seen, NOW())
FROM etransfer_sender_payers;
