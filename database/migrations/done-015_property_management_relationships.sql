-- =====================================================
-- MOWOLOGY CRM - Property Management & Invoice Relationships
-- Migration: 015_property_management_relationships.sql
--
-- Adds support for:
-- 1. Strata corporations (buildings with multiple units)
-- 2. Property management companies (manage properties on behalf of owners)
-- 3. Multiple invoice contact routing (email invoices to multiple people per event)
-- 4. Billing address separation (invoice address ≠ service address)
--
-- Run this ONCE in phpMyAdmin or MySQL CLI.
-- =====================================================

DELIMITER //

-- =====================================================
-- 1. Add property_manager_id to properties table
-- Links a property to the company managing it on owner's behalf
-- =====================================================
DROP PROCEDURE IF EXISTS add_property_manager_id//
CREATE PROCEDURE add_property_manager_id()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'properties'
        AND COLUMN_NAME = 'property_manager_id'
    ) THEN
        ALTER TABLE `properties` ADD COLUMN `property_manager_id` INT NULL AFTER `site_contact_id`;
        ALTER TABLE `properties` ADD FOREIGN KEY (property_manager_id) REFERENCES companies(id) ON DELETE SET NULL;
        ALTER TABLE `properties` ADD INDEX idx_property_manager (property_manager_id);
    END IF;
END//
CALL add_property_manager_id()//
DROP PROCEDURE add_property_manager_id//

-- =====================================================
-- 2. Add owner_company_id to properties table
-- Links property to the company that owns it (for strata/commercial)
-- =====================================================
DROP PROCEDURE IF EXISTS add_owner_company_id//
CREATE PROCEDURE add_owner_company_id()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'properties'
        AND COLUMN_NAME = 'owner_company_id'
    ) THEN
        ALTER TABLE `properties` ADD COLUMN `owner_company_id` INT NULL AFTER `property_manager_id`;
        ALTER TABLE `properties` ADD FOREIGN KEY (owner_company_id) REFERENCES companies(id) ON DELETE SET NULL;
        ALTER TABLE `properties` ADD INDEX idx_owner_company (owner_company_id);
    END IF;
END//
CALL add_owner_company_id()//
DROP PROCEDURE add_owner_company_id//

-- =====================================================
-- 3. Enhance company_type ENUM to include 'strata'
-- =====================================================
DROP PROCEDURE IF EXISTS update_company_type_enum//
CREATE PROCEDURE update_company_type_enum()
BEGIN
    -- Check if strata is already in enum
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'companies'
        AND COLUMN_NAME = 'company_type'
        AND COLUMN_TYPE LIKE '%strata%'
    ) THEN
        -- Modify the enum to add strata if not present
        ALTER TABLE `companies`
        MODIFY COLUMN `company_type`
        ENUM('individual', 'business', 'strata', 'property_manager')
        DEFAULT 'individual';
    END IF;
END//
CALL update_company_type_enum()//
DROP PROCEDURE update_company_type_enum//

-- =====================================================
-- 4. Create invoice_contacts table (Multiple contacts per invoice)
-- Handles emailing invoices to multiple people (owner, manager, accounting)
-- =====================================================
DROP TABLE IF EXISTS invoice_contacts//
CREATE TABLE invoice_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,

    invoice_id INT NOT NULL,
    contact_id INT NOT NULL,

    -- Role of this contact for this invoice
    contact_role ENUM(
        'primary_recipient',      -- Main invoice recipient
        'billing_contact',        -- Alternate billing contact
        'accounting',             -- Accounting/finance team
        'property_manager',       -- Property manager contact
        'strata_manager',         -- Strata manager
        'owner_contact',          -- Property owner (for managed properties)
        'cc',                     -- CC recipient
        'bcc'                     -- BCC recipient
    ) NOT NULL,

    email_address VARCHAR(255) NOT NULL COMMENT 'Email to send to (may differ from contact.email)',

    -- Tracking
    invoice_sent_at TIMESTAMP NULL COMMENT 'When invoice was sent to this contact',
    invoice_opened_at TIMESTAMP NULL COMMENT 'When recipient opened email (if tracking enabled)',
    bounced TINYINT(1) DEFAULT 0 COMMENT 'Email bounced',
    bounce_reason VARCHAR(500),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign keys
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_invoice (invoice_id),
    INDEX idx_contact (contact_id),
    INDEX idx_role (contact_role),
    INDEX idx_sent_at (invoice_sent_at),
    UNIQUE KEY unique_invoice_contact_role (invoice_id, contact_id, contact_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Maps contacts to invoices with multiple recipients and roles'//

-- =====================================================
-- 5. Add invoice fields for service vs billing address
-- =====================================================
DROP PROCEDURE IF EXISTS add_invoice_address_fields//
CREATE PROCEDURE add_invoice_address_fields()
BEGIN
    -- Service address fields (where work was done)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'invoices'
        AND COLUMN_NAME = 'service_address'
    ) THEN
        ALTER TABLE `invoices` ADD COLUMN `service_address` VARCHAR(255) AFTER `notes`;
        ALTER TABLE `invoices` ADD COLUMN `service_city` VARCHAR(100) AFTER `service_address`;
        ALTER TABLE `invoices` ADD COLUMN `service_province` VARCHAR(50) AFTER `service_city`;
        ALTER TABLE `invoices` ADD COLUMN `service_postal_code` VARCHAR(10) AFTER `service_province`;
    END IF;

    -- Billing address fields (where invoice is sent)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'invoices'
        AND COLUMN_NAME = 'billing_address'
    ) THEN
        ALTER TABLE `invoices` ADD COLUMN `billing_address` VARCHAR(255) AFTER `service_postal_code`;
        ALTER TABLE `invoices` ADD COLUMN `billing_city` VARCHAR(100) AFTER `billing_address`;
        ALTER TABLE `invoices` ADD COLUMN `billing_province` VARCHAR(50) AFTER `billing_city`;
        ALTER TABLE `invoices` ADD COLUMN `billing_postal_code` VARCHAR(10) AFTER `billing_province`;
    END IF;

    -- Mark which address is different
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'invoices'
        AND COLUMN_NAME = 'address_differs'
    ) THEN
        ALTER TABLE `invoices` ADD COLUMN `address_differs` TINYINT(1) DEFAULT 0;
    END IF;
