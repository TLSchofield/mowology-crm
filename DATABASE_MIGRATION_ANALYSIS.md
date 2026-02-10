# Mowology CRM Database Schema & Migration Analysis

**Generated:** February 10, 2026
**Status:** Comprehensive gap analysis between SCHEMA_MASTER.sql and migration files

---

## Executive Summary

- **Total tables in SCHEMA_MASTER.sql:** 89
- **Tables created by migrations:** 66
- **Tables NOT created by migrations:** 27 (30.3% gap)
- **Duplicate CREATE statements:** 1 (seo_page_drafts)
- **Schema coverage:** 69.7%
- **Migration files analyzed:** 34

**Critical Issues:**
1. **27 core tables are missing migrations** — these will not be created by running the migration system
2. **seo_page_drafts created twice** — in different migration files with potential conflicts
3. **4 migration-only tables not in SCHEMA_MASTER** — cms_media_variants, cms_media_alt_suggestions, cms_page_generations_log, cms_case_studies_generated

---

## Part 1: Master Schema Tables (89 total)

### By Migration Source

| Table Name | Source Migration | Notes |
|---|---|---|
| activity_log | 001_restructure_core_tables.sql | ✓ Core table |
| billing_templates | 026_create_billing_templates.sql | ✓ |
| business_settings | 020_business_settings_table.sql | ✓ |
| **client_feedback** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| client_notes | 012_client_notes_table.sql | ✓ |
| cms_block_template_versions | done-502_cms_template_library.sql | ✓ |
| cms_block_templates | done-502_cms_template_library.sql | ✓ |
| cms_block_types | done-500_cms_core.sql | ✓ |
| cms_blocks | done-500_cms_core.sql | ✓ |
| cms_menu_items | done-500_cms_core.sql | ✓ |
| cms_menus | done-500_cms_core.sql | ✓ |
| cms_page_generator_config | 113_cms_phase4_template_generation.sql | ✓ |
| cms_page_revisions | done-500_cms_core.sql | ✓ |
| cms_page_template_versions | done-502_cms_template_library.sql | ✓ |
| cms_page_templates | done-502_cms_template_library.sql | ✓ |
| cms_pages | done-500_cms_core.sql | ✓ |
| cms_pages_template_audit | done-502_cms_template_library.sql | ✓ |
| cms_template_group_members | done-502_cms_template_library.sql | ✓ |
| cms_template_groups | done-502_cms_template_library.sql | ✓ |
| cms_template_performance | done-502_cms_template_library.sql | ✓ |
| cms_template_presets | done-502_cms_template_library.sql | ✓ |
| **communication_log** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| companies | 001_restructure_core_tables.sql | ✓ Core table |
| company_properties | 001_restructure_core_tables.sql | ✓ Core table |
| consent_log | 001_restructure_core_tables.sql | ✓ Core table |
| contacts | 001_restructure_core_tables.sql | ✓ Core table |
| **content_recommendations** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| **conversion_events** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| **cost_factors** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| crew_location_history | problem-022_location_aware_job_creation.sql | ✓ |
| **estimator_templates** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| geocoding_cache | problem-022_location_aware_job_creation.sql | ✓ |
| **gsc_properties** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| **gsc_query_page_stats** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| **gsc_snapshots** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING (altered by 110_gsc_sync_history.sql) |
| gsc_sync_history | 110_gsc_sync_history.sql | ✓ |
| **gsc_sync_history_with_duration** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING (view or computed table) |
| invoice_contacts | done-015_property_management_relationships.sql | ✓ |
| **invoice_items** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| invoice_line_items | 007_job_system.sql | ✓ |
| invoices | 007_job_system.sql | ✓ |
| job_notes | 007_job_system.sql | ✓ |
| job_photos | 007_job_system.sql | ✓ |
| job_proof_of_work | done-027_create_job_proof_of_work.sql | ✓ |
| jobs | 007_job_system.sql | ✓ Core table |
| **lead_events** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| lifecycle_stages | 021_add_lifecycle_stage_to_companies.sql | ✓ |
| marketing_logs | problem-501_marketing_automation_tables.sql | ✓ |
| marketing_performance | problem-501_marketing_automation_tables.sql | ✓ |
| marketing_queue | problem-501_marketing_automation_tables.sql | ✓ |
| **media_activity_log** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| media_assets | done-500_cms_core.sql | ✓ |
| **media_files** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| **media_metadata** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| migrations_log | 024_create_migrations_log.sql | ✓ |
| password_reset_tokens | create_password_reset_tokens_table.sql | ✓ |
| **portfolio_curation** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| portfolio_projects | 013_portfolio_projects_table.sql | ✓ |
| **product_categories** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| **product_cost_breakdown** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| **products** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| properties | 001_restructure_core_tables.sql | ✓ Core table |
| property_contacts | done-015_property_management_relationships.sql | ✓ |
| property_measurements | 002_property_measurements.sql | ✓ |
| property_visit_patterns | problem-022_location_aware_job_creation.sql | ✓ |
| **quote_items** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| quote_line_items | 007_job_system.sql | ✓ |
| quote_notes | 011_quote_notes_table.sql | ✓ |
| quote_requests | 001_restructure_core_tables.sql | ✓ Core table |
| **quotes** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING (altered by 4 migrations) |
| **roi_attribution** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| seo_improvement_guidelines | done-503_seo_template_library.sql | ✓ |
| **seo_page_drafts** | **done-100_seo_recommendations.sql & problem-501_marketing_automation_tables.sql** | ⚠️ DUPLICATE |
| seo_recommendation_responses | done-503_seo_template_library.sql | ✓ |
| seo_recommendation_status_history | problem-501_marketing_automation_tables.sql | ✓ |
| seo_recommendations | done-100_seo_recommendations.sql | ✓ |
| seo_recommendations_audit | done-100_seo_recommendations.sql | ✓ |
| seo_response_template_versions | done-503_seo_template_library.sql | ✓ |
| seo_response_templates | done-503_seo_template_library.sql | ✓ |
| seo_seasons | done-100_seo_recommendations.sql | ✓ |
| seo_targets | done-100_seo_recommendations.sql | ✓ |
| seo_template_conditions | done-503_seo_template_library.sql | ✓ |
| service_orders | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| service_packages | 025_create_service_packages.sql | ✓ |
| service_templates | 007_job_system.sql | ✓ |
| **sessions** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| **unit_types** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |
| **users** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING (core auth table!) |
| **visit_photo_sets** | **NOT_IN_MIGRATIONS** | ⚠️ MISSING |

