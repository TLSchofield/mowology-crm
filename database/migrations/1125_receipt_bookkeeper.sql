-- Migration 1125: Receipt bookkeeper
-- Date: 2026-10-05
-- Purpose: the AI bookkeeper proposes category, asset tag, job, GST/PST split and line
--   items for each receipt; the owner approves, edits or keeps the original side by side.
--   Every suggestion and the owner's decision is kept: the decision is the bookkeeper's
--   scorecard (per field, per vendor) and its worked examples for the next receipt, and
--   earns per-vendor auto-approval (off by default).
--   expenses.asset_tag: one Fuel category with a truck/equipment tag (Tim, 2026-10-05) —
--   feeds the GGOB (Great Game of Business) cost drill-down as two open-book lines.
-- Code is guarded: the bookkeeper probes for expense_suggestions and does nothing
--   until this runs.
-- MySQL 5.7 compatible: TEXT for JSON, no JSON type, no generated columns.

ALTER TABLE expenses
  ADD COLUMN asset_tag VARCHAR(20) NULL
    COMMENT 'truck | equipment — what the expense was for (fuel split for GGOB)';

CREATE TABLE IF NOT EXISTS expense_suggestions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  expense_id INT NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'live'
    COMMENT 'live = shown for review; backtest = scored against an already-approved receipt',
  model VARCHAR(60) NULL,
  used_image TINYINT(1) NOT NULL DEFAULT 0,
  current_json MEDIUMTEXT NULL   COMMENT 'Values the receipt had when the suggestion was made',
  suggestion_json MEDIUMTEXT NOT NULL COMMENT 'Per field: value, reason, confidence, rule',
  checks_json TEXT NULL          COMMENT 'Hard-check results (sums, GST/PST, category, job)',
  status VARCHAR(20) NOT NULL DEFAULT 'pending'
    COMMENT 'pending | accepted | edited | rejected | superseded | scored',
  outcome_json TEXT NULL         COMMENT 'Per field: accepted / overridden, and the final value',
  input_tokens INT NULL,
  output_tokens INT NULL,
  error VARCHAR(255) NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_es_expense (expense_id),
  INDEX idx_es_status (status, source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='AI bookkeeper suggestions and the owner''s decisions';
