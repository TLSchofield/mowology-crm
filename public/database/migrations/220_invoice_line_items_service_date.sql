-- Migration 220: Add service_date to invoice_line_items
-- Records the actual date a service was performed (from job_visits.scheduled_date).
-- NULL is intentional for manually-created line items with no visit context.

ALTER TABLE invoice_line_items
    ADD COLUMN service_date DATE NULL DEFAULT NULL AFTER visit_id;

-- Backfill existing rows that are linked to a visit
UPDATE invoice_line_items ili
JOIN   job_visits jv ON jv.id = ili.visit_id
SET    ili.service_date = jv.scheduled_date
WHERE  ili.visit_id IS NOT NULL
AND    ili.service_date IS NULL;
