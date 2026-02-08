# Mowology CRM Database

## Files

- `schema.sql` - **Master schema** - The complete current database structure
- `/migrations/` - Incremental changes for updating existing databases

## How to Update Master Schema

1. Go to phpMyAdmin
2. Select database `mowology_landscape_crm`
3. Click **Export** tab
4. Choose **Custom** export method
5. Settings:
   - Format: SQL
   - Tables: Select all
   - **Output**: Check "Structure" only (uncheck Data)
   - Check "Add DROP TABLE"
   - Check "Add CREATE DATABASE / USE"
6. Click **Go**
7. Replace `schema.sql` with the downloaded file

## How to Apply Migrations

Run migrations in order in phpMyAdmin:
```
SET FOREIGN_KEY_CHECKS = 0;
-- paste migration SQL here --
SET FOREIGN_KEY_CHECKS = 1;
```

## Current Version

Last updated: 2025-02-03
Tables: contacts, companies, properties, company_properties, quote_requests, consent_log, activity_log, users, products, quotes, etc.
