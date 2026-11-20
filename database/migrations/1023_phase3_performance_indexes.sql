-- Migration 1023: Phase 3 performance indexes
-- Date: 2026-05-09
-- Purpose: Add the missing composite indexes identified in the architectural
--          audit for hot-path queries on schedule, expense, jobs, quotes,
--          invoices, properties, and contacts modules.
--
-- Notes on what is NOT included:
--   * idx_cs_date_crew_route (stop_date, crew_id, route_order)
--       SKIPPED — calendar_stops already has idx_route on the exact same
--       three columns (migration 200). Adding a duplicate would only cost
--       INSERT/UPDATE performance with zero query benefit.
--   * uniq_acct_source on accounting_transactions
--       SKIPPED — accounting_transactions already has
--       UNIQUE KEY uq_source (reference_type, reference_id) from migration
--       980. The functional purpose (idempotent batch upsert by source) is
--       already covered.
--
-- DDL only, no DML, no transaction (MySQL DDL auto-commits anyway).
-- The migrations_log table prevents re-runs, so plain CREATE INDEX is safe.

CREATE INDEX idx_jv_stop_status        ON job_visits     (stop_id, status);
CREATE INDEX idx_jp_recur_status_horiz ON job_plans      (is_recurring, status, visits_generated_through);
CREATE INDEX idx_jv_plan_date          ON job_visits     (plan_id, scheduled_date);
CREATE INDEX idx_cs_property_date      ON calendar_stops (property_id, stop_date);
CREATE INDEX idx_e_status_date         ON expenses       (status, expense_date);
CREATE INDEX idx_q_status_created      ON quotes         (status, created_at);
CREATE INDEX idx_i_company_status_due  ON invoices       (company_id, status, due_date);
CREATE INDEX idx_p_status_created      ON properties     (status, created_at);
CREATE INDEX idx_c_prospect_lifecycle  ON contacts       (prospect_status, lifecycle_stage);
