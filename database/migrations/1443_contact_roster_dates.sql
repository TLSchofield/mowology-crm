-- Migration 1443 — who is the contact NOW: start and end dates on the people-with-roles tables
--
-- Why (Tim, 2026-11-07): a strata rep sold her unit and handed the account to two neighbours by email
-- three weeks before one of them paid; Mia still drafted the thank-you to her. Nothing in the CRM could
-- say "was the contact, left on <date>". Plan: docs/plans/2026-11-07-contact-left-the-building.md.
--
-- A person who leaves is never deleted: old invoices, quotes and history stay true. ended_on in the past
-- = former. ended_reason: left | bounced | replaced | owner_said | merged.
--
-- Columns only; nothing reads them until ContactRoster::current() switches a sender over (step 3).
-- Filling the roster itself is ContactRosterSync (run once, then nightly), not this file.
-- Checked against production 2026-11-07: neither table has these columns.
-- MySQL 5.7 rules: plain ADD COLUMN (runs once), CREATE INDEX without IF NOT EXISTS, no DDL in a txn.

ALTER TABLE client_contacts
  ADD COLUMN started_on DATE NULL DEFAULT NULL COMMENT 'Took this role on (NULL = before records)' AFTER receives_notifications,
  ADD COLUMN ended_on DATE NULL DEFAULT NULL COMMENT 'Left this role; NULL = current' AFTER started_on,
  ADD COLUMN ended_reason VARCHAR(40) NULL DEFAULT NULL COMMENT 'left | bounced | replaced | owner_said | merged' AFTER ended_on;
CREATE INDEX idx_cc_client_current ON client_contacts (client_id, ended_on);

ALTER TABLE property_contacts
  ADD COLUMN started_on DATE NULL DEFAULT NULL COMMENT 'Took this role on (NULL = before records)' AFTER is_primary,
  ADD COLUMN ended_on DATE NULL DEFAULT NULL COMMENT 'Left this role; NULL = current' AFTER started_on,
  ADD COLUMN ended_reason VARCHAR(40) NULL DEFAULT NULL COMMENT 'left | bounced | replaced | owner_said | merged' AFTER ended_on;
CREATE INDEX idx_pc_property_current ON property_contacts (property_id, ended_on);
