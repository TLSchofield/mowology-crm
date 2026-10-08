-- Migration 1231: bank_statement_accounts — which bank / card statements Penny expects every month
-- Date: 2026-10-07
-- Purpose: Penny's statements check (StatementCoverageService) asks "is every statement in?".
--   It needs to know which accounts SHOULD have a statement each month, including a credit
--   card that has never been imported (2400 Credit Card Payable carries card payments but no
--   purchases). One row per chart_of_accounts account; the owner can rename a row, switch
--   `expected` off, or set the statement closing day (NULL = calendar month end).
--   Seeded from every account that has had an import, plus every credit-card account.
--   The service re-seeds new accounts with INSERT IGNORE and never overwrites an edited row.
-- MySQL 5.7 compatible (no JSON, no generated columns); safe to re-run.

CREATE TABLE IF NOT EXISTS bank_statement_accounts (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  account_id      INT NOT NULL COMMENT 'chart_of_accounts.id (bank asset or credit-card liability)',
  label           VARCHAR(80) NOT NULL,
  kind            ENUM('bank','card') NOT NULL DEFAULT 'bank',
  expected        TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = a statement is expected every month',
  statement_day   TINYINT NULL COMMENT 'statement closing day of month; NULL = month end',
  bank_name_match VARCHAR(80) NULL COMMENT 'bank_import_sessions.bank_name of older imports saved without an account',
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NULL,
  UNIQUE KEY uq_bsa_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Statements Penny expects each month (StatementCoverageService)';

-- Every account that has had an import.
INSERT IGNORE INTO bank_statement_accounts (account_id, label, kind, expected, bank_name_match, created_at)
SELECT c.id, c.name,
       CASE WHEN c.sub_type = 'credit_card' OR c.type = 'liability' THEN 'card' ELSE 'bank' END,
       1,
       (SELECT s2.bank_name FROM bank_import_sessions s2
         WHERE s2.bank_account_id = c.id AND s2.bank_name IS NOT NULL AND s2.bank_name <> ''
         GROUP BY s2.bank_name ORDER BY COUNT(*) DESC LIMIT 1),
       NOW()
FROM chart_of_accounts c
WHERE c.id IN (SELECT s.bank_account_id FROM bank_import_sessions s
                WHERE s.status = 'imported' AND s.bank_account_id IS NOT NULL);

-- The credit card(s) — expected even though never imported.
INSERT IGNORE INTO bank_statement_accounts (account_id, label, kind, expected, created_at)
SELECT c.id, c.name, 'card', 1, NOW()
FROM chart_of_accounts c
WHERE c.sub_type = 'credit_card'
   OR (c.type = 'liability' AND (c.name LIKE '%credit card%' OR c.name LIKE '%visa%' OR c.name LIKE '%mastercard%'));

SELECT id, account_id, label, kind, expected FROM bank_statement_accounts ORDER BY kind, account_id;
