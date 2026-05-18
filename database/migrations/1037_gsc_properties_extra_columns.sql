/**
 * Migration: Add missing columns to gsc_properties
 * Purpose: connect.php OAuth callback inserts api_site_url, property_type, is_active
 *          but these columns were never added to the original table (migration 030).
 *          Without them every reconnect attempt fails silently with a SQL error.
 */

ALTER TABLE gsc_properties
    ADD COLUMN api_site_url  VARCHAR(255)    NULL         AFTER site_url,
    ADD COLUMN property_type VARCHAR(50)     NULL         AFTER api_site_url,
    ADD COLUMN is_active     TINYINT(1)  NOT NULL DEFAULT 1 AFTER property_type;

INSERT INTO migrations_log (migration_filename, executed_at, status)
VALUES ('1037_gsc_properties_extra_columns.sql', NOW(), 'success');
