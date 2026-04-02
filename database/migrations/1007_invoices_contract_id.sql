-- Migration 1007: Add contract_id to invoices table
-- Enables idempotency check in the contract_billing cron:
-- "has an invoice already been generated for this contract this month?"
--
-- No FK constraint — contracts may be archived/deleted independently of invoices.

ALTER TABLE invoices
    ADD COLUMN contract_id INT UNSIGNED NULL DEFAULT NULL AFTER plan_id,
    ADD INDEX idx_invoices_contract_id (contract_id);
