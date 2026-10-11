-- Migration 1323: where a bank deposit came from, when it isn't a CRM invoice (owner, 2026-10-11).
-- Jobber-era payments: the deposit stays income (the only income record for that era — see
-- Accounting-Ledger "Jobber import must not break this invariant"); this row says which Jobber payment
-- (payer, method, invoice numbers) it was, for Penny's per-client payment habits.
-- DepositBackfillService. MySQL 5.7.
CREATE TABLE IF NOT EXISTS bank_deposit_sources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT NOT NULL COMMENT 'accounting_transactions.id (the bank deposit)',
  source VARCHAR(20) NOT NULL COMMENT 'jobber_payment',
  source_id INT NOT NULL,
  contact_id INT NULL,
  payer_name VARCHAR(255) NULL,
  method VARCHAR(30) NULL,
  amount DECIMAL(10,2) NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bds_source (source, source_id),
  KEY idx_bds_tx (transaction_id),
  KEY idx_bds_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