END//
CALL add_invoice_address_fields()//
DROP PROCEDURE add_invoice_address_fields//

-- =====================================================
-- 6. Create property_contacts table (Multiple contacts per property)
-- Handles different roles at a property (tenant, owner, manager contact)
-- =====================================================
DROP TABLE IF EXISTS property_contacts//
CREATE TABLE property_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,

    property_id INT NOT NULL,
    contact_id INT NOT NULL,

    -- Role of this contact at this property
    contact_role ENUM(
        'owner',              -- Property owner
        'tenant',             -- Tenant/occupant
        'site_supervisor',    -- On-site supervisor
        'manager',            -- Property manager contact
        'billing',            -- Billing contact for this property
        'emergency',          -- Emergency contact
        'other'
    ) NOT NULL,

    is_primary TINYINT(1) DEFAULT 0 COMMENT 'Primary contact for this role',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign keys
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_property (property_id),
    INDEX idx_contact (contact_id),
    INDEX idx_role (contact_role),
    UNIQUE KEY unique_property_contact_role (property_id, contact_id, contact_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Maps contacts to properties with specific roles'//

-- =====================================================
-- 7. Add company relationship type to quote_requests
-- =====================================================
DROP PROCEDURE IF EXISTS add_quote_request_company_relationship//
CREATE PROCEDURE add_quote_request_company_relationship()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'quote_requests'
        AND COLUMN_NAME = 'company_relationship'
    ) THEN
        ALTER TABLE `quote_requests`
        ADD COLUMN `company_relationship` ENUM(
            'direct',
            'via_property_manager',
            'via_strata'
        ) DEFAULT 'direct'
        COMMENT 'How this quote request relates to companies';
    END IF;
END//
CALL add_quote_request_company_relationship()//
DROP PROCEDURE add_quote_request_company_relationship//

-- =====================================================
-- 8. Add quote invoice routing preferences
-- =====================================================
DROP PROCEDURE IF EXISTS add_company_invoice_routing//
CREATE PROCEDURE add_company_invoice_routing()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'companies'
        AND COLUMN_NAME = 'invoice_routing_method'
    ) THEN
        ALTER TABLE `companies`
        ADD COLUMN `invoice_routing_method` ENUM(
            'primary_contact',      -- Send to primary contact only
            'billing_contact',      -- Send to billing contact only
            'both_contacts',        -- Send to both primary and billing
            'custom_contacts',      -- Use custom invoice_contacts table
            'email_address'         -- Use custom email from billing_email field
        ) DEFAULT 'primary_contact'
        COMMENT 'How to route invoices for this company';
    END IF;
END//
CALL add_company_invoice_routing()//
DROP PROCEDURE add_company_invoice_routing//

-- =====================================================
-- 9. Add payment method tracking to companies
-- =====================================================
DROP PROCEDURE IF EXISTS add_company_payment_fields//
CREATE PROCEDURE add_company_payment_fields()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'companies'
        AND COLUMN_NAME = 'payment_method'
    ) THEN
        ALTER TABLE `companies`
        ADD COLUMN `payment_method` ENUM(
            'invoice',
            'credit_card',
            'bank_transfer',
            'cheque'
        ) DEFAULT 'invoice'
        COMMENT 'Preferred payment method';
    END IF;
END//
CALL add_company_payment_fields()//
DROP PROCEDURE add_company_payment_fields//

DELIMITER ;

-- =====================================================
-- Summary of changes:
-- =====================================================
-- 1. properties.property_manager_id - Links to managing company
-- 2. properties.owner_company_id - Links to owning company
-- 3. invoice_contacts - Maps multiple contacts to invoices
-- 4. invoices - Added service_address and billing_address fields
-- 5. property_contacts - Maps multiple contacts to properties with roles
-- 6. quote_requests.company_relationship - Tracks how quote relates to companies
-- 7. companies.invoice_routing_method - Configures invoice recipient routing
-- 8. companies.payment_method - Tracks preferred payment method
--
-- Usage:
-- - Strata: property_type='strata', owner_company_id=strata_corp_id, property_manager_id=manager_id
-- - Residential with manager: property_type='single_family', owner_company_id=owner_id, property_manager_id=manager_id
-- - Direct business: property_type='commercial', owner_company_id=business_id, property_manager_id=NULL
-- =====================================================
