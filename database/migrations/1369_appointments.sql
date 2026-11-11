-- Migration 1369: appointments — a booked time at a client's place that isn't a job visit (2026-10-15)
--   Tim booked Anita Venier's quote visit by phone (request #72, Fri Oct 16 8:15) and the CRM had nowhere to
--   put it: schedule visits belong to a job plan, and there is no job before the quote. An appointment is its
--   own row, shown on the schedule (web, crew app, iPhone) beside the job stops, never mixed into them.
--   kind: quote_visit today; the word is trade-neutral so other tenants can book consults, inspections, etc.
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS appointments (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  kind              VARCHAR(20)  NOT NULL DEFAULT 'quote_visit' COMMENT 'quote_visit',
  quote_request_id  INT          NULL,
  contact_id        INT          NULL,
  property_id       INT          NULL,
  appt_date         DATE         NOT NULL,
  appt_time         TIME         NOT NULL,
  duration_minutes  INT          NOT NULL DEFAULT 45,
  assigned_to       INT          NULL     COMMENT 'users.id of the person going',
  status            VARCHAR(12)  NOT NULL DEFAULT 'booked' COMMENT 'booked | done | cancelled',
  notes             TEXT         NULL,
  confirmed_at      DATETIME     NULL     COMMENT 'when the client was sent a confirmation',
  created_by        INT          NULL,
  created_at        DATETIME     NULL,
  updated_at        DATETIME     NULL,
  KEY idx_appt_day (appt_date, status),
  KEY idx_appt_request (quote_request_id),
  KEY idx_appt_assigned (assigned_to, appt_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
