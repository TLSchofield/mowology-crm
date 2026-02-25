-- Migration 210: Add missing columns to invoices table
-- Production was missing property_id, access_token, token_expires_at, pdf_filename
-- Safe: all adds are conditional via the migration runner's SHOW COLUMNS guard

ALTER TABLE invoices
    ADD COLUMN property_id INT NULL DEFAULT NULL AFTER contact_id,
    ADD COLUMN access_token VARCHAR(64) NULL DEFAULT NULL,
    ADD COLUMN token_expires_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN pdf_filename VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN pdf_path VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN pdf_version INT UNSIGNED DEFAULT 0,
    ADD COLUMN pdf_generated_at TIMESTAMP NULL DEFAULT NULL;
