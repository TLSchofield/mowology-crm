# Mowology CRM — Database Schema Files Guide

⚠️ **CRITICAL: Before writing ANY SQL, read `DATABASE_CRITICAL_CONSTRAINTS.md`**

Your production database uses **MySQL 5.7+**. Many SQL features from MySQL 8.0+ will NOT work:
- ❌ NO `IF NOT EXISTS` in `ALTER TABLE ADD COLUMN` (causes #1064 syntax error)
- ❌ NO window functions (`ROW_NUMBER()`, `RANK()`, etc.)
- ❌ NO `JSON_EXTRACT()` or JSON functions
- ❌ NO generated columns

**Always use:** `utf8mb4_general_ci` collation on all tables

See `DATABASE_CRITICAL_CONSTRAINTS.md` for full compatibility rules and safe migration patterns.

---

This guide explains the three database schema files available for setting up the Mowology CRM database.

---

## Quick Reference

| File | Best For | Compatibility | Idempotent | Size |
|------|----------|---------------|-----------|------|
| `INIT_DATABASE.sql` | Fresh database with basic schema | MySQL 5.7+ | Yes | Small |
| `COMPLETE_DATABASE_SCHEMA_CLEAN.sql` | Production setup (RECOMMENDED) | MySQL 5.7+ | Yes | 855 lines |
| `COMPLETE_DATABASE_SCHEMA_SAFE.sql` | Alternative full schema | MySQL 5.7+ | Yes | ~1200 lines |

---

## INIT_DATABASE.sql (Legacy/Basic)

**Purpose:** Initial database setup with core 6 tables.

**Contains:**
- `users` — CRM user authentication
- `companies` — Client company information
- `properties` — Customer properties/locations
- `quotes` — Quote workflow and tracking
- `jobs` — Job scheduling and management
- `activity_log` — System activity tracking and audit trail

**Default Admin User:**
- Username: `mowology`
- Email: `mowology@icloud.com`
- Password: `Sunwukong2026#` (hashed)

**When to use:**
- Quick setup for testing
- Minimal feature set only
- Not recommended for production

**How to run:**
```bash
mysql -u username -p database_name < INIT_DATABASE.sql
```

---

## COMPLETE_DATABASE_SCHEMA_CLEAN.sql (RECOMMENDED)

**Purpose:** Full production-ready schema, simplified for MySQL 5.7 compatibility.

**Contains 35+ tables organized in 7 phases:**

### Phase 0: Lookup Tables
- `lifecycle_stages` — Contact and company lifecycle classification

### Phase 1: Core Tables
- `users` — User management and authentication
- `contacts` — Individual prospects and clients
- `companies` — Business accounts and billing
- `properties` — Customer property locations
- `company_properties` — Junction between companies and properties
- `lead_events` — Lead source and interaction tracking
- `quote_requests` — Quote request form submissions
- `consent_log` — Marketing consent audit trail

### Phase 2: Quotes & Invoicing
- `quotes` — Quote workflow management
- `quote_line_items` — Individual line items on quotes
- `quote_notes` — Quote internal and customer notes
- `invoices` — Invoice generation and tracking
- `invoice_line_items` — Individual invoice line items
- `invoice_contacts` — Contact-specific invoice routing

### Phase 3: Jobs & Completion
- `jobs` — Job scheduling and management
- `job_notes` — Internal job notes
- `job_photos` — Before/after and progress photos
- `job_proof_of_work` — Photo and signature proof of completion

### Phase 4: Portfolio & Client Relations
- `client_notes` — General client relationship notes
- `portfolio_projects` — Portfolio display and testimonials

### Phase 5: Location & Analytics
- `property_measurements` — Area and measurement calculations
- `crew_location_history` — GPS tracking for jobs
- `geocoding_cache` — Cached address geocoding
- `property_visit_patterns` — Historical visit analysis
- `property_contacts` — Contact assignments to properties

### Phase 6: Service Configuration
- `service_templates` — Reusable service definitions
- `service_packages` — Bundled service offerings
- `billing_templates` — Invoice grouping and timing rules

### Phase 7: Business & System
- `business_settings` — Company configuration
- `migrations_log` — Database migration history
- `password_reset_tokens` — Password recovery tokens
- `activity_log` — Audit trail for all changes

**Key Features:**
- ✅ MySQL 5.7+ fully compatible (no unsupported syntax)
- ✅ Idempotent (`IF NOT EXISTS` on all tables)
- ✅ Unified `utf8mb4` collation (no per-column collation issues)
- ✅ Clean syntax (no backticks, straightforward)
- ✅ Proper foreign key constraint ordering
- ✅ `FOREIGN_KEY_CHECKS` disabled during import, re-enabled after
- ✅ Default data inserted (admin user, lifecycle stages, service templates, billing templates)

**When to use:**
- New production database setup
- Full feature support needed
- Production CRM deployment
- **This is the RECOMMENDED file**

**How to run:**
```bash
# In phpMyAdmin: Import tab → select file → Import
# Or via command line:
mysql -u username -p database_name < COMPLETE_DATABASE_SCHEMA_CLEAN.sql
```

**Default Data Inserted:**
- Admin user: `mowology` / `mowology@icloud.com`
- 8 lifecycle stages (Lead, Opportunity, Prospect, Qualified, Client, Won, Inactive, Lost)
- 12 service templates (Lawn Mowing, Hedge Trimming, Spring/Fall Cleanup, Snow Removal, etc.)
- 4 billing templates (Per Visit, Monthly Grouped, Monthly Flat, Seasonal Prepay)

---

## COMPLETE_DATABASE_SCHEMA_SAFE.sql (Alternative)

**Purpose:** Full production schema with additional compatibility safeguards.

**Differences from CLEAN:**
- More verbose syntax in some areas
- Still MySQL 5.7 compatible
- All `IF NOT EXISTS` guards
- Similar table structure to CLEAN

**When to use:**
- If CLEAN version encounters issues
- Alternative backup schema
- Additional safety margin desired

**How to run:**
```bash
mysql -u username -p database_name < COMPLETE_DATABASE_SCHEMA_SAFE.sql
```

---

## Setup Instructions

### Option 1: Using phpMyAdmin (Easiest for cPanel)

1. Log into phpMyAdmin
2. Create a new database (e.g., `mowology_landscape_crm`)
3. Select the new database
4. Click **Import** tab
5. Click **Choose File** and select `COMPLETE_DATABASE_SCHEMA_CLEAN.sql`
6. Click **Import** button
7. Wait for completion — you should see no errors

### Option 2: Using Command Line

```bash
# SSH into your server
cd /path/to/mowology-crm/database

# Run the import
mysql -u your_username -p your_database_name < COMPLETE_DATABASE_SCHEMA_CLEAN.sql

# You'll be prompted for your MySQL password
```

### Option 3: Using MySQL Client Directly

```sql
SOURCE /path/to/COMPLETE_DATABASE_SCHEMA_CLEAN.sql;
```

---

## Troubleshooting

### Error: "Unknown system variable 'OLD_COLLATION_CONNECTION'"
**Solution:** This only affects older versions. The CLEAN file doesn't use this variable.

### Error: "Duplicate foreign key constraint name"
**Solution:** The file uses `IF NOT EXISTS` and `SET FOREIGN_KEY_CHECKS = 0` to prevent this. Re-run the import; it should complete on second run.

### Error: "1864 - Collation 'utf8mb4_0900_ai_ci' is not valid"
**Solution:** This means your MySQL version is older than 8.0. The CLEAN file uses `utf8mb4` only (compatible with 5.7+), so upgrade MySQL or use SAFE version.

### Error: "Cannot add or update a child row — a foreign key constraint fails"
**Solution:** Ensure all related tables exist. The CLEAN file disables FK checks during import (`SET FOREIGN_KEY_CHECKS = 0`), so if you get this error, a table didn't create. Check the full import output for earlier errors.

---

## Verification

After import, verify the database setup:

```sql
-- Count tables
SHOW TABLES;
-- Should show 35+ tables

-- Check admin user
SELECT id, username, email, role FROM users;
-- Should show: 1 | mowology | mowology@icloud.com | admin

-- Check lifecycle stages
SELECT COUNT(*) FROM lifecycle_stages;
-- Should show: 8

-- Check service templates
SELECT COUNT(*) FROM service_templates;
-- Should show: 12
```

---

## Next Steps

1. ✅ Run `COMPLETE_DATABASE_SCHEMA_CLEAN.sql` to set up the database
2. Update `/app_config/secrets.php` with your database credentials (if not already done)
3. Test CRM login with `mowology@icloud.com` (default password as shown above)
4. Create additional users as needed via the CRM admin panel
5. Customize business settings (company name, contact info, etc.) in the CRM settings

---

## Notes for Developers

**Migration Strategy:**
The `migrations_log` table tracks which migrations have been applied. If you create new migrations:

1. Create SQL file in `database/migrations/` with date prefix: `023_your_migration_name.sql`
2. Reference it in your migration runner
3. The runner records it in `migrations_log` with status, timestamp, and any error messages

**Backup Strategy:**
After successful import, create backups:

```bash
# Export full schema + data
mysqldump -u username -p database_name > backup_full.sql

# Export schema only
mysqldump -u username -p --no-data database_name > backup_schema_only.sql
```

**Adding New Tables:**
When adding new tables to the schema:
1. Add the table creation to `COMPLETE_DATABASE_SCHEMA_CLEAN.sql` in the appropriate phase
2. Follow the same naming conventions (snake_case tables, utf8mb4 charset)
3. Re-generate the SAFE version if needed
4. Use `IF NOT EXISTS` for idempotency
