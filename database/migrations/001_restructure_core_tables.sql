-- =====================================================
-- MOWOLOGY CRM - Core Tables Restructure
-- Migration: 001_restructure_core_tables.sql
--
-- This restructures the database for proper relationships:
-- - contacts: Independent (people)
-- - companies: Independent (businesses), links to contacts
-- - properties: Independent (locations), links to contacts
-- - quote_requests: JobFlow submissions, links to contacts & properties
-- =====================================================

-- IMPORTANT: Run this command FIRST in phpMyAdmin before running this script:
-- SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- 1. CONTACTS TABLE (Independent - People)
-- =====================================================
DROP TABLE IF EXISTS contacts;
CREATE TABLE contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Basic info
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100),
    email VARCHAR(255),
    phone VARCHAR(50),

    -- Communication preferences
    preferred_contact_method ENUM('phone', 'email', 'text') DEFAULT 'phone',
    receive_marketing TINYINT(1) DEFAULT 0,
    receive_sms TINYINT(1) DEFAULT 0,

    -- Consent tracking
    consent_quote_followup TINYINT(1) DEFAULT 0,
    consent_marketing_email TINYINT(1) DEFAULT 0,
    consent_sms TINYINT(1) DEFAULT 0,
    consent_timestamp TIMESTAMP NULL,
    consent_ip_address VARCHAR(45),
    consent_source VARCHAR(50),

    -- Status
    is_active TINYINT(1) DEFAULT 1,
    notes TEXT,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_email (email),
    INDEX idx_phone (phone),
    INDEX idx_name (last_name, first_name),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================
-- 2. COMPANIES TABLE (Independent - Businesses)
-- Links to contacts for primary/billing contact
-- =====================================================
DROP TABLE IF EXISTS companies;
CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Basic info
    company_name VARCHAR(200) NOT NULL,
    company_type ENUM('individual', 'business', 'strata', 'property_manager') DEFAULT 'individual',

    -- Linked contacts
    primary_contact_id INT NULL COMMENT 'Main contact person',
    billing_contact_id INT NULL COMMENT 'Person for invoices',

    -- Billing address (may differ from property)
    billing_address VARCHAR(255),
    billing_city VARCHAR(100) DEFAULT 'Vancouver',
    billing_province VARCHAR(50) DEFAULT 'BC',
    billing_postal_code VARCHAR(10),
    billing_email VARCHAR(255),
    billing_phone VARCHAR(50),

    -- Account status
    account_status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    payment_terms VARCHAR(50) DEFAULT 'Net 30',
    notes TEXT,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign keys
    FOREIGN KEY (primary_contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    FOREIGN KEY (billing_contact_id) REFERENCES contacts(id) ON DELETE SET NULL,

    -- Indexes
    INDEX idx_company_name (company_name),
    INDEX idx_status (account_status),
    INDEX idx_primary_contact (primary_contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================
-- 3. PROPERTIES TABLE (Independent - Locations)
-- Links to contacts for site contact
-- =====================================================
DROP TABLE IF EXISTS properties;
CREATE TABLE properties (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Basic info
    property_name VARCHAR(200) COMMENT 'Friendly name like "Smith Residence"',
    property_type ENUM('single_family', 'townhouse', 'condo', 'commercial', 'strata', 'multi_unit') DEFAULT 'single_family',

    -- Address
    address VARCHAR(255) NOT NULL,
    city VARCHAR(100) DEFAULT 'Vancouver',
    province VARCHAR(50) DEFAULT 'BC',
    postal_code VARCHAR(10),

    -- Geolocation
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    geocode VARCHAR(500) COMMENT 'Full formatted address from Google geocoding',
    geocoded_at TIMESTAMP NULL,

    -- Site contact (person at the property)
    site_contact_id INT NULL COMMENT 'Contact person at property',

    -- Property details
    lot_size_sqft DECIMAL(10, 2),
    lawn_size_sqft DECIMAL(10, 2),
    notes TEXT,

    -- Status
    status ENUM('active', 'inactive', 'archived') DEFAULT 'active',

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign keys
    FOREIGN KEY (site_contact_id) REFERENCES contacts(id) ON DELETE SET NULL,

    -- Indexes
    INDEX idx_address (address),
    INDEX idx_city (city),
    INDEX idx_status (status),
    INDEX idx_location (latitude, longitude),
    INDEX idx_site_contact (site_contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================
-- 4. COMPANY_PROPERTIES (Many-to-Many Link)
-- Links companies to their properties
-- =====================================================
DROP TABLE IF EXISTS company_properties;
CREATE TABLE company_properties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    property_id INT NOT NULL,

    relationship_type ENUM('owner', 'manager', 'tenant', 'billing') DEFAULT 'owner',
    is_primary TINYINT(1) DEFAULT 0 COMMENT 'Primary property for this company',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,

    UNIQUE KEY unique_company_property (company_id, property_id),
    INDEX idx_company (company_id),
    INDEX idx_property (property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================
-- 5. QUOTE_REQUESTS TABLE (JobFlow Submissions)
-- Links to contacts and properties
-- =====================================================
DROP TABLE IF EXISTS quote_requests;
CREATE TABLE quote_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Linked records (created or matched during submission)
    contact_id INT NULL COMMENT 'Link to contact record',
    property_id INT NULL COMMENT 'Link to property record',
    company_id INT NULL COMMENT 'Link to company if created',

    -- Service details (from form)
    service_types VARCHAR(255) COMMENT 'Comma-separated: maintenance,cleanup,snow_removal',
    urgency ENUM('inquiring', 'soon', 'asap') DEFAULT 'inquiring',
    project_description TEXT,

    -- Status tracking
    status ENUM('new', 'reviewing', 'quoted', 'converted', 'declined', 'spam') DEFAULT 'new',

    -- If converted to quote
    quote_id INT NULL COMMENT 'Link to created quote',

    -- Form metadata
    source VARCHAR(50) DEFAULT 'website',
    ip_address VARCHAR(45),
    user_agent TEXT,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    converted_at TIMESTAMP NULL,

    -- Foreign keys
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,

    -- Indexes
    INDEX idx_status (status),
    INDEX idx_contact (contact_id),
    INDEX idx_property (property_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================
-- 6. CONSENT_LOG TABLE (Audit Trail)
-- =====================================================
DROP TABLE IF EXISTS consent_log;
CREATE TABLE consent_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contact_id INT NOT NULL,

    consent_type ENUM('quote_followup', 'marketing_email', 'sms') NOT NULL,
    consent_given TINYINT(1) NOT NULL,
    consent_text TEXT COMMENT 'The exact text they agreed to',

    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    consent_source VARCHAR(50) COMMENT 'website_form, phone, in_person',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    INDEX idx_contact (contact_id),
    INDEX idx_type (consent_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================
-- 7. ACTIVITY_LOG TABLE (Updated)
-- =====================================================
DROP TABLE IF EXISTS activity_log;
CREATE TABLE activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NULL COMMENT 'CRM user who performed action',
    contact_id INT NULL,
    company_id INT NULL,
    property_id INT NULL,
    quote_request_id INT NULL,

    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_user (user_id),
    INDEX idx_contact (contact_id),
    INDEX idx_company (company_id),
    INDEX idx_property (property_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;
