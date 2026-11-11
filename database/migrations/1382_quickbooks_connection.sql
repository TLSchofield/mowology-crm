-- 1382 — QuickBooks Online connection, step 1: connect + read (owner, 2026-10-20: "build quickbooks api").
-- One way: Ittai sends to QuickBooks; from QuickBooks it only READS the account list, tax codes and
-- customers so its sends match what is there. A business chooses ONE set of books: QuickBooks
-- (this connection) or Ittai's own ledger (the journal export) — never both.
-- Tokens are AES-256-GCM encrypted with QBO_TOKEN_KEY (secrets.php): a wrong key fails loudly.
-- QboConnectionService. Tenant-neutral: one row per business (site_id).

CREATE TABLE IF NOT EXISTS qbo_connections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  environment VARCHAR(12) NOT NULL COMMENT 'sandbox | production',
  realm_id VARCHAR(32) NOT NULL COMMENT 'the QuickBooks company id',
  company_name VARCHAR(255) NULL,
  country VARCHAR(8) NULL,
  access_token_enc TEXT NULL,
  refresh_token_enc TEXT NULL,
  access_expires_at DATETIME NULL,
  refresh_expires_at DATETIME NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'connected' COMMENT 'connected | expired | disconnected | error',
  last_error VARCHAR(500) NULL,
  last_pull_at DATETIME NULL,
  connected_by INT NULL,
  connected_at DATETIME NULL,
  disconnected_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_qbo_site (site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- What was read from QuickBooks (accounts, tax codes, customers): the mapping screen's choices.
CREATE TABLE IF NOT EXISTS qbo_remote_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  kind VARCHAR(16) NOT NULL COMMENT 'account | tax_code | customer',
  qbo_id VARCHAR(32) NOT NULL,
  name VARCHAR(255) NOT NULL,
  detail VARCHAR(255) NULL COMMENT 'account type / tax rate / customer email',
  active TINYINT(1) NOT NULL DEFAULT 1,
  pulled_at DATETIME NOT NULL,
  UNIQUE KEY uq_qri (site_id, kind, qbo_id),
  KEY idx_qri_kind (site_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The owner's one-time choices: an Ittai account / tax treatment → a QuickBooks account / tax code.
CREATE TABLE IF NOT EXISTS qbo_mappings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL DEFAULT 1,
  kind VARCHAR(16) NOT NULL COMMENT 'account | tax_code',
  ittai_key VARCHAR(64) NOT NULL COMMENT 'chart_of_accounts.code, or a tax key (gst | gst_exempt | zero)',
  qbo_id VARCHAR(32) NOT NULL,
  qbo_name VARCHAR(255) NULL,
  set_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_qm (site_id, kind, ittai_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
