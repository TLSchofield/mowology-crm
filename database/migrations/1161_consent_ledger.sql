-- Migration 1161: Consent ledger — one place that says who may receive marketing, and why
-- Date: 2026-10-05
-- Purpose: per contact and channel, every consent with its type (express / implied),
--   source, date, proof (the checkbox wording, timestamp and IP, or the job / invoice that
--   created it) and expiry; withdrawals too. Unsubscribes stay in marketing_unsubscribes
--   and always win.
--   Implied consent (CASL): a paid invoice or a completed job gives 2 years from that date.
--   The ledger is FILLED by ConsentLedgerService::refresh() (app/Modules/Consent), which is
--   idempotent and runs on Mia's prepare: it backfills implied consent from job and invoice
--   history, express consent from consent_log (quote form), confirmed double opt-ins and
--   the contacts' express fields, and withdrawals from consent_log. It also lifts
--   contacts.consent_email_implied_at / consent_email_express_at to match the proof, so the
--   existing canSendMarketing() checks (campaign sender) honour the same record.
-- Reserved range 1160–1169. MySQL 5.7 compatible. No foreign keys (collation drift on prod).

CREATE TABLE IF NOT EXISTS consent_ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contact_id INT NOT NULL,
  channel VARCHAR(10) NOT NULL COMMENT 'email | sms',
  consent_type VARCHAR(10) NOT NULL COMMENT 'express | implied | withdrawn',
  source VARCHAR(40) NOT NULL COMMENT 'website_form | optin_email | contact_record | completed_visit | paid_invoice | unsubscribe_link | crm_user',
  proof_key VARCHAR(80) NOT NULL COMMENT 'what proves it: consent_log:12, optin:9, visit:4410, invoice:388, contact:55',
  proof_text TEXT NULL COMMENT 'the wording agreed to, the IP, the job — human-readable proof',
  granted_at DATETIME NOT NULL COMMENT 'when the consent (or withdrawal) happened',
  expires_at DATETIME NULL COMMENT 'implied: granted_at + 2 years; express: NULL (until withdrawn)',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_consent_proof (contact_id, channel, consent_type, proof_key),
  INDEX idx_consent_contact (contact_id, channel),
  INDEX idx_consent_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Consent ledger: who may receive marketing, on what basis, with proof and expiry';
