-- Migration 1392: Jobber quotes kept as history (read only)
-- Date: 2026-10-22
-- Purpose: Jobber's Quotes export (950 quotes 2016-2026, incl. archived / unanswered ones) shows what was
--   quoted, what the client accepted and what they did not. HISTORY ONLY: never creates a CRM quote, job,
--   invoice or ledger entry. Imported by JobberQuoteHistory (/crm/accounting/jobber-quotes.php), keyed by
--   quote_number so re-importing updates in place. Clients are linked with JobberImportService::matchClient.
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS jobber_quotes (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  quote_number    VARCHAR(40)   NOT NULL COMMENT 'Jobber quote # (without the #)',
  contact_id      INT           NULL,
  company_id      INT           NULL,
  property_id     INT           NULL,
  match_how       VARCHAR(30)   NULL,
  client_name     VARCHAR(200)  NULL,
  client_email    VARCHAR(255)  NULL,
  service_street  VARCHAR(255)  NULL,
  title           VARCHAR(255)  NULL,
  status          VARCHAR(30)   NOT NULL DEFAULT 'unknown' COMMENT 'converted | approved | archived | awaiting | draft | changes | unknown',
  status_raw      VARCHAR(60)   NULL,
  subtotal        DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'before GST',
  total           DECIMAL(12,2) NOT NULL DEFAULT 0,
  drafted_date    DATE          NULL,
  sent_date       DATE          NULL,
  approved_date   DATE          NULL,
  converted_date  DATE          NULL,
  archived_date   DATE          NULL,
  line_items      TEXT          NULL,
  imported_at     DATETIME      NULL,
  UNIQUE KEY uq_jq_number (quote_number),
  KEY idx_jq_contact (contact_id),
  KEY idx_jq_company (company_id),
  KEY idx_jq_property (property_id),
  KEY idx_jq_name (client_name),
  KEY idx_jq_sent (sent_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Jobber quotes kept as history (migration 1392)';

SELECT COUNT(*) AS jobber_quotes FROM jobber_quotes;
