-- Migration 1320: supplier directory, company documents, supplier account forms (owner, 2026-10-10).
-- Plan: docs/plans/2026-10-10-supplier-account-forms.md. Services: SupplierDirectoryService,
-- CompanyDocumentService, SupplierFormService (Operations). Ittai: all three are the company's own
-- (private) data. MySQL 5.7: plain DDL.

-- One list of suppliers for Otto (where they are: ops_places) and Penny (the books: vendors).
CREATE TABLE IF NOT EXISTS supplier_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(255) NULL COMMENT 'Who we write to',
  domain VARCHAR(120) NULL COMMENT 'Mail from this domain is theirs (lower case, no www)',
  phone VARCHAR(40) NULL,
  website VARCHAR(255) NULL,
  address VARCHAR(255) NULL,
  sells VARCHAR(120) NULL COMMENT 'nursery|soil|aggregate|sod|seed|equipment|chemicals — separated by |',
  status VARCHAR(16) NOT NULL DEFAULT 'asked' COMMENT 'asked | applied | open | declined | none',
  list_kind VARCHAR(16) NULL COMMENT 'priced | stock | quote — what their list gives',
  vendor_id INT NULL COMMENT 'vendors.id (Penny)',
  place_id INT NULL COMMENT 'ops_places.id (Otto)',
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  asked_at DATETIME NULL,
  applied_at DATETIME NULL,
  last_heard_at DATETIME NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_supplier_domain (domain),
  KEY idx_supplier_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The company's papers: business licence, insurance, WorkSafeBC clearance… Files live in
-- STORAGE_ROOT/company-docs (app/ is closed to the web).
CREATE TABLE IF NOT EXISTS company_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_type VARCHAR(40) NOT NULL COMMENT 'business_licence | insurance | wcb_clearance | other',
  title VARCHAR(160) NOT NULL,
  doc_number VARCHAR(60) NULL,
  issued_on DATE NULL,
  expires_on DATE NULL,
  file_path VARCHAR(255) NOT NULL COMMENT 'Relative to STORAGE_ROOT',
  file_name VARCHAR(160) NOT NULL,
  mime VARCHAR(60) NOT NULL DEFAULT 'application/pdf',
  source VARCHAR(20) NOT NULL DEFAULT 'upload' COMMENT 'upload | email',
  message_key VARCHAR(191) NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_company_doc_type (doc_type, is_current),
  UNIQUE KEY uq_company_doc_message (message_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- An account application a supplier sent us, filled by Otto, sent on the owner's yes.
CREATE TABLE IF NOT EXISTS supplier_forms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT NOT NULL COMMENT 'supplier_accounts.id',
  message_key VARCHAR(191) NOT NULL,
  mailbox VARCHAR(20) NOT NULL,
  from_addr VARCHAR(255) NOT NULL,
  cc_addrs VARCHAR(500) NULL COMMENT 'Others on their email (e.g. their accounting) — copied on our reply',
  subject VARCHAR(255) NULL,
  received_at DATETIME NOT NULL,
  original_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(160) NOT NULL,
  filled_path VARCHAR(255) NULL,
  answers_json TEXT NULL COMMENT 'field => value Otto filled',
  blanks_json TEXT NULL COMMENT 'fields Otto could not answer',
  wants_licence TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(12) NOT NULL DEFAULT 'ready' COMMENT 'ready | sent | declined | failed | unfillable',
  sent_at DATETIME NULL,
  error VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_supplier_form (message_key, original_name),
  KEY idx_supplier_form_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO ops_settings (setting_key, setting_value, description) VALUES
  ('company_legal_name', '', 'Legal name as on the business licence (supplier forms). Blank = business_settings.company_name'),
  ('company_contact_name', '', 'Who suppliers deal with (supplier forms)'),
  ('company_province', 'BC', 'Province for forms that ask for it'),
  ('licence_sender', '', 'Email address the city sends the business licence from — Yui files it automatically')
ON DUPLICATE KEY UPDATE description = VALUES(description);
