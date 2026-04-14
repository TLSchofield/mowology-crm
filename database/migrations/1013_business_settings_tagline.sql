-- Migration 1013 — Add company_tagline to business_settings
-- Used as the quirky/fun slogan shown under the brand name on invoice PDFs
-- Safe to re-run (IF NOT EXISTS pattern is enforced at the runner level)

ALTER TABLE business_settings
    ADD COLUMN company_tagline VARCHAR(255) NULL AFTER company_address;
