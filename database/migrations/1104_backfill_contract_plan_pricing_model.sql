-- Migration 1104 — backfill job_plans.pricing_model for plans linked to a formal
-- contract whose billing_cycle isn't per-visit, but whose pricing_model was wrongly
-- hardcoded to 'per_visit' at creation (bug in create-from-quote.php, fixed alongside
-- this migration). Idempotent DML — only touches rows that are currently wrong.
-- Cosmetic/reporting fix only; the runtime "Complete & Invoice" gate is enforced live
-- off job_plans.contract_id + contracts.status/billing_cycle, not this column.
UPDATE job_plans jp
JOIN contracts c ON jp.contract_id = c.id
SET jp.pricing_model = CASE c.billing_cycle
        WHEN 'monthly'   THEN 'monthly_flat'
        WHEN 'seasonal'  THEN 'seasonal'
        WHEN 'per_visit' THEN 'per_visit'
        ELSE 'custom'
    END
WHERE jp.pricing_model = 'per_visit'
  AND c.billing_cycle <> 'per_visit';
