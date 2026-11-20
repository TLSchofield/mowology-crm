-- Migration 1126: Penny's questions — unbilled materials
-- Date: 2026-10-05
-- Purpose: when an approved materials / dump / subcontractor receipt is assigned to a
--   job and no invoice for that job or property shows it, Penny asks: "Did you forget
--   to invoice it, or is it included in their contract?" The owner's answer is kept and
--   taught back: 'contract' and 'not_billable' answers stop the same question for that
--   job (or contract) and vendor; 'invoice' answers count toward "found to bill".
-- Code is guarded: the question check probes for the table and does nothing until this runs.
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS penny_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(30) NOT NULL DEFAULT 'unbilled_materials',
  expense_id INT NOT NULL,
  plan_id INT NULL,
  property_id INT NULL,
  contract_id INT NULL,
  vendor_id INT NULL,
  vendor_name VARCHAR(255) NULL,
  category VARCHAR(100) NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  question TEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open | answered',
  answer VARCHAR(20) NULL COMMENT 'invoice | contract | not_billable',
  answered_by INT NULL,
  answered_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pq_kind_expense (kind, expense_id),
  INDEX idx_pq_status (status),
  INDEX idx_pq_plan_vendor (plan_id, vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Questions Penny asks the owner, and his answers (what she learns from)';
