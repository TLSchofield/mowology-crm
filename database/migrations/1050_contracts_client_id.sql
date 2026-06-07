-- Migration 1050 — add nullable client_id to contracts
--
-- Client/Account model: Phase 1 added client_id to properties/job_plans/quotes/
-- invoices but missed `contracts`. Contracts link only via property_id +
-- contact_id, so they can't reference the account spine. This adds the FK
-- alongside the existing columns (non-breaking). Backfilled separately.
--
-- MySQL rules: plain ADD COLUMN (runs once), nullable + ON DELETE SET NULL.

ALTER TABLE contracts
  ADD COLUMN client_id INT NULL DEFAULT NULL
    COMMENT 'FK clients.id — bill-to account for this contract'
    AFTER contact_id;
ALTER TABLE contracts
  ADD CONSTRAINT fk_contracts_client
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
CREATE INDEX idx_contracts_client ON contracts(client_id);
