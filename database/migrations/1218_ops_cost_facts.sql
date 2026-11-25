-- Migration 1218: shared cost facts + trip overhead tagged to jobs (Otto writes, Sam / Penny / Charlie read)
-- Date: 2026-10-07
-- Purpose: heads share facts cheaply. Each fact is written once by the head that owns it and the
--   others read a small summary row with plain SQL — never the raw trail, never an AI call.
--   ops_cost_facts: Otto's medians for one-man dump / supplier runs (from ops_trip_runs), recomputed
--     by the trip_runs_daily cron (CostFactsService::refresh). Sam reads it to suggest a "Material
--     pickup" / "Disposal run" line on a quote (only when sample_n >= 3).
--   ops_trip_job_costs: Penny's attribution of each run (ops_trip_runs row) to the job visited from
--     that property that day — the trip's time + km, the dump fee, and receipt lines split into job
--     materials vs shop stock. Rebuilt per date by the same cron (TripAttributionService::attribute).
--     Job profitability and Charlie's brief read it; no journal entries are posted from it.
-- Requires migration 1216 (ops_trip_runs). MySQL 5.7 compatible (no JSON, no window functions);
-- safe to re-run.

CREATE TABLE IF NOT EXISTS ops_cost_facts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fact_key VARCHAR(64) NOT NULL COMMENT 'run:dump:place:1, run:supplier:place:<id>, run:dump:any, run:supplier:any',
  label VARCHAR(160) NOT NULL,
  kind VARCHAR(12) NOT NULL COMMENT 'dump | supplier',
  place_id INT NULL COMMENT 'NULL = every place of this kind',
  sample_n INT NOT NULL DEFAULT 0 COMMENT 'one-man runs with a pay rate behind the medians',
  median_onsite_min DECIMAL(6,1) NULL,
  median_round_trip_min DECIMAL(6,1) NULL COMMENT 'drive share + time on site',
  median_km DECIMAL(7,2) NULL,
  median_trip_cost DECIMAL(10,2) NULL COMMENT 'labour + truck only',
  median_receipt DECIMAL(10,2) NULL COMMENT 'dump fee median, from runs with a linked receipt',
  receipt_n INT NOT NULL DEFAULT 0,
  median_cost DECIMAL(10,2) NULL COMMENT 'what a run costs us: trip cost (+ dump fee median for dump runs; supplier materials are billed on their own line)',
  avg_cost DECIMAL(10,2) NULL,
  last_run_date DATE NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ops_cost_facts_key (fact_key),
  INDEX idx_ops_cost_facts_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Shared cost facts: written by Otto (cron), read by Sam, Penny and Charlie';

CREATE TABLE IF NOT EXISTS ops_trip_job_costs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL COMMENT 'ops_trip_runs.id',
  run_date DATE NOT NULL,
  trip_key VARCHAR(40) NULL,
  kind VARCHAR(12) NOT NULL COMMENT 'ops_trip_runs.kind',
  property_id INT NULL COMMENT 'where the run started (or returned to)',
  job_plan_id INT NULL COMMENT 'job_plans.id visited at that property that day; NULL = no job / shop stock',
  visit_id INT NULL,
  source ENUM('trip','receipt','receipt_line') NOT NULL COMMENT 'trip = time + km; receipt = whole receipt (single purpose); receipt_line = one line of a mixed receipt',
  expense_id INT NULL,
  expense_line_id INT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  is_stock TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = shop stock, stays job-less on 5200 Materials & Supplies',
  label VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ops_tjc_job (job_plan_id),
  INDEX idx_ops_tjc_date (run_date),
  INDEX idx_ops_tjc_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Penny: trip overhead attributed to jobs (time + km, dump fees, job vs stock receipt lines)';
