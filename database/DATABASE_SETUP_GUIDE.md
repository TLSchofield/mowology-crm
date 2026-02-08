# Database Setup Guide

## Overview

This guide covers initializing the Mowology CRM database on both local development and production environments.

## Files Included

- **INIT_DATABASE.sql** - Complete database schema for fresh installation
- **SCHEMA_MASTER.sql** - Alternative schema reference (includes all data structures)
- **migrations/** - Incremental database changes (for future updates)

---

## Local Development Setup

### Using the Web Interface (Easiest)

1. Visit: **http://localhost:8888/DATABASE_SETUP.php**
2. The tool will automatically:
   - Create all required tables
   - Create default admin user
   - Show status of each table

### Using Command Line

```bash
mysql -u root -proot mowology_landscape_crm < database/INIT_DATABASE.sql
```

### Using phpMyAdmin

1. Open phpMyAdmin
2. Select your database
3. Click **Import**
4. Upload **INIT_DATABASE.sql**
5. Click **Go**

---

## Production Setup (Live Server)

### Via cPanel File Manager

1. Log in to cPanel
2. Open **File Manager**
3. Navigate to your database directory
4. Upload **INIT_DATABASE.sql**

### Via cPanel Terminal (if available)

```bash
mysql -u cpanel_user -p database_name < INIT_DATABASE.sql
```

### Via phpMyAdmin (cPanel)

1. Log in to cPanel phpMyAdmin
2. Create new database: `mowology_landscape_crm`
3. Select the database
4. Click **Import**
5. Upload **INIT_DATABASE.sql**
6. Click **Go**

### Via SSH (Advanced)

```bash
ssh user@your-domain.com
cd /home/your-account/
mysql -u cpanel_user -p mowology_landscape_crm < database/INIT_DATABASE.sql
```

---

## Database Tables

### users
Stores CRM user accounts and credentials.
- Columns: id, username, email, password_hash, full_name, role, is_active, last_login, created_at, updated_at
- Default admin: mowology@icloud.com

### companies
Client company information.
- Columns: id, company_name, contact_name, email, phone, address, city, province, postal_code, account_status, created_at, updated_at

### properties
Customer properties/locations.
- Columns: id, company_id, address, city, province, postal_code, property_type, created_at, updated_at

### quotes
Quote workflow and tracking.
- Columns: id, quote_number, property_id, company_id, title, status, service_types, subtotal, tax_amount, total_amount, expiry_date, sent_at, accepted_at, created_by, created_at, updated_at
- Statuses: draft, sent, accepted, declined, expired

### jobs
Job scheduling and management.
- Columns: id, job_number, quote_id, property_id, company_id, title, description, status, scheduled_date, completed_date, amount, created_by, created_at, updated_at
- Statuses: scheduled, in_progress, completed, cancelled

### activity_log
System activity tracking and audit trail.
- Columns: id, user_id, contact_id, company_id, property_id, job_id, quote_id, action, details, ip_address, created_at

---

## Default Admin User

**Email:** mowology@icloud.com
**Password:** Sunwukong2026#
**Role:** admin
**Status:** active

### Changing Admin Password

1. Log in with default credentials
2. Go to Settings/Users
3. Click on admin user
4. Change password
5. Save

Or via database (use PHP password_hash):

```sql
UPDATE users
SET password_hash = '$2y$12$...'
WHERE email = 'mowology@icloud.com';
```

---

## Troubleshooting

### "Table already exists" errors
- This is normal and safe - tables won't be recreated if they already exist
- The script uses `CREATE TABLE IF NOT EXISTS`

### "Access denied for user" errors
- Check username and password
- Verify database exists
- Check user privileges in cPanel

### "Foreign key constraint fails"
- Ensure tables are created in the correct order
- Don't modify the SQL file - it handles dependencies

### Missing tables after import
- Check import output for errors
- Verify database is selected before importing
- Try importing line by line if issues persist

---

## Backup Before Setup

Always backup your database before running initialization:

```bash
mysqldump -u username -p database_name > backup_$(date +%Y%m%d).sql
```

---

## Incremental Updates

For future database changes, use the migration files in the `migrations/` directory:

```bash
mysql -u username -p database_name < migrations/001_add_field.sql
```

---

## Support

If you encounter issues:
1. Check the error message
2. Verify database credentials
3. Ensure database user has proper privileges
4. Check MySQL/MariaDB version (5.7+ required)
5. Review cPanel documentation for your hosting provider
