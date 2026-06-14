-- Migration 1018: Add bank_account_id FK to accounting_transactions and bank_import_sessions
-- Allows bank imports to be linked to a specific Chart of Accounts bank account (sub_type='bank')
-- so that the Transaction Ledger "Account" filter returns imported transactions correctly.

ALTER TABLE accounting_transactions
    ADD COLUMN bank_account_id INT NULL DEFAULT NULL;

ALTER TABLE bank_import_sessions
    ADD COLUMN bank_account_id INT NULL DEFAULT NULL;
