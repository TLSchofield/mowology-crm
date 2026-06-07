-- 1050_invoices_backfill_contract_id.sql
-- Backfill invoices.contract_id from job_plans.contract_id via invoices.plan_id.
-- Required because earlier invoice creation paths (visit-completion via
-- pow-actions.php, manual create.php) set plan_id but never set contract_id,
-- which left contract Billing Summaries showing $0 even when paid invoices
-- existed against the contract.

UPDATE invoices i
JOIN job_plans jp ON jp.id = i.plan_id
SET i.contract_id = jp.contract_id
WHERE i.contract_id IS NULL
  AND jp.contract_id IS NOT NULL;
