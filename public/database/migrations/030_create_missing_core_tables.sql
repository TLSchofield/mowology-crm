-- ============================================================================
-- Migration 030: Create Missing Core Tables
-- ============================================================================
-- Purpose: Create 27 tables that exist in SCHEMA_MASTER.sql but have no migrations
-- Critical: This migration must run AFTER migration 001 (which creates contacts, companies, properties)
-- MySQL 5.7+ Compatible (uses utf8mb4_general_ci, no generated columns)
-- ============================================================================

-- ============================================================================
-- FOUNDATION TABLES (CRITICAL - Must come first)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `company_id` int DEFAULT NULL,
  `role` enum('admin','manager','team_member') DEFAULT 'team_member',
  `is_active` tinyint(1) DEFAULT '1',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` varchar(128) NOT NULL,
  `user_id` int DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `payload` longtext,
  `last_activity` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL UNIQUE,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `quotes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `quote_number` varchar(50) NOT NULL UNIQUE,
  `contact_id` int NOT NULL,
  `property_id` int DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `service_type` varchar(100) DEFAULT NULL,
  `description` text,
  `status` enum('draft','sent','accepted','rejected','expired') DEFAULT 'draft',
  `subtotal` decimal(10,2) DEFAULT 0.00,
  `amount` decimal(10,2) DEFAULT NULL,
  `tax_rate` decimal(5,4) DEFAULT 0.0500,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `total` decimal(10,2) DEFAULT 0.00,
  `valid_until` date DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `pdf_version` int unsigned DEFAULT 0,
  `pdf_generated_at` timestamp NULL DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sent_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quote_number` (`quote_number`),
  KEY `idx_contact` (`contact_id`),
  KEY `idx_property` (`property_id`),
  KEY `idx_status` (`status`),
  FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PRODUCT SYSTEM TABLES
-- ============================================================================

CREATE TABLE IF NOT EXISTS `unit_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `abbreviation` varchar(10) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `product_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_name` varchar(255) NOT NULL,
  `category_id` int DEFAULT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `description` text,
  `cost_per_unit` decimal(10,2) DEFAULT 0.00,
  `selling_price` decimal(10,2) DEFAULT 0.00,
  `unit_type_id` int DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  KEY `idx_category` (`category_id`),
  KEY `idx_unit_type` (`unit_type_id`),
  FOREIGN KEY (`unit_type_id`) REFERENCES `unit_types` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `cost_factors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `factor_name` varchar(100) NOT NULL,
  `factor_type` enum('labor','equipment','material','overhead','fuel','other') NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `description` text,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `factor_name` (`factor_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `product_cost_breakdown` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `cost_factor_id` int NOT NULL,
  `quantity_per_unit` decimal(10,2) DEFAULT '1.00',
  `unit_cost` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `estimator_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `template_name` varchar(200) NOT NULL,
  `description` text,
  `category_id` int DEFAULT NULL,
  `base_calculation_type` enum('area','linear','volume','time','fixed') NOT NULL,
  `default_unit_type_id` int DEFAULT NULL,
  `calculation_rules` text COMMENT 'JSON: rules for calculating quantity, cost',
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  FOREIGN KEY (`default_unit_type_id`) REFERENCES `unit_types` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- QUOTE LINE ITEMS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `quote_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `quote_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `description` text NOT NULL,
  `quantity` decimal(10,2) DEFAULT '1.00',
  `unit_price` decimal(10,2) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_quote` (`quote_id`),
  KEY `idx_product` (`product_id`),
  FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- INVOICE ITEMS & SERVICE ORDERS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `property_id` int DEFAULT NULL,
  `service_order_id` int DEFAULT NULL,
  `description` text NOT NULL,
  `quantity` decimal(10,2) DEFAULT '1.00',
  `unit_price` decimal(10,2) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `service_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_invoice` (`invoice_id`),
  KEY `idx_property` (`property_id`),
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `service_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `service_order_number` varchar(50) NOT NULL UNIQUE,
  `property_id` int NOT NULL,
  `service_type` varchar(100) DEFAULT NULL,
  `status` enum('pending','scheduled','in_progress','completed','cancelled') DEFAULT 'pending',
  `scheduled_date` date DEFAULT NULL,
  `completed_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_order_number` (`service_order_number`),
  KEY `idx_property` (`property_id`),
  FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- COMMUNICATIONS & FEEDBACK
-- ============================================================================

CREATE TABLE IF NOT EXISTS `communication_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `company_id` int DEFAULT NULL,
  `contact_id` int DEFAULT NULL,
  `property_id` int DEFAULT NULL,
  `type` enum('email','phone','text','meeting','note') NOT NULL,
  `direction` enum('inbound','outbound') DEFAULT 'outbound',
  `subject` varchar(255) DEFAULT NULL,
  `message` text,
  `from_email` varchar(255) DEFAULT NULL,
  `to_email` varchar(255) DEFAULT NULL,
  `cc_email` text,
  `status` enum('sent','delivered','failed','bounced') DEFAULT 'sent',
  `attachments` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_contact` (`contact_id`),
  KEY `idx_company` (`company_id`),
  FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `client_feedback` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_id` int NOT NULL,
  `client_id` int DEFAULT NULL,
  `rating` int DEFAULT NULL,
  `comment` text,
  `feedback_type` enum('general','issue','praise') DEFAULT 'general',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_job` (`job_id`),
  FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- ANALYTICS & TRACKING
-- ============================================================================

CREATE TABLE IF NOT EXISTS `lead_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) DEFAULT NULL,
  `landing_page` varchar(255) DEFAULT NULL,
  `utm_source` varchar(100) DEFAULT NULL,
  `utm_medium` varchar(100) DEFAULT NULL,
  `utm_campaign` varchar(100) DEFAULT NULL,
  `utm_content` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `conversion_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lead_event_id` int DEFAULT NULL,
  `event_type` enum('quote_request','quote_sent','quote_accepted','job_created','job_completed') DEFAULT 'quote_request',
  `entity_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lead_event` (`lead_event_id`),
  FOREIGN KEY (`lead_event_id`) REFERENCES `lead_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `roi_attribution` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lead_id` int DEFAULT NULL,
  `conversion_event_id` int DEFAULT NULL,
  `service_type` varchar(100) DEFAULT NULL,
  `revenue` decimal(10,2) DEFAULT 0.00,
  `cost` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- CONTENT & PORTFOLIO
