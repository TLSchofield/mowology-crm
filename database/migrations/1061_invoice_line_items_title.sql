-- Migration 1061: Add title column to invoice_line_items
-- Stores the service/plan name at creation time so invoices are
-- self-contained billing snapshots that do not depend on JOINs to
-- reconstruct what was sold. Nullable so existing rows are unaffected.

ALTER TABLE invoice_line_items
    ADD COLUMN title VARCHAR(255) NULL AFTER invoice_id;

-- Backfill title from job_plans for existing line items that have a visit link.
UPDATE invoice_line_items ili
JOIN   job_visits jv ON jv.id = ili.visit_id
JOIN   job_plans  jp ON jp.id = jv.plan_id
SET    ili.title = jp.title
WHERE  ili.visit_id IS NOT NULL
AND    ili.title IS NULL;