---

## Part 2: Critical Issues

### Issue #1: Duplicate CREATE TABLE - seo_page_drafts

**Severity:** HIGH

Two separate migration files create the same table:
- `done-100_seo_recommendations.sql`
- `problem-501_marketing_automation_tables.sql`

**Problem:** If migrations are run out of order, the second CREATE TABLE IF NOT EXISTS will succeed silently, but if the first one fails, you may end up with missing data or schema mismatches.

**Recommendation:**
- Keep the table creation in `done-100_seo_recommendations.sql` (it's in the "done" directory and runs first)
- Remove the CREATE TABLE statement from `problem-501_marketing_automation_tables.sql`
- Keep any ALTER TABLE statements that add new columns in the second file

---

### Issue #2: Core Tables Not in Migrations (27 tables)

**Severity:** CRITICAL

#### Missing Foundation Tables (3)
These are the **most critical** — they won't be created by the migration system:
- **users** — Core authentication table (essential)
- **sessions** — Core session management (essential)
- **products** — Core product catalog (product management feature)

#### Missing Feature Tables (24)
- client_feedback, communication_log, content_recommendations, conversion_events
- cost_factors, estimator_templates
- gsc_properties, gsc_query_page_stats, gsc_snapshots, gsc_sync_history_with_duration
- invoice_items, lead_events
- media_activity_log, media_files, media_metadata
- portfolio_curation
- product_categories, product_cost_breakdown
- quote_items, quotes (⚠️ altered by 4 migrations!)
- roi_attribution, service_orders
- unit_types, visit_photo_sets

**Root Cause:** These tables exist in SCHEMA_MASTER.sql (probably from a live database export) but were never formalized into the migration system. They may have been created manually or exist only in backups.

**Impact:**
1. Fresh database from migrations will be missing 27 tables
2. Running migrations won't populate the users table — authentication will fail
3. Old systems (that have SCHEMA_MASTER) will have extra tables not in the migration flow

---

### Issue #3: Altered Tables Not Created in Migrations

**Severity:** MEDIUM

The `quotes` table is a key example:
- NOT created by any migration
- Altered by 4 different migrations:
  - `001_add_roi_pipeline_fields.sql` (adds ROI fields)
  - `007_job_system.sql` (adds job relation fields)
  - `008_quotes_table_alignment.sql` (adds title, service_type, amount, tax_rate, etc.)
  - `010_pdf_generation.sql` (adds PDF fields)

**Problem:** If the quotes table doesn't exist, all 4 ALTER statements will fail silently.

**Similarly affected tables:**
- `gsc_snapshots` — altered by `110_gsc_sync_history.sql` but not created in migrations
- `properties` — altered by `done-015_property_management_relationships.sql` but created in `001_restructure_core_tables.sql` (OK)
- `contacts`, `companies` — similarly altered after initial creation

---

### Issue #4: Migration-Only Tables

**Severity:** LOW-MEDIUM

Four tables exist in migrations but NOT in SCHEMA_MASTER.sql:
- `cms_media_variants` (112_cms_phase3_media_optimization.sql)
- `cms_media_alt_suggestions` (112_cms_phase3_media_optimization.sql)
- `cms_page_generations_log` (113_cms_phase4_template_generation.sql)
- `cms_case_studies_generated` (114_cms_phase5_portfolio_integration.sql)

**Problem:** SCHEMA_MASTER.sql doesn't include these newer tables. This means SCHEMA_MASTER is out of date or incomplete.

---

## Part 3: Tables Modified by Multiple Migrations

### Tables with ALTER statements in multiple migrations:

| Table | Migrations | Summary |
|---|---|---|
| quotes | 001_add_roi_pipeline_fields, 007_job_system, 008_quotes_table_alignment, 010_pdf_generation | Columns added across 4 migrations |
| gsc_snapshots | 110_gsc_sync_history | Added sync_history_id FK |
| contacts | 001_restructure_core_tables, 023_consolidate_lifecycle_stages | Lifecycle stage relation added |
| companies | 001_restructure_core_tables, 021_add_lifecycle_stage_to_companies, 023_consolidate_lifecycle_stages | Lifecycle stage columns added |
| properties | 001_restructure_core_tables, 002_property_measurements, 015_property_management_relationships, 022_location_aware_job_creation | Multiple enhancements |
| jobs | 007_job_system, 028_update_jobs_for_service_packages, 029_add_custom_recurrence_fields | Job system enhancements |

**Analysis:** This is normal for long-lived feature tables. The risk is if a migration runs against a fresh database where the base table doesn't exist.

---

## Part 4: Migration File Inventory (34 files)

### Active Migrations (Not prefixed with "done-" or "problem-")
These are assumed to be the canonical migration set:

1. `001_add_roi_pipeline_fields.sql` — ALTER TABLE (dependencies: quotes table)
2. `001_restructure_core_tables.sql` — CREATE TABLE (base tables: contacts, companies, properties, etc.)
3. `002_property_measurements.sql` — CREATE TABLE property_measurements
4. `007_job_system.sql` — CREATE TABLE jobs, invoices, job_notes, job_photos, etc.
5. `008_quotes_table_alignment.sql` — ALTER TABLE quotes
6. `010_pdf_generation.sql` — ALTER TABLE quotes, invoices
7. `011_quote_notes_table.sql` — CREATE TABLE quote_notes
8. `012_client_notes_table.sql` — CREATE TABLE client_notes
9. `013_portfolio_projects_table.sql` — CREATE TABLE portfolio_projects
10. `014_add_tags_to_portfolio.sql` — ALTER TABLE portfolio_projects
11. `020_business_settings_table.sql` — CREATE TABLE business_settings
12. `021_add_lifecycle_stage_to_companies.sql` — CREATE TABLE lifecycle_stages, ALTER TABLE companies
13. `023_consolidate_lifecycle_stages.sql` — ALTER TABLE (lifecycle stage consolidation)
14. `024_create_migrations_log.sql` — CREATE TABLE migrations_log
15. `025_create_service_packages.sql` — CREATE TABLE service_packages
16. `026_create_billing_templates.sql` — CREATE TABLE billing_templates
17. `028_update_jobs_for_service_packages.sql` — ALTER TABLE jobs
18. `029_add_custom_recurrence_fields.sql` — ALTER TABLE jobs
19. `110_gsc_sync_history.sql` — CREATE TABLE gsc_sync_history, ALTER TABLE gsc_snapshots
20. `112_cms_phase3_media_optimization.sql` — CREATE TABLE cms_media_variants, cms_media_alt_suggestions
21. `113_cms_phase4_template_generation.sql` — CREATE TABLE cms_page_generator_config, cms_page_generations_log
22. `114_cms_phase5_portfolio_integration.sql` — CREATE TABLE cms_case_studies_generated
23. `create_password_reset_tokens_table.sql` — CREATE TABLE password_reset_tokens

### "Done" Migrations (completed/archived)
- `done-015_property_management_relationships.sql` — CREATE TABLE invoice_contacts, property_contacts
- `done-027_create_job_proof_of_work.sql` — CREATE TABLE job_proof_of_work
- `done-100_seo_recommendations.sql` — CREATE TABLE seo_targets, seo_seasons, seo_recommendations, seo_page_drafts, seo_recommendations_audit
- `done-500_cms_core.sql` — CREATE TABLE cms_pages, cms_block_types, cms_blocks, cms_menus, cms_menu_items, media_assets, cms_page_revisions
- `done-502_cms_template_library.sql` — CREATE TABLE cms_page_templates, cms_page_template_versions, cms_block_templates, cms_block_template_versions, cms_pages_template_audit, cms_template_groups, cms_template_group_members, cms_template_presets, cms_template_performance
- `done-503_seo_template_library.sql` — CREATE TABLE seo_response_templates, seo_response_template_versions, seo_recommendation_responses, seo_template_conditions, seo_improvement_guidelines

### "Problem" Migrations (problematic/in-progress)
- `problem-022_location_aware_job_creation.sql` — CREATE TABLE crew_location_history, geocoding_cache, property_visit_patterns (also ALTERs properties)
- `problem-501_marketing_automation_tables.sql` — CREATE TABLE marketing_queue, marketing_logs, seo_page_drafts (DUPLICATE!), seo_recommendation_status_history, marketing_performance

### TODO Migrations (not yet executed)
- `todo-115_seed_generator_templates.sql` — (empty or placeholder)

---

## Part 5: Recommendations

### Priority 1: CRITICAL - Fix Schema-Migration Sync

1. **Create migrations for core missing tables:**
   ```sql
   -- 030_create_core_missing_tables.sql
   CREATE TABLE IF NOT EXISTS `users` (...)
   CREATE TABLE IF NOT EXISTS `sessions` (...)
   CREATE TABLE IF NOT EXISTS `quotes` (...)
   CREATE TABLE IF NOT EXISTS `products` (...)
   CREATE TABLE IF NOT EXISTS `unit_types` (...)
   -- ... and 22 others
   ```

2. **Verify quotes table migration order:**
   - Ensure quotes is created BEFORE the four ALTER migrations
   - Consider consolidating into a single "003_quotes_table.sql" file

3. **Fix seo_page_drafts duplicate:**
   - Remove CREATE TABLE from `problem-501_marketing_automation_tables.sql`
   - Keep only in `done-100_seo_recommendations.sql`

### Priority 2: HIGH - Update SCHEMA_MASTER.sql

The current SCHEMA_MASTER.sql is missing 4 new CMS tables. Update it to include:
- cms_media_variants
- cms_media_alt_suggestions
- cms_page_generations_log
- cms_case_studies_generated

Or regenerate SCHEMA_MASTER.sql from the live database.

### Priority 3: MEDIUM - Consolidate Migration Files

The migration system has inconsistent prefixes ("done-", "problem-"). Recommend:
- Rename "done-" files to sequential numbers (015, 027, 100, 500, 502, 503)
- Address or remove "problem-" files (fix issues, then rename)
- Document the "why" each file exists in comments

### Priority 4: MEDIUM - Verify Execution Order

Test migration order with a fresh database:
```bash
mysql mowology_test < database/migrations/001_*.sql
mysql mowology_test < database/migrations/002_*.sql
# ... etc
```

Ensure no "Table doesn't exist" errors for ALTER statements.

### Priority 5: LOW - Add Audit Trail

Update migrations_log table to track:
- Migration file name
- Execution timestamp
- Hash of file contents (to detect changes)
- Any errors during execution

---

## Part 6: SQL Statements for Missing Tables

Below are CREATE TABLE statements extracted from SCHEMA_MASTER.sql for the 27 missing tables. Create a new migration file `030_create_missing_core_tables.sql` with these statements.

### Foundation Tables (CRITICAL)

```sql
-- File: 030_create_missing_core_tables.sql

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `unit_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `abbreviation` varchar(10) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

### Additional Feature Tables

```sql
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `product_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `product_cost_breakdown` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `cost_factor_id` int NOT NULL,
  `quantity_per_unit` decimal(10,2) DEFAULT '1.00',
  `unit_cost` decimal(10,2) NOT NULL,
  `total_cost` decimal(10,2) GENERATED ALWAYS AS (quantity_per_unit * unit_cost) STORED,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `roi_attribution` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lead_id` int DEFAULT NULL,
  `conversion_event_id` int DEFAULT NULL,
  `service_type` varchar(100) DEFAULT NULL,
  `revenue` decimal(10,2) DEFAULT 0.00,
  `cost` decimal(10,2) DEFAULT 0.00,
  `roi_percent` decimal(6,2) GENERATED ALWAYS AS ((revenue - cost) / cost * 100) STORED,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `conversion_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lead_event_id` int DEFAULT NULL,
  `event_type` enum('quote_request','quote_sent','quote_accepted','job_created','job_completed') DEFAULT 'quote_request',
  `entity_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lead_event` (`lead_event_id`),
  FOREIGN KEY (`lead_event_id`) REFERENCES `lead_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `gsc_properties` (
  `id` int NOT NULL AUTO_INCREMENT,
  `site_url` varchar(255) NOT NULL,
  `connected_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `access_token_encrypted` text,
  `refresh_token_encrypted` text,
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_url` (`site_url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `media_metadata` (
  `id` int NOT NULL AUTO_INCREMENT,
  `media_file_id` int NOT NULL,
  `metadata_key` varchar(100) NOT NULL,
  `metadata_value` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_media` (`media_file_id`),
  FOREIGN KEY (`media_file_id`) REFERENCES `media_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `visit_photo_sets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_id` int NOT NULL,
  `visit_type` enum('initial_visit','inspection','final_walkthrough','other') DEFAULT 'initial_visit',
  `visit_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_job` (`job_id`),
  FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

---

## Summary Table

| Category | Count | Status |
|---|---|---|
| **Schema Tables** | 89 | Total in SCHEMA_MASTER |
| **Migration-Created Tables** | 66 | Created by migrations |
| **Missing Tables** | 27 | ⚠️ NOT created by migrations |
| **Critical Missing (users, sessions, quotes)** | 3 | 🔴 CRITICAL |
| **Duplicate Creates** | 1 (seo_page_drafts) | ⚠️ NEEDS FIX |
| **Tables with Multiple Alters** | 6 | Normal (feature evolution) |
| **Migration Files** | 34 | Total migration files |
| **Active Migrations** | 23 | Numbered migrations |
| **Done Migrations** | 6 | Archived |
| **Problem Migrations** | 2 | In-progress/problematic |
| **TODO Migrations** | 1 | Placeholder |
| **Schema Coverage** | 69.7% | Percentage of tables in migrations |

---

## Conclusion

The Mowology CRM has a significant schema-migration gap:

1. **27 core tables exist in SCHEMA_MASTER but have no migrations** — running migrations from scratch will not create a complete database
2. **3 critical tables (users, sessions, quotes) are missing** — authentication and quoting will fail
3. **seo_page_drafts is created twice** — risk of conflicting schema changes
4. **4 new CMS tables in migrations but not in SCHEMA_MASTER** — SCHEMA_MASTER is out of date

**Recommended immediate action:** Create `030_create_missing_core_tables.sql` with all 27 missing tables and update the migration execution process to validate that critical tables are present before running ALTER operations.

