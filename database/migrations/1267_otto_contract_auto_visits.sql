-- Migration 1267: Otto — visits he logged by himself at contract sites
-- Date: 2026-10-08
-- Purpose: when the truck / crew worked at a property covered by an ACTIVE, non-per-visit contract
--   (Vancouver Management buildings: Oakridge Gardens, 1650 W 13th, …) with no visit for that time,
--   Otto does not ask (owner, 2026-10-07): he logs a completed job_visit with the observed times on the
--   contract's plan, marked covered by the contract (is_invoiced = 1, linked to that month's contract
--   invoice when there is one) so it never reaches per-visit invoicing or the unbilled lists, and
--   nothing is sent to the client. One row per property per day (idempotent); Undo cancels the visit.
--   A refusal (it could double-bill, no plan, no crew…) is not stored — Otto asks as usual.
-- Code is guarded: nothing is auto-logged until this has run.
-- MySQL 5.7 compatible.

CREATE TABLE IF NOT EXISTS otto_auto_visits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  day DATE NOT NULL,
  kind VARCHAR(16) NOT NULL DEFAULT 'unscheduled' COMMENT 'unscheduled | extra',
  contract_id INT NULL,
  plan_id INT NULL,
  visit_id INT NULL,
  invoice_id INT NULL COMMENT 'The contract invoice for that month the visit was linked to, if any',
  start_time TIME NULL,
  end_time TIME NULL,
  minutes INT NULL,
  status ENUM('logging','logged','undone','failed') NOT NULL DEFAULT 'logging',
  reason VARCHAR(255) NULL,
  evidence TEXT NULL COMMENT 'What Otto saw (truck stops, phones, neighbours explained)',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  undone_by INT NULL,
  undone_at DATETIME NULL,
  UNIQUE KEY uq_oav_property_day (property_id, day),
  INDEX idx_oav_day (day, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
