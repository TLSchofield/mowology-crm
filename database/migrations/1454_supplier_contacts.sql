-- Migration 1454: supplier contacts, each with where we found it (Tim approved 2026-11-13).
-- Plan: docs/plans/2026-11-13-supplier-quote-requests.md, step 1. Service: SupplierContactService (Operations).
-- A supplier can have a wholesale person and an orders address; the source sits with each address so a quote
-- request goes to the one a person answers. supplier_accounts.email stays "who we write to" and is set from the
-- kept default here, so everything that reads it today is unchanged. Private to the business (Ittai).
-- MySQL 5.7: plain DDL.

CREATE TABLE IF NOT EXISTS supplier_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  supplier_account_id INT NOT NULL COMMENT 'supplier_accounts.id',
  email VARCHAR(255) NOT NULL COMMENT 'Lower case',
  person VARCHAR(120) NULL COMMENT 'Name shown with the address, when there is one',
  role VARCHAR(12) NOT NULL DEFAULT 'general' COMMENT 'wholesale | orders | sales | accounts | general',
  sources VARCHAR(40) NOT NULL COMMENT 'mail | website | manual, | separated',
  mail_ref VARCHAR(255) NULL COMMENT 'Where and when it wrote to us (mailbox + date), never the body',
  web_ref VARCHAR(500) NULL COMMENT 'The page it is listed on',
  first_seen DATETIME NULL,
  last_seen DATETIME NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'suggested' COMMENT 'suggested | kept | dropped',
  is_default TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'The one quote requests go to',
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_supplier_contact (supplier_account_id, email),
  KEY idx_supplier_contact_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