-- ============================================================================

CREATE TABLE IF NOT EXISTS `content_recommendations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` enum('landing_page','portfolio_item','faq_section') DEFAULT 'landing_page',
  `query` varchar(255) DEFAULT NULL,
  `target_slug` varchar(100) DEFAULT NULL,
  `suggested_title` varchar(255) DEFAULT NULL,
  `suggested_meta_desc` varchar(160) DEFAULT NULL,
  `suggested_h1` varchar(255) DEFAULT NULL,
  `outline_json` longtext,
  `status` enum('suggested','approved','drafted','published') DEFAULT 'suggested',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `actioned_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `portfolio_curation` (
  `id` int NOT NULL AUTO_INCREMENT,
  `portfolio_project_id` int NOT NULL,
  `featured` tinyint(1) DEFAULT '0',
  `featured_order` int DEFAULT NULL,
  `curation_reason` text,
  `curated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `curated_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`portfolio_project_id`),
  FOREIGN KEY (`portfolio_project_id`) REFERENCES `portfolio_projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- MEDIA MANAGEMENT
-- ============================================================================

CREATE TABLE IF NOT EXISTS `media_files` (
  `id` int NOT NULL AUTO_INCREMENT,
  `owner_user_id` int NOT NULL,
  `job_id` int DEFAULT NULL,
  `visit_id` int DEFAULT NULL,
  `property_id` int DEFAULT NULL,
  `client_id` int DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `width` int DEFAULT NULL,
  `height` int DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner_user_id`),
  KEY `idx_job` (`job_id`),
  FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `media_metadata` (
  `id` int NOT NULL AUTO_INCREMENT,
  `media_file_id` int NOT NULL,
  `metadata_key` varchar(100) NOT NULL,
  `metadata_value` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_media` (`media_file_id`),
  FOREIGN KEY (`media_file_id`) REFERENCES `media_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `media_activity_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `media_id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(100) DEFAULT NULL,
  `details` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_media` (`media_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `visit_photo_sets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_id` int NOT NULL,
  `visit_type` enum('initial_visit','inspection','final_walkthrough','other') DEFAULT 'initial_visit',
  `visit_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_job` (`job_id`),
  FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- GSC INTEGRATION TABLES
-- ============================================================================

CREATE TABLE IF NOT EXISTS `gsc_properties` (
  `id` int NOT NULL AUTO_INCREMENT,
  `site_url` varchar(255) NOT NULL,
  `connected_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `access_token_encrypted` text,
  `refresh_token_encrypted` text,
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_url` (`site_url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `gsc_snapshots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `property_id` int NOT NULL,
  `sync_history_id` int DEFAULT NULL,
  `snapshot_date` date NOT NULL,
  `data_json` longtext,
  `pulled_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_property` (`property_id`),
  KEY `idx_sync_history` (`sync_history_id`),
  FOREIGN KEY (`property_id`) REFERENCES `gsc_properties` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`sync_history_id`) REFERENCES `gsc_sync_history` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `gsc_query_page_stats` (
  `id` int NOT NULL AUTO_INCREMENT,
  `snapshot_id` int NOT NULL,
  `query` varchar(255) DEFAULT NULL,
  `page` varchar(512) DEFAULT NULL,
  `clicks` int DEFAULT '0',
  `impressions` int DEFAULT '0',
  `ctr` decimal(5,4) DEFAULT '0.0000',
  `position` decimal(5,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `idx_snapshot` (`snapshot_id`),
  FOREIGN KEY (`snapshot_id`) REFERENCES `gsc_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `gsc_sync_history_with_duration` (
  `id` int NOT NULL AUTO_INCREMENT,
  `property_id` int NOT NULL,
  `sync_type` enum('manual','cron','api') DEFAULT 'manual',
  `status` enum('pending','success','failed','partial') DEFAULT 'pending',
  `rows_processed` int DEFAULT 0,
  `rows_inserted` int DEFAULT 0,
  `rows_updated` int DEFAULT 0,
  `error_message` text,
  `started_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `initiated_by_user_id` int DEFAULT NULL,
  `notes` text,
  `duration_seconds` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_property` (`property_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- TRACK MIGRATION
-- ============================================================================

INSERT INTO migrations_log (migration_filename, executed_at, status)
VALUES ('030_create_missing_core_tables.sql', NOW(), 'success');
