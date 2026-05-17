-- Migration 995: Per-property billing entity
-- Adds billing_company_id to properties so each property can explicitly declare
-- which company (if any) should be invoiced. NULL = bill to site contact personally.

ALTER TABLE properties
  ADD COLUMN billing_company_id INT NULL DEFAULT NULL,
  ADD CONSTRAINT fk_prop_billing_company
    FOREIGN KEY (billing_company_id) REFERENCES companies(id)
    ON DELETE SET NULL;

-- Data fix: 70 West 18th Avenue (property id=45) belongs to Folick Holdings (company id=6).
-- Correct the property, any plans for it, and invoice 45 which was created before this fix.
UPDATE properties SET billing_company_id = 6 WHERE id = 45;
UPDATE job_plans  SET company_id = 6 WHERE property_id = 45 AND (company_id IS NULL OR company_id = 0);
UPDATE invoices   SET company_id = 6 WHERE id = 45;
