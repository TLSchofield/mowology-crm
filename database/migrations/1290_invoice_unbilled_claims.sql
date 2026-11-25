-- Migration 1290: invoice_unbilled_claims — audit of missed work added to an invoice
-- Date: 2026-10-08
-- Purpose: when an invoice is raised for a property, UnbilledWorkFinder offers other unbilled
--   work at the same address (last 60 days): completed-not-invoiced visits, skipped/scheduled
--   visits with a job timer or Otto evidence ("possibly done"), and completed visits priced $0.
--   Each line the person ticks is written here in the same transaction as the invoice: which
--   visit, what it was (kind + its status before), the day the work was really done, the amount
--   billed, the evidence shown, who ticked it and when. Real case: visit #2112 (TIM LOUIS,
--   property 29) was timed Tue Sep 29, then marked skipped, so it was never billed.
-- Code is guarded: claims still work before this runs (the visit's completion_notes carry the
--   audit note); only this log is skipped, with an error_log line.
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS invoice_unbilled_claims (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  visit_id INT NOT NULL,
  kind VARCHAR(20) NOT NULL              COMMENT 'completed | possibly_done | zero_price',
  previous_status VARCHAR(20) NULL       COMMENT 'job_visits.status before the claim',
  scheduled_date DATE NULL               COMMENT 'job_visits.scheduled_date before the claim',
  service_date DATE NOT NULL             COMMENT 'Day the work was done (timer / completion day)',
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  evidence VARCHAR(1000) NULL            COMMENT 'Evidence + warnings shown when it was ticked',
  claimed_by INT NULL,
  claimed_at DATETIME NOT NULL,
  INDEX idx_iuc_visit (visit_id),
  INDEX idx_iuc_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
