-- Migration 1236: bank balance check — every invoice payment its own journal entry + a fix log
-- Date: 2026-10-07
-- Purpose: the trial balance shows 1010 Chequing about $580K "overdrawn" while the real account
--   closed Sept 2026 at $13,425.61. BankBalanceCheckService compares the journal balance of each
--   bank account with the statement's closing balance at every month end and proposes fixes Tim
--   approves on /crm/accounting/bank-balance-check.php:
--     - part payments: LedgerSyncService posted ONE payment per invoice (amount_paid at the first
--       sync) and never again, so later part payments never reached the journal. From now on each
--       payment is its own entry: 'payment_allocation' (invoice_payment_allocations.id) and
--       'stripe_payment' (stripe_payments.id); 'payment' (invoices.id) keeps only what has no record
--       of its own. Older gaps are a proposal, not automatic.
--     - Jobber-era deposits (before 2026-04-01) that never reached the journal: 'bank_deposit'
--       (accounting_transactions.id), DR bank / CR the deposit's revenue account.
--     - Opening balance per bank account at its first statement: 'bank_opening' (chart id) vs 3900.
--   Changes:
--     1. journal_entries.source_type ENUM → VARCHAR(30) (keeps every value; accepts the new ones).
--     2. bank_balance_fix_log — every posting / reversal an approval made, so a batch can be undone.
--     3. ops_settings 'ledger_payments_each_from' = now: the nightly sync posts each payment
--        recorded from this moment on by itself. Without the row the sync behaves as before.
--     4. 3900 Opening Balance Equity if the chart doesn't have it (1066 seeds it).
-- MySQL 5.7 compatible (no JSON, no generated columns, no window functions); safe to re-run.

-- ── 1. New journal sources ───────────────────────────────────────────────────
ALTER TABLE journal_entries
  MODIFY COLUMN source_type VARCHAR(30) NOT NULL DEFAULT 'manual'
  COMMENT 'invoice | payment | payment_allocation | stripe_payment | expense | bill | bank_import | bank_deposit | bank_opening | manual | adjusting | opening';

-- ── 2. Fix log (BankBalanceCheckService approve / undo) ──────────────────────
CREATE TABLE IF NOT EXISTS bank_balance_fix_log (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  batch_id         VARCHAR(40)   NOT NULL COMMENT 'one approval; the undo key',
  fix_group        VARCHAR(20)   NOT NULL COMMENT 'part_payments | jobber_deposits | opening',
  item_key         VARCHAR(60)   NOT NULL COMMENT 'inv:123 / tx:456 / acct:7',
  op               VARCHAR(10)   NOT NULL COMMENT 'post = entry posted; reverse = an old entry reversed',
  entry_id         INT           NULL COMMENT 'post: the entry posted; reverse: the reversal entry',
  target_entry_id  INT           NULL COMMENT 'reverse: the entry that was reversed',
  source_type      VARCHAR(30)   NULL,
  source_id        INT           NULL,
  amount           DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'cash effect on the bank account (+ in)',
  entry_date       DATE          NULL,
  created_by       INT           NULL,
  created_at       DATETIME      NOT NULL,
  undone_at        DATETIME      NULL,
  undone_by        INT           NULL,
  undo_entry_id    INT           NULL COMMENT 'post undone: its reversal; reverse undone: the re-posted copy',
  KEY idx_bbfl_batch (batch_id),
  KEY idx_bbfl_item (item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Bank balance check fixes Tim approved (migration 1236)';

-- ── 3. Each payment its own entry, from now on ───────────────────────────────
INSERT IGNORE INTO ops_settings (setting_key, setting_value, description)
VALUES ('ledger_payments_each_from', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'),
        'LedgerSyncService posts each invoice payment recorded from this moment as its own journal entry (migration 1236)');

-- ── 4. Opening Balance Equity ────────────────────────────────────────────────
INSERT INTO chart_of_accounts (code, name, type, sub_type, normal_balance, is_system, is_active, display_order)
SELECT '3900', 'Opening Balance Equity', 'equity', 'opening', 'credit', 1, 1, 390
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM chart_of_accounts WHERE code = '3900') x);

SELECT setting_key, setting_value FROM ops_settings WHERE setting_key = 'ledger_payments_each_from';
SELECT code, name FROM chart_of_accounts WHERE code IN ('1010', '1020', '1025', '2400', '3900') ORDER BY code;
