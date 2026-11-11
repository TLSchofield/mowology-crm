-- Migration 1295: Special requests — a client's ask about work at a property already on the schedule
-- Date: 2026-10-08
-- Purpose:
--   A client emails / texts "run the mower over the front lawns ... and rake the leaves in the NW
--   corner" about properties that are on today's schedule. One row per request. It is attached to
--   the next scheduled visit(s) at the matched properties (special_request_visits, 1296) ONLY after
--   Tim confirms it with one tap on Yui's (client comms) or Otto's (operations) card — v1 never
--   attaches on its own.
--   status: pending (proposed from a message, waiting for Tim) | attached | dismissed | closed
--   head:   yui (came in from a client message) | otto (added by hand from a visit / Otto's card)
--   included_items / extra_items: one item per line (no JSON functions needed to read them).
--   proposal_json: SpecialRequestMatcher's proposal (properties, visits, why) — display only.
-- MySQL 5.7 compatible; safe to re-run.

CREATE TABLE IF NOT EXISTS special_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  status VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending | attached | dismissed | closed',
  head VARCHAR(8) NOT NULL DEFAULT 'yui'        COMMENT 'yui | otto',
  source VARCHAR(12) NOT NULL DEFAULT 'manual'  COMMENT 'email | sms | manual',
  source_message_key VARCHAR(255) NULL          COMMENT 'sales_messages.message_key — one request per message',
  contact_id INT NULL,
  company_id INT NULL,
  summary VARCHAR(255) NOT NULL DEFAULT '',
  client_words TEXT NULL                        COMMENT 'The client''s own words, quoted to the crew',
  included_items TEXT NULL                      COMMENT 'One per line — part of the scheduled work',
  extra_items TEXT NULL                         COMMENT 'One per line — extra, decide on site',
  proposal_json TEXT NULL,
  received_at DATETIME NULL,
  created_by INT NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sr_message (source_message_key),
  INDEX idx_sr_status (status, created_at),
  INDEX idx_sr_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Client special requests about scheduled work (crew see them on arrival)';
