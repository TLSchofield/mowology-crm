-- Migration 1042 — Purchase Tasks: vendor_id + vendor_location_id FKs
-- ─────────────────────────────────────────────────────────────────────
-- Links purchase_tasks to the vendors/vendor_locations catalog so the
-- modal can show a dropdown of known vendors + their store locations.
-- The free-text columns (vendor_name, location_label, location_address)
-- are KEPT and still populated on save for backward-compatible display.
--
-- Run via: /crm/api/run-migration-1042.php

ALTER TABLE purchase_tasks
    ADD COLUMN vendor_id          INT NULL DEFAULT NULL AFTER location_label,
    ADD COLUMN vendor_location_id INT NULL DEFAULT NULL AFTER vendor_id;

ALTER TABLE purchase_tasks
    ADD INDEX idx_pt_vendor     (vendor_id),
    ADD INDEX idx_pt_vendor_loc (vendor_location_id);

ALTER TABLE purchase_tasks
    ADD CONSTRAINT fk_pt_vendor
        FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_pt_vendor_location
        FOREIGN KEY (vendor_location_id) REFERENCES vendor_locations(id) ON DELETE SET NULL;
