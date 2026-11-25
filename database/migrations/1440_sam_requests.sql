-- Migration 1440: Sam's "Looks like a request" (2026-11-05)
-- Purpose: a known client's email asking for work becomes one proposal on Sam's card: the service, the property, a
-- price precedent (this property's last same-service job, else similar recent quotes) and a level (sure / likely /
-- not sure) with the reasons. Nothing is sent: "Make draft quote" creates a draft the owner edits and sends. When that
-- draft is sent, what changed is kept in outcome_json and as HeadLessons rows (head 'sam'), field by field.
-- Written by SamRequestService only. Plan: docs/plans/2026-11-05-sam-request-to-draft-quote-plan.md.
-- MySQL 5.7, utf8mb4_general_ci. Idempotent.
CREATE TABLE IF NOT EXISTS sam_requests (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  message_key     VARCHAR(191) NOT NULL COMMENT 'sales_messages.message_key of the client email',
  contact_id      INT          NULL,
  property_id     INT          NULL     COMMENT 'NULL when none or several were found (the card asks which)',
  service_key     VARCHAR(30)  NULL     COMMENT 'CloserPricing / SamRequestRules key, e.g. cleanup',
  level           VARCHAR(10)  NOT NULL DEFAULT 'not_sure' COMMENT 'sure | likely | not_sure',
  evidence_json   TEXT         NULL     COMMENT 'reasons[], services[], negation, property_ids[], how the property was found',
  precedent_json  TEXT         NULL     COMMENT 'source (property_jobber | property_quote | similar | none), date, lines[{name, qty, amount}], price, count',
  draft_quote_id  INT          NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'open' COMMENT 'open | drafted | dismissed | sent',
  dismiss_reason  VARCHAR(30)  NULL     COMMENT 'not_request | wrong_service',
  outcome_json    TEXT         NULL     COMMENT 'per field: accepted | changed (said → sent), when the draft was sent',
  decided_by      INT          NULL,
  decided_at      DATETIME     NULL,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NOT NULL,
  UNIQUE KEY uq_sr_message (message_key),
  KEY idx_sr_status (status),
  KEY idx_sr_quote (draft_quote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
