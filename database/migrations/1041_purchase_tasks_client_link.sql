-- Migration 1041 — Purchase Tasks: client + property link
-- ─────────────────────────────────────────────────────────
-- Adds contact_id and property_id to purchase_tasks so a vendor
-- run can be associated with the client/property the supplies
-- are destined for. Both nullable — existing tasks are unaffected.
--
-- Run via: /crm/api/run-migration-1041.php

ALTER TABLE purchase_tasks
    ADD COLUMN contact_id  INT NULL DEFAULT NULL AFTER notes,
    ADD COLUMN property_id INT NULL DEFAULT NULL AFTER contact_id;

ALTER TABLE purchase_tasks
    ADD INDEX idx_pt_contact  (contact_id),
    ADD INDEX idx_pt_property (property_id);

ALTER TABLE purchase_tasks
    ADD CONSTRAINT fk_pt_contact  FOREIGN KEY (contact_id)  REFERENCES contacts(id)   ON DELETE SET NULL,
    ADD CONSTRAINT fk_pt_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL;
