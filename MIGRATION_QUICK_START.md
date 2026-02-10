# Database Migration Manager - Quick Start Guide

## What You Can Do Now

### 1. Navigate to Database Migrations
- Log in as **Admin**
- Click **Settings** in the sidebar
- Click **"Database / Migrations"** tab

### 2. View Your Database Status
- See database name and MySQL version
- Green "✓ Healthy" badge means all is well
- Red "⚠ Issues" badge means something needs attention

### 3. Execute Pending Migrations
- Look for yellow cards in **"Pending Migrations"** section
- Read the description of what each migration does
- Click **"Execute"** button
- Confirm in the popup dialog
- Wait for success message

### 4. Check Migration History
- Scroll to **"Migration History"** section
- Filter by "All", "Success", or "Failed"
- See who executed each migration and when
- Click error messages to see full details

---

## Your New SQL Migrations

### Migration 023: Consolidate Lifecycle Stages
**What it does:** Unifies lifecycle stage management for both contacts and companies using a single shared table
**Impact:** Requires minor code update for proper FK handling
**Status:** Ready to execute

### Migration 024: Create Migrations Log
**What it does:** Creates the migrations_log table to track which migrations have been applied
**Impact:** Enables the migration manager to function properly
**Status:** Ready to execute

---

## Recommended Execution Order

1. First: **Execute Migration 023** (consolidate lifecycle stages)
2. Then: **Execute Migration 024** (create migrations log table)

Both are safe to run in sequence, and the system handles re-runs gracefully.

---

## What Changed in clients_appstack.php

**Contact Lifecycle Support:**
- Added AJAX handler for `?action=move_contact`
- Allows updating contact lifecycle stages (same as companies)
- Mirrors the existing company lifecycle functionality

**No UI changes needed yet** - The handler is ready for future contact kanban view

---

## New Files You Can Reference

| File | Purpose |
|------|---------|
| `migrations.php` | PHP functions for migration management |
| `migrations-manager.php` | API endpoints for UI |
| `migrations-manager.js` | Frontend UI logic |
| `MIGRATION_MANAGER_IMPLEMENTATION.md` | Full technical documentation |

---

## Common Questions

**Q: Can I undo a migration?**
A: Not yet. Make a database backup before executing migrations.

**Q: Why did migration 023 fail?**
A: Check the migration history section - click the error to see details. Most common issue is existing data conflicts.

**Q: Can non-admins execute migrations?**
A: No, only admin users can access the migrations manager.

**Q: What if I accidentally run the same migration twice?**
A: It's idempotent - the migration file includes `IF NOT EXISTS` checks, so duplicate runs are safe.

**Q: How do I add a new migration?**
A: Create a new `.sql` file in `/database/migrations/` directory with proper numbering (e.g., `025_your_migration_name.sql`). It will appear in pending list automatically.

---

## Support Information

If something goes wrong:

1. Check **Migration History** for error details
2. Review `migrations_log` table in phpMyAdmin
3. Check server error logs
4. Refer to `MIGRATION_MANAGER_IMPLEMENTATION.md` for troubleshooting section

---

**Your CRM is now ready for automated, auditable database migrations!**
