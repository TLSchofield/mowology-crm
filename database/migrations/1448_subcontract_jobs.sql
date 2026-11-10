-- Migration 1448: give a visit to another company from the system (2026-11-10)
-- Purpose: the owner hands a visit to a subcontractor through one private link instead of a text. The subcontractor accepts,
-- says when, marks on my way / started / finished, sends a bill. The owner sees each step and accepts the work.
-- Only the SHA-256 of a link is stored, same as visit_share_tokens. Subcontract time is never written to job_time_entries,
-- so it cannot reach payroll. subcontract_events is the timeline and also counts what the marketing panel brings in.
-- MySQL 5.7, utf8mb4_general_ci. Safe to run twice.
CREATE TABLE IF NOT EXISTS subcontractors (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  company_name  VARCHAR(191) NOT NULL,
  contact_name  VARCHAR(120) NULL,
  email         VARCHAR(191) NULL,
  phone         VARCHAR(30)  NULL,
  vendor_id     INT NULL COMMENT 'vendors.id: who the bill is booked against',
  on_ittai      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = already a business on Ittai, so no marketing panel',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_by    INT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sub_company (company_name),
  INDEX idx_sub_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS subcontract_jobs (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  subcontractor_id    INT NOT NULL,
  visit_id            INT NOT NULL,
  plan_id             INT NOT NULL,
  token_hash          CHAR(64) NOT NULL COMMENT 'SHA-256 hex of the raw token; the raw token is only ever in the link',
  token_preview       VARCHAR(12) NOT NULL,
  expires_at          DATETIME NOT NULL,
  revoked_at          DATETIME NULL,
  last_opened_at      DATETIME NULL,
  open_count          INT NOT NULL DEFAULT 0,
  agreed_amount       DECIMAL(10,2) NULL COMMENT 'What the owner will pay, before tax. Not the client price.',
  note_to_sub         TEXT NULL,
  share_client_name   TINYINT(1) NOT NULL DEFAULT 1,
  share_client_phone  TINYINT(1) NOT NULL DEFAULT 1,
  after_the_fact      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = the work was already done off the system; the link asks for proof and the bill',
  work_status         VARCHAR(12) NOT NULL DEFAULT 'sent' COMMENT 'sent accepted declined scheduled on_way started finished cancelled',
  status_changed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  planned_date        DATE NULL,
  started_at          DATETIME NULL,
  finished_at         DATETIME NULL,
  work_accepted_at    DATETIME NULL COMMENT 'The owner has seen the work: only now does the visit complete',
  work_accepted_by    INT NULL,
  bill_status         VARCHAR(10) NOT NULL DEFAULT 'none' COMMENT 'none sent approved paid returned',
  bill_amount         DECIMAL(10,2) NULL,
  bill_gst            DECIMAL(10,2) NULL,
  bill_invoice_no     VARCHAR(60) NULL,
  bill_expense_id     INT NULL,
  bill_paid_at        DATETIME NULL,
  created_by          INT NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sj_token (token_hash),
  INDEX idx_sj_visit (visit_id),
  INDEX idx_sj_plan (plan_id),
  INDEX idx_sj_sub (subcontractor_id),
  INDEX idx_sj_status (work_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS subcontract_events (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  job_id      INT NOT NULL,
  visit_id    INT NOT NULL,
  event_type  VARCHAR(24) NOT NULL,
  actor       VARCHAR(5) NOT NULL DEFAULT 'sub' COMMENT 'sub | staff',
  user_id     INT NULL,
  note        TEXT NULL,
  latitude    DECIMAL(10,7) NULL,
  longitude   DECIMAL(10,7) NULL,
  accuracy_m  INT NULL,
  on_site     TINYINT(1) NULL COMMENT '1 at the property, 0 not, NULL no reading or the property has no pin',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_se_job (job_id, id),
  INDEX idx_se_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS subcontract_files (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  job_id         INT NOT NULL,
  kind           VARCHAR(8) NOT NULL COMMENT 'photo | invoice',
  stored_name    VARCHAR(60) NOT NULL COMMENT 'random name in the closed subcontract uploads folder',
  original_name  VARCHAR(191) NULL,
  mime_type      VARCHAR(60) NOT NULL,
  size_bytes     INT NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sf_job (job_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
