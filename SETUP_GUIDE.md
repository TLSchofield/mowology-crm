# Mowology CRM - Local Development Setup Guide

## Overview

Your production database is fully synced and working. To get your **local development environment** working with the quote submission system, you need to sync your local database schema with production.

## Quick Start (3 Steps)

### Step 1: Check Current Database Status

```bash
cd /path/to/mowology-crm
php check-database.php
```

This shows:
- ✓ Tables that exist
- ✗ Tables that are missing
- Current database structure

### Step 2: Apply Migrations (if needed)

If tables are missing, run:

```bash
php apply-migrations-cli.php
```

This will:
- Connect to your local MySQL
- Create all required tables
- Show progress for each step
- Verify everything worked

### Step 3: Test Quote Form

Visit the quote form and submit a test:
- Form: https://www.mowology.ca/jobFlow/getQuote.php
- Check CRM dashboard to see the quote request

## What These Scripts Do

### `check-database.php`

Verifies your database connection and schema without making changes.

**Shows:**
- Database name and MySQL version
- All existing tables
- Missing tables (if any)
- Column structure for critical tables

**No changes made** - safe to run anytime.

### `apply-migrations-cli.php`

Applies all database migrations to sync with production schema.

**Creates:**
- Core: contacts, companies, properties, quote_requests
- Admin: users, sessions, password_reset_tokens
- Tracking: lead_events, conversion_events, roi_attribution
- Logging: consent_log, activity_log

**Handles:**
- Tables that already exist (skips without error)
- Foreign key constraints
- Proper collation (utf8mb4)

## Troubleshooting

### "Database connection failed"

1. Check MySQL is running (MAMP, Docker, etc.)
2. Verify config in `public/app_config/config.php`:
   ```php
   const DB_HOST = 'localhost';
   const DB_USER = 'root';
   const DB_PASS = '';
   const DB_NAME = 'your_database_name';
   ```

### "Tables already exist"

This is fine! The migration script skips tables that already exist.

If tables exist but quote form still fails:
- Run `check-database.php` to see current state
- Check if you're using a database snapshot from an older version
- Contact support with the output of `check-database.php`

### "Quote submission still failing after migration"

1. Verify all tables exist: `php check-database.php`
2. Check error log: `tail -50 /path/to/php-error.log`
3. Test manually:
   ```bash
   mysql -u username -p database_name
   SELECT * FROM quote_requests LIMIT 1;
   ```

## Database Schema Overview

### Core Tables

**contacts** - Customer information
- first_name, last_name, email, phone
- Consent tracking (quote_followup, marketing_email, sms)
- Lifecycle tracking (prospect, client, inactive)

**companies** - Business/strata company records
- Links to contacts (primary_contact, billing_contact)
- Billing information
- Account status

**properties** - Physical locations
- Address, city, postal_code
- Geolocation (latitude, longitude)
- Links to site contact

**quote_requests** - jobFlow form submissions
- Contact and property references
- Service types and urgency
- Status tracking (new, reviewing, quoted, converted)

### Tracking Tables

**lead_events** - User funnel entry
- Session tracking
- UTM parameters (source, medium, campaign)
- Landing page reference

**conversion_events** - Quote/job conversions
- Links lead_events to conversions
- Event type and entity ID
- Timestamp

**roi_attribution** - ROI tracking per job
- Links jobs to lead events
- Campaign source
- Estimated value

### Logging Tables

**consent_log** - GDPR compliance
- Consent given/denied per contact
- Consent type (quote, marketing, SMS)
- IP address and timestamp

**activity_log** - System audit trail
- User actions
- Contact/property/quote changes
- IP address for security

## File Locations

```
mowology-crm/
├── check-database.php              ← Run this first to check status
├── apply-migrations-cli.php        ← Run this to create tables
├── database/
│   ├── APPLY_ALL_MIGRATIONS.sql   ← All migrations combined
│   └── migrations/                 ← Individual migration files
├── public/
│   ├── jobFlow/
│   │   ├── jobFlow-getQuote.php   ← Quote form
│   │   └── jobFlow-confirm.php    ← Form confirmation (FIXED)
│   ├── crm/
│   │   └── api/
│   │       └── apply-migrations.php ← Optional web-based runner
│   └── app_config/
│       └── config.php              ← Database config
└── SYNC_DATABASE_INSTRUCTIONS.md   ← Detailed setup guide
```

## Common Commands

```bash
# Check database status
php check-database.php

# Apply migrations
php apply-migrations-cli.php

# View MySQL tables
mysql -u root -p database_name
SHOW TABLES;
DESCRIBE contacts;

# Run migrations manually
mysql -u root -p database_name < database/APPLY_ALL_MIGRATIONS.sql
```

## What Changed

### jobFlow-confirm.php (FIXED)
- Now works with full production schema
- Creates contacts with consent tracking
- Links properties to quote requests
- Tracks lead events for ROI
- Logs all user consent
- Non-blocking optional features (won't break form if tracking fails)

### New Features
- Web-based migration runner (admin only)
- CLI migration tool (fastest option)
- Database diagnostics
- Comprehensive setup documentation

## After Setup

1. ✓ Database schema matches production
2. ✓ Quote form works end-to-end
3. ✓ Data is tracked for ROI analysis
4. ✓ GDPR consent is logged
5. ✓ System is production-ready

## Support

If you encounter issues:

1. Run: `php check-database.php`
2. Note the output
3. Check the error in browser console (F12)
4. Review `SYNC_DATABASE_INSTRUCTIONS.md` for detailed options

---

**Last Updated:** Feb 10, 2026
**Status:** Production-ready with local setup tools
