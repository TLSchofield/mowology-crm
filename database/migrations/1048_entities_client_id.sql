-- Migration 1048 — add nullable client_id to billable entities
--
-- Client/Account model, Phase 1 (expand). Adds the new bill-to FK ALONGSIDE
-- the existing company_id/contact_id columns so old code keeps working while
-- new code can read/write client_id. Backfilled by 1049.
--
-- All nullable + ON DELETE SET NULL (decision: client_id becomes a hard
-- non-null invariant only AFTER Phase 3 cutover; during expand it may be null
-- on un-backfilled rows).
--
-- See docs/architecture/client-account-model.md §3 (Phase 1).
-- MySQL rules: plain ADD COLUMN (runs once; no IF NOT EXISTS), no DDL in txn.

-- properties.client_id — who pays for work at this address
ALTER TABLE properties
  ADD COLUMN client_id INT NULL DEFAULT NULL
    COMMENT 'FK clients.id — bill-to account for work at this property'
    AFTER site_contact_id;
ALTER TABLE properties
  ADD CONSTRAINT fk_properties_client
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
CREATE INDEX idx_properties_client ON properties(client_id);

-- job_plans.client_id
ALTER TABLE job_plans
  ADD COLUMN client_id INT NULL DEFAULT NULL
    COMMENT 'FK clients.id — bill-to account for this plan'
    AFTER company_id;
ALTER TABLE job_plans
  ADD CONSTRAINT fk_job_plans_client
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
CREATE INDEX idx_job_plans_client ON job_plans(client_id);

-- quotes.client_id
ALTER TABLE quotes
  ADD COLUMN client_id INT NULL DEFAULT NULL
    COMMENT 'FK clients.id — bill-to account for this quote'
    AFTER company_id;
ALTER TABLE quotes
  ADD CONSTRAINT fk_quotes_client
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
CREATE INDEX idx_quotes_client ON quotes(client_id);

-- invoices.client_id
ALTER TABLE invoices
  ADD COLUMN client_id INT NULL DEFAULT NULL
    COMMENT 'FK clients.id — bill-to account for this invoice'
    AFTER company_id;
ALTER TABLE invoices
  ADD CONSTRAINT fk_invoices_client
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
CREATE INDEX idx_invoices_client ON invoices(client_id);
