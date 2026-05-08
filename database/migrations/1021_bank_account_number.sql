-- Migration 1021: Add account_number to chart_of_accounts for bank statement auto-detection
-- When a bank account's account number is stored here, the Bank Import tool can
-- detect the account from the statement text and auto-select the correct GL account.

ALTER TABLE chart_of_accounts
    ADD COLUMN account_number VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'Bank account number (digits only, no spaces) for auto-detection during PDF import'
    AFTER expense_category_alias;

ALTER TABLE chart_of_accounts
    ADD INDEX idx_account_number (account_number);
