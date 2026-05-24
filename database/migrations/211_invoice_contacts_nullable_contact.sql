-- Migration 211: Make invoice_contacts.contact_id nullable
-- Allows ad-hoc recipients (email typed directly, no linked contact record)
-- when editing invoices. Previously NOT NULL blocked the INSERT.
--
-- Idempotent runner: /crm/api/run-migration-211.php

ALTER TABLE `invoice_contacts`
    DROP FOREIGN KEY `invoice_contacts_ibfk_2`;

ALTER TABLE `invoice_contacts`
    MODIFY COLUMN `contact_id` INT NULL DEFAULT NULL;

ALTER TABLE `invoice_contacts`
    ADD CONSTRAINT `fk_invoice_contacts_contact`
    FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE SET NULL;
