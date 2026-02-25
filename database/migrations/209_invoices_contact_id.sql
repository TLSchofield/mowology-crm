-- Migration 209: Support contact-based invoices (no company)
-- Makes company_id nullable and adds contact_id for direct-to-contact invoicing
-- This handles plans linked to contacts rather than companies

ALTER TABLE invoices
    MODIFY COLUMN company_id INT NULL DEFAULT NULL,
    ADD COLUMN contact_id INT NULL DEFAULT NULL AFTER company_id;
