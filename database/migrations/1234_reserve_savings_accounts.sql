-- Migration 1234: the two Vancity savings accounts get their own bank accounts + a move log
-- Date: 2026-10-07
-- Purpose: Vancity prints three accounts on one statement — chequing ••6801, "Reserve funds"
--   savings ••6819 and "GST RESERVES" savings ••6827. Every line was imported to 1010 Chequing.
--   BankAccountSplitService moves the savings lines (with their journal entries) to:
--     1020 → "Reserve funds (Savings ••6819)"   (renamed only while still the default "Savings Account")
--     1025 → "GST Reserves (Savings ••6827)"     (renamed only while still "GST Reserve Account";
--                                                  created if the chart has no 1025)
--   Both go on Penny's statements check (bank_statement_accounts: kind bank, expected).
--   A transfer between chequing and a savings account was imported twice (printed in both
--   sections): the move makes it ONE transfer — the chequing line's category becomes the savings
--   account, the savings line is filed on its own account and posts nothing.
--   bank_account_split_log keeps every change so a batch can be undone.
-- Needs migration 1231 (bank_statement_accounts) — its table is created here too if missing.
-- MySQL 5.7 compatible (no JSON, no generated columns, no window functions); safe to re-run.

-- ── Chart: rename while the names are still the defaults ─────────────────────
UPDATE chart_of_accounts SET name = 'Reserve funds (Savings ••6819)'
 WHERE code = '1020' AND name = 'Savings Account';

UPDATE chart_of_accounts SET name = 'GST Reserves (Savings ••6827)'
 WHERE code = '1025' AND name = 'GST Reserve Account';

-- 1025 if the chart doesn't have one: a bank asset beside 1010.
INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, parent_id, is_system, is_active, display_order, description)
SELECT '1025', 'GST Reserves (Savings ••6827)', 'asset', 'bank', 'debit', p.parent_id, 0, 1, 13, 'Vancity GST RESERVES savings ••6827'
FROM (SELECT parent_id FROM chart_of_accounts WHERE code = '1010' ORDER BY id LIMIT 1) p
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '1025') x);

-- Both are bank accounts (the importer's account list shows sub_type 'bank').
UPDATE chart_of_accounts SET sub_type = 'bank'
 WHERE code IN ('1020', '1025') AND (sub_type IS NULL OR sub_type = '');
UPDATE chart_of_accounts SET is_active = 1 WHERE code IN ('1020', '1025') AND is_active = 0;

-- ── Statements check: both expected every month ──────────────────────────────
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

INSERT IGNORE INTO bank_statement_accounts (account_id, label, kind, expected, bank_name_match, created_at)
SELECT c.id, 'Reserve funds (Savings ••6819)', 'bank', 1, 'Vancity ••6819', NOW()
FROM chart_of_accounts c WHERE c.code = '1020' ORDER BY c.id LIMIT 1;

INSERT IGNORE INTO bank_statement_accounts (account_id, label, kind, expected, bank_name_match, created_at)
SELECT c.id, 'GST Reserves (Savings ••6827)', 'bank', 1, 'Vancity ••6827', NOW()
FROM chart_of_accounts c WHERE c.code = '1025' ORDER BY c.id LIMIT 1;

-- Rows seeded earlier (by 1231 or the daily check) still carry the old default label.
UPDATE bank_statement_accounts a JOIN chart_of_accounts c ON c.id = a.account_id
   SET a.label = 'Reserve funds (Savings ••6819)', a.kind = 'bank', a.updated_at = NOW()
 WHERE c.code = '1020' AND a.label = 'Savings Account';
UPDATE bank_statement_accounts a JOIN chart_of_accounts c ON c.id = a.account_id
   SET a.label = 'GST Reserves (Savings ••6827)', a.kind = 'bank', a.updated_at = NOW()
 WHERE c.code = '1025' AND a.label = 'GST Reserve Account';

-- ── Move log (BankAccountSplitService apply / undo) ──────────────────────────
CREATE TABLE IF NOT EXISTS bank_account_split_log (
  id                     INT AUTO_INCREMENT PRIMARY KEY,
  batch_id               VARCHAR(40) NOT NULL COMMENT 'one apply; the undo key',
  chain_key              VARCHAR(20) NULL COMMENT 'the balance chain the line was in (c + first bank_import_rows.id)',
  kind                   VARCHAR(12) NOT NULL DEFAULT 'move' COMMENT 'move = savings line moved; mirror = its chequing twin made the transfer',
  transaction_id         INT NOT NULL COMMENT 'accounting_transactions.id',
  from_bank_account_id   INT NULL COMMENT 'bank_account_id before (NULL = defaulted to 1010)',
  to_bank_account_id     INT NOT NULL,
  old_account_id         INT NULL COMMENT 'category before, when the move changed it (paired transfer)',
  old_type               VARCHAR(20) NULL,
  old_gst_amount         DECIMAL(12,2) NULL,
  new_account_id         INT NULL COMMENT 'category after',
  old_entry_id           INT NULL COMMENT 'bank_import journal entry reversed by the move',
  reversal_entry_id      INT NULL,
  new_entry_id           INT NULL COMMENT 'entry posted to the savings account',
  moved_by               INT NULL,
  moved_at               DATETIME NOT NULL,
  undone_at              DATETIME NULL,
  undone_by              INT NULL,
  undo_reversal_entry_id INT NULL,
  undo_entry_id          INT NULL,
  KEY idx_basl_batch (batch_id),
  KEY idx_basl_tx (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Bank lines moved out of chequing to a savings account (migration 1234)';

SELECT code, name, sub_type FROM chart_of_accounts WHERE code IN ('1010', '1020', '1025') ORDER BY code;
SELECT id, account_id, label, kind, expected FROM bank_statement_accounts ORDER BY kind, account_id;
