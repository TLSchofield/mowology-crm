# Syncing Local Database with Production Schema

Your local database schema is out of sync with production. The jobFlow quote submission form expects tables and columns that don't exist locally yet.

## Quick Start (Choose One)

### Option 1: CLI Tool (Recommended - Easiest)

```bash
cd /path/to/mowology-crm
php apply-migrations-cli.php
```

The script will:
- Connect to your local database
- Apply all migrations automatically
- Show progress and verify tables
- Tell you if there are any issues

### Option 2: Using phpMyAdmin or MySQL GUI

1. Open phpMyAdmin/MySQL Workbench
2. Select your Mowology database
3. Go to Import tab
4. Select `/database/APPLY_ALL_MIGRATIONS.sql`
5. Click "Import"

### Option 3: Command Line MySQL

```bash
mysql -u username -p database_name < database/APPLY_ALL_MIGRATIONS.sql
```

Replace:
- `username` - Your MySQL user
- `database_name` - Your Mowology database name

### Option 4: Individual Migration Files (Advanced)

The migrations are located in `/database/migrations/` and should be applied in this order:

1. `001_restructure_core_tables.sql` - Core tables (contacts, companies, properties, quote_requests)
2. `007_job_system.sql` - Job management tables
3. `010_pdf_generation.sql` - PDF storage
4. `011_quote_notes_table.sql` - Quote notes
5. `012_client_notes_table.sql` - Client notes
6. (And others as needed)

## What Gets Updated

The migration script will create/update these critical tables:

### Core Tables
- `contacts` - Customer information + consent tracking
- `companies` - Business/strata companies
- `properties` - Property locations
- `quote_requests` - JobFlow quote form submissions

### Support Tables
- `users` - CRM users
- `sessions` - User sessions
- `password_reset_tokens` - Password recovery

### ROI & Tracking
- `lead_events` - Visitor funnel tracking
- `conversion_events` - Quote/job conversions
- `roi_attribution` - Job-to-lead attribution

### Logging
- `consent_log` - GDPR consent tracking
- `activity_log` - System audit log

## After Migration

Once the migration is complete:

1. Test the jobFlow quote form: `https://www.mowology.ca/jobFlow/getQuote.php`
2. Submit a test quote and verify it appears in the CRM
3. Verify you can see the quote request in the CRM dashboard
4. Delete `public/crm/api/apply-migrations.php` if you created it (security)

## Troubleshooting

### "Table already exists" errors
This is normal - the `IF NOT EXISTS` clauses handle existing tables gracefully.

### "Foreign key constraint fails"
Ensure you apply migrations in the correct order. The script handles this automatically.

### MySQL connection issues
- Verify MySQL is running
- Check database username/password in `public/app_config/config.php`
- Ensure the database name matches

## Verification

To verify the migration was successful, run this query in your MySQL client:

```sql
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME;
```

You should see at least these 14 tables:
- activity_log
- companies
- company_properties
- consent_log
- contacts
- conversion_events
- lead_events
- password_reset_tokens
- properties
- quote_requests
- roi_attribution
- sessions
- users
- (plus any other custom tables from other migrations)

## Files Created

- `database/APPLY_ALL_MIGRATIONS.sql` - Complete migration script
- `public/crm/api/apply-migrations.php` - Web-based migration runner (admin only)

## Production Note

Your production database already has this schema applied. This script brings your **local development environment** in sync.

---

Questions? Check the error logs in `public/crm/api/apply-migrations.php` output or review individual migration files in `database/migrations/`
