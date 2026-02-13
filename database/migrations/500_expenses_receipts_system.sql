-- ============================================================================
-- Migration 500: Expenses, Vendors & Receipt Forwarding System
-- ============================================================================
-- Purpose: Create the expenses tracking system with vendor directory,
--          smart receipt categorization support, and email forwarding log.
--
-- Tables created:
--   1. vendors               — vendor directory with aliases for OCR matching
--   2. vendor_locations      — GPS-tagged vendor locations for proximity matching
--   3. expenses              — core expense records linked to jobs/properties/media
--   4. receipt_email_log     — audit log for receipt forwarding to accounting
--
-- Also adds RBAC permissions: expenses.view, expenses.edit, expenses.send
--
-- MySQL 5.7 compatible — no JSON functions, no window functions, no generated columns
-- All tables use utf8mb4_general_ci to match existing FK references
-- Run each statement outside of a transaction (DDL auto-commits in MySQL)
-- ============================================================================


-- ============================================================================
-- 1. vendors — Vendor directory with alias matching for OCR
-- ============================================================================

CREATE TABLE IF NOT EXISTS `vendors` (
    `id` int NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `aliases` text NULL COMMENT 'Comma-separated alternate names for OCR matching (e.g., HD, Home Depot, HomeDepot)',
    `default_accounting_category` varchar(50) NULL COMMENT 'Default accounting category for this vendor',
    `default_gbp_category` varchar(100) NULL COMMENT 'Default Google Business Profile category',
    `phone` varchar(20) NULL,
    `website` varchar(255) NULL,
    `notes` text NULL,
    `is_active` tinyint(1) DEFAULT 1,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_vendor_active` (`is_active`),
    KEY `idx_vendor_category` (`default_accounting_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Vendor directory for expense tracking and OCR matching';


-- ============================================================================
-- 2. vendor_locations — GPS-tagged locations for proximity matching
-- ============================================================================

CREATE TABLE IF NOT EXISTS `vendor_locations` (
    `id` int NOT NULL AUTO_INCREMENT,
    `vendor_id` int NOT NULL,
    `label` varchar(255) NULL COMMENT 'e.g., Home Depot Cambie, Shell Marine Drive',
    `address` varchar(500) NULL,
    `lat` decimal(10,7) NULL,
    `lng` decimal(10,7) NULL,
    `city` varchar(100) NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_vl_vendor` (`vendor_id`),
    KEY `idx_vl_geo` (`lat`, `lng`),
    CONSTRAINT `fk_vl_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Vendor store locations for GPS-based receipt matching';


-- ============================================================================
-- 3. expenses — Core expense records
-- ============================================================================

CREATE TABLE IF NOT EXISTS `expenses` (
    `id` int NOT NULL AUTO_INCREMENT,
    `expense_date` date NOT NULL,
    `vendor_id` int NULL COMMENT 'FK to vendors table (matched or manually selected)',
    `vendor_name_raw` varchar(255) NULL COMMENT 'OCR-extracted or manually entered vendor name',
    `description` text NULL,
    `amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Pre-tax amount',
    `tax_amount` decimal(10,2) DEFAULT 0.00,
    `total` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Total including tax',
    `accounting_category` varchar(50) NULL COMMENT 'Materials, Fuel, Tools/Equipment, etc.',
    `gbp_category` varchar(100) NULL COMMENT 'Vendor/business type for GBP analytics',
    `payment_method` varchar(50) NULL COMMENT 'cash, credit_card, debit, etransfer, company_card',
    `receipt_media_id` int NULL COMMENT 'FK to media_assets for receipt image',
    `receipt_lat` decimal(10,7) NULL COMMENT 'GPS latitude at time of receipt upload',
    `receipt_lng` decimal(10,7) NULL COMMENT 'GPS longitude at time of receipt upload',
    `match_confidence` int DEFAULT 0 COMMENT 'Vendor/category match confidence 0-100',
    `raw_ocr_json` longtext NULL COMMENT 'Raw OCR extraction results for debugging',
    `job_id` int NULL COMMENT 'Linked job plan (if expense is job-related)',
    `property_id` int NULL COMMENT 'Linked property',
    `contact_id` int NULL COMMENT 'Linked client contact',
    `forwarded_to_accounting` tinyint(1) DEFAULT 0 COMMENT 'Whether receipt was emailed to QuickBooks',
    `forwarded_at` datetime NULL COMMENT 'When receipt was forwarded',
    `notes` text NULL,
    `status` varchar(20) DEFAULT 'draft' COMMENT 'draft, approved, forwarded',
    `created_by` int NOT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_exp_date` (`expense_date`),
    KEY `idx_exp_vendor` (`vendor_id`),
    KEY `idx_exp_category` (`accounting_category`),
    KEY `idx_exp_job` (`job_id`),
    KEY `idx_exp_property` (`property_id`),
    KEY `idx_exp_status` (`status`),
    KEY `idx_exp_forwarded` (`forwarded_to_accounting`),
    KEY `idx_exp_created_by` (`created_by`),
    KEY `idx_exp_media` (`receipt_media_id`),
    CONSTRAINT `fk_exp_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_exp_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Expense records with receipt tracking and accounting categorization';


-- ============================================================================
-- 4. receipt_email_log — Audit trail for receipt forwarding
-- ============================================================================

CREATE TABLE IF NOT EXISTS `receipt_email_log` (
    `id` int NOT NULL AUTO_INCREMENT,
    `expense_id` int NULL COMMENT 'FK to expenses (null if sent without expense record)',
    `media_id` int NULL COMMENT 'FK to media_assets for the receipt file',
    `to_email` varchar(255) NOT NULL COMMENT 'Destination email (QuickBooks inbox)',
    `subject` varchar(500) NULL,
    `status` enum('queued','sent','failed') DEFAULT 'queued',
    `error_message` text NULL,
    `provider_message_id` varchar(255) NULL COMMENT 'Email provider message ID for tracking',
    `sent_at` datetime NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` int NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_rel_expense` (`expense_id`),
    KEY `idx_rel_media` (`media_id`),
    KEY `idx_rel_status` (`status`),
    KEY `idx_rel_created` (`created_at`),
    CONSTRAINT `fk_rel_expense` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rel_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Audit log for receipt email forwarding to accounting inbox';


-- ============================================================================
-- 5. RBAC Permissions — expenses.view, expenses.edit, expenses.send
-- ============================================================================

INSERT IGNORE INTO `permissions` (`key`, `description`) VALUES
    ('expenses.view', 'View expense records and receipts'),
    ('expenses.edit', 'Create, edit, and delete expenses'),
    ('expenses.send', 'Send receipts to accounting email (QuickBooks)');

-- Admin gets all expense permissions
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
    WHERE r.name = 'admin' AND p.`key` IN ('expenses.view', 'expenses.edit', 'expenses.send');

-- Manager gets all expense permissions
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT r.id, p.id FROM roles r JOIN permissions p
    ON p.`key` IN ('expenses.view', 'expenses.edit', 'expenses.send')
    WHERE r.name = 'manager';

-- Staff gets view only
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT r.id, p.id FROM roles r JOIN permissions p
    ON p.`key` = 'expenses.view'
    WHERE r.name = 'staff';


-- ============================================================================
-- 6. Seed common vendors (landscaping industry)
-- ============================================================================

INSERT IGNORE INTO `vendors` (`name`, `aliases`, `default_accounting_category`, `default_gbp_category`) VALUES
    ('Home Depot',       'HD, Home Depot, HomeDepot, THE HOME DEPOT',     'Materials',         'Hardware store'),
    ('Lowes',            'Lowes, LOWES, Lowe''s',                          'Materials',         'Hardware store'),
    ('Canadian Tire',    'Canadian Tire, CANADIAN TIRE, CTC',              'Tools/Equipment',   'Hardware store'),
    ('Shell',            'Shell, SHELL CANADA',                            'Fuel',              'Gas station'),
    ('Petro-Canada',     'Petro-Canada, Petro Canada, PETRO',              'Fuel',              'Gas station'),
    ('Esso',             'Esso, ESSO, IMPERIAL OIL',                       'Fuel',              'Gas station'),
    ('Chevron',          'Chevron, CHEVRON CANADA',                        'Fuel',              'Gas station'),
    ('Rona',             'Rona, RONA',                                     'Materials',         'Building materials'),
    ('Costco',           'Costco, COSTCO WHOLESALE',                       'Materials',         'Wholesale store'),
    ('Art Knapp',        'Art Knapp, ART KNAPP',                           'Materials',         'Garden center/nursery'),
    ('GardenWorks',      'GardenWorks, Garden Works, GARDENWORKS',         'Materials',         'Garden center/nursery'),
    ('United Rentals',   'United Rentals, UNITED RENTALS',                 'Tools/Equipment',   'Equipment rental'),
    ('Sunbelt Rentals',  'Sunbelt, Sunbelt Rentals, SUNBELT',             'Tools/Equipment',   'Equipment rental'),
    ('Vancouver Landfill','Vancouver Landfill, CITY OF VANCOUVER, TRANSFER STATION', 'Disposal/Dump', 'Waste disposal/landfill');


-- ============================================================================
-- 7. Track migration
-- ============================================================================

INSERT INTO migrations_log (migration_file, executed_at)
VALUES ('500_expenses_receipts_system.sql', NOW())
ON DUPLICATE KEY UPDATE executed_at = NOW();
