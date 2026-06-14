-- Migration 1045: Backfill service_date on invoice_line_items
-- The service_date column already exists (added by a prior migration).
-- This migration only backfills rows that are linked to a visit but have no date yet.

UPDATE invoice_line_items ili
JOIN   job_visits jv ON jv.id = ili.visit_id
SET    ili.service_date = jv.scheduled_date
WHERE  ili.visit_id IS NOT NULL
AND    ili.service_date IS NULL;
