-- 1345 — property_measurements.measurement_type ENUM → VARCHAR(50) (Fable audit #27, 2026-10-12).
-- Adding a measurement group ran ALTER TABLE … MODIFY … ENUM(…) from a web request
-- (public/crm/api/measurement-groups.php ensureMeasurementTypeExists). Once the app runs as a
-- data-only MySQL user that ALTER is refused, so the column becomes a plain string: every
-- existing value is kept (all ≤ 11 characters), and ensureMeasurementTypeExists() already
-- no-ops for VARCHAR. Run it as the schema-owning user (the migrations manager does).
-- MySQL 5.7 compatible.
ALTER TABLE property_measurements
    MODIFY COLUMN measurement_type VARCHAR(50) NOT NULL DEFAULT 'other';
