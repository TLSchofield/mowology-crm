-- Migration 1007 — Add company_id FK to contacts and properties
--
-- Purpose: Give contacts and properties a first-class link to the paying company.
-- Currently there's no way to derive "which company pays this invoice" from a
-- contact or property, so ad-hoc invoices end up with company_id=NULL and the
-- PDF Bill To section can't resolve a billing address.
--
-- After this migration:
--   - contacts.company_id      → the company this contact represents / belongs to
--   - properties.company_id    → the company that pays for work at this property
-- Both are nullable (existing rows stay valid) and use ON DELETE SET NULL so
-- deleting a company never cascades away contacts/properties.

-- contacts.company_id
ALTER TABLE `contacts`
    ADD COLUMN `company_id` INT NULL DEFAULT NULL
        COMMENT 'FK to companies.id — the company this contact belongs to (for invoicing/billing lookups)'
        AFTER `last_name`;

ALTER TABLE `contacts`
    ADD CONSTRAINT `fk_contacts_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL;

CREATE INDEX `idx_contacts_company` ON `contacts`(`company_id`);

-- properties.company_id
ALTER TABLE `properties`
    ADD COLUMN `company_id` INT NULL DEFAULT NULL
        COMMENT 'FK to companies.id — the company that pays invoices for work at this property'
        AFTER `site_contact_id`;

ALTER TABLE `properties`
    ADD CONSTRAINT `fk_properties_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL;

CREATE INDEX `idx_properties_company` ON `properties`(`company_id`);
