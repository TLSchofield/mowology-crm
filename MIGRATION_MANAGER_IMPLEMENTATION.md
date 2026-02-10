# Mowology CRM: Database Migration Manager & Lifecycle Refactoring

**Date:** February 8, 2026
**Status:** Implementation Complete - Ready for Testing

---

## Overview

This implementation consolidates lifecycle stage management into a single shared table and creates a comprehensive PHP-based database migration manager integrated into the CRM's Settings page.

## What Was Built

### 1. Lifecycle Stage Refactoring

#### Migration 023: Consolidate Lifecycle Stages
**File:** `/database/migrations/023_consolidate_lifecycle_stages.sql`

**Changes:**
- Adds `entity_type` column to `lifecycle_stages` table (contact, company, or both)
- Converts `contacts.lifecycle_stage` from ENUM to VARCHAR(50)
- Adds foreign key constraint from `contacts.lifecycle_stage` → `lifecycle_stages.stage_key`
- Ensures `companies.lifecycle_stage` also has FK to `lifecycle_stages.stage_key`
- Syncs existing data and validates referential integrity
- Creates indexes for fast lifecycle lookups

**Result:** Both contacts and companies now share the same centralized lifecycle stages table, improving data consistency and maintenance.

### 2. Migration Tracking System

#### Migration 024: Create Migrations Log Table
**File:** `/database/migrations/024_create_migrations_log.sql`

**Schema:**
```sql
migrations_log (
  id INT (PK),
  migration_filename VARCHAR(255) UNIQUE,
  executed_at TIMESTAMP,
  executed_by INT (FK to users),
  status ENUM('success', 'failed', 'rollback'),
  error_message TEXT,
  migration_type ENUM('sql', 'php'),
  checksum VARCHAR(64),  -- MD5 of file
  notes TEXT
)
```

**Features:**
- Tracks which migrations have been applied
- Records who executed each migration and when
- Stores error messages from failed migrations
- Includes MD5 checksums to detect file modifications
- Provides audit trail for schema changes

### 3. PHP-Based Migration Execution Framework

#### New File: `/public/crm/includes/migrations.php`

**Functions provided:**
- `getMigrationsDirectory()` - Get migrations folder path
- `readMigrationFile($filename)` - Load .sql file safely with path traversal protection
- `parseMigrationMetadata($content)` - Extract title/date/purpose from comment block
- `getMigrationChecksum($content)` - MD5 hash for integrity checking
- `logMigrationExecution()` - Record to migrations_log table
- `isMigrationApplied($filename)` - Check if already executed
- `executeMigration($filename, $userId)` - Main execution function
- `getMigrationsPending()` - List files not yet applied
- `getMigrationsApplied()` - List applied migrations
- `getMigrationsByStatus($status)` - Filter history by status
- `getMigrationDetails($filename)` - Get full metadata for a migration

**Security:**
- Validates filename format (alphanumeric, underscores, hyphens only)
- Prevents path traversal attacks
- Logs all executions with user ID
- Checks migration files are readable before executing

### 4. Migration Manager API

#### New File: `/public/crm/api/migrations-manager.php`

**Endpoints:**
- `POST /api/migrations-manager.php?action=list` - List pending + applied migrations
- `POST /api/migrations-manager.php?action=execute` - Execute single migration
- `POST /api/migrations-manager.php?action=history` - Get migration history with filtering
- `POST /api/migrations-manager.php?action=verify` - Check database health

**Security:**
- Admin-only access (role check)
- CSRF token validation on all endpoints
- Filename validation against whitelist
- All changes logged with user_id and timestamp

**Responses:**
All endpoints return JSON with:
```json
{
  "success": true|false,
  "message": "...",
  "error": "...",
  "data": { /* endpoint-specific */ }
}
```

### 5. Settings Page Integration

#### Modified File: `/public/crm/settings.php`

**New "Database / Migrations" Tab** with three sections:

1. **Database Health**
   - Shows database name and MySQL version
   - Overall health status badge (ok/issues)
   - Table existence checks

2. **Pending Migrations**
   - Displays all unapplied migrations as cards
   - Shows filename, title, purpose, creation date
   - "Execute" button per migration
   - Shows total pending count
   - Notification when all migrations are applied

3. **Migration History**
   - Sortable table of all executed migrations
   - Filter by status (all, success, failed)
   - Shows executed by (user name), timestamp, status
   - Expandable error messages for failed migrations
   - Scrollable with up to 500 records

### 6. Frontend Components

#### New File: `/public/crm/js/migrations-manager.js`

**Features:**
- Auto-loads data when Database tab is clicked
- Real-time migration execution with confirmation dialog
- Spinner feedback during operations
- Success/error alerts with auto-dismiss
- History filtering by status
- Responsive table design

**Endpoints:**
All AJAX calls include CSRF token and require POST method.

### 7. Styling

#### Updated File: `/public/crm/css/mowology-brand.css`

**New classes:**
- `.mw-migration-card` - Pending migration card styling
- `.mw-migration-card.border-warning` - Warning color for pending migrations
- `.mw-migration-card:hover` - Hover effect with shadow and lift
- `.mw-settings-nav` - Settings tab navigation styling
- `.mw-settings-nav .nav-link` - Individual tab link styling

**Design:**
- Consistent with Mowology brand colors (green/dark)
- Uses existing CSS variables (--mw-green, --mw-dark, --mw-light)
- Responsive on mobile devices
- Smooth transitions and hover effects

### 8. Contact Lifecycle Functions

#### Updated File: `/public/crm/includes/functions.php`

**New functions:**
- `getLifecycleStages($entityType = 'both')` - Get stages filtered by entity type
- `getContactLifecycleStage($contactId)` - Get contact's current stage
- `updateContactLifecycleStage($contactId, $newStage, $userId)` - Update contact stage

**Existing functions (unchanged):**
- `updateCompanyLifecycleStage()` - Still works as before
- `addLifecycleStage()` - Add new stage definition
- `updateLifecycleStage()` - Modify stage properties
- `deleteLifecycleStage()` - Remove unused stage

### 9. API Handler Updates

#### Updated File: `/public/crm/clients_appstack.php`

**New AJAX handler:** `?action=move_contact`
- Mirrors the existing `move_company` handler
- Updates contact lifecycle_stage via AJAX
- Validates stage against lifecycle_stages table
- Logs activity to activity_log table
- Returns JSON success/error

---

## How to Use

### Executing Migrations via the UI

1. **Log in as admin** to the CRM
2. **Navigate to Settings** (sidebar icon)
3. **Click "Database / Migrations" tab**
4. **Review pending migrations** in the cards section
5. **Click "Execute"** on a migration
6. **Confirm** the execution in the confirmation dialog
7. **Watch** for success/error message
8. **Refresh** to see updated lists (auto-updates)

### Executing Migrations Programmatically

```php
require_once __DIR__ . '/includes/migrations.php';

$result = executeMigration('023_consolidate_lifecycle_stages.sql', $userId);

if ($result['success']) {
    echo "Migration applied: " . $result['message'];
} else {
    echo "Error: " . $result['error'];
}
```

### Checking Migration Status

```php
require_once __DIR__ . '/includes/migrations.php';

// Get pending
$pending = getMigrationsPending();
echo "Pending: " . count($pending);

// Get applied
$applied = getMigrationsApplied();
echo "Applied: " . count($applied);

// Check specific migration
if (isMigrationApplied('023_consolidate_lifecycle_stages.sql')) {
    echo "Already applied";
}
```

---

## Test Plan

### Prerequisites
- Access to admin account
- MySQL client access (for manual verification)
- Recent database backup

### Step 1: Verify Initial State
```bash
# Check current lifecycle_stage columns
SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME='contacts' AND COLUMN_NAME='lifecycle_stage';
# Should show: ENUM('lead','opportunity','client','won','lost')

SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME='companies' AND COLUMN_NAME='lifecycle_stage';
# Should show: VARCHAR(50)
```

### Step 2: Run Migrations via UI
1. Navigate to Settings > Database / Migrations
2. Verify you see migration 023 and 024 in pending list
3. Click "Execute" on migration 023
4. Confirm the dialog
5. Verify success message appears
6. Check that migration appears in history with "Success" badge

### Step 3: Execute Migration 024
1. Click "Execute" on migration 024
2. Verify success
3. Confirm `migrations_log` table now exists

### Step 4: Verify Database State
```bash
# Check new migration 023 results
SELECT COUNT(*) FROM lifecycle_stages;
# Should show: 8 stages (4 contact + 4 company stages)

SELECT entity_type, COUNT(*) as count
FROM lifecycle_stages
WHERE is_active=1
GROUP BY entity_type;
# Should show: both (8 rows with entity_type='both')

# Verify foreign keys exist
SELECT CONSTRAINT_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_NAME IN ('contacts','companies') AND COLUMN_NAME='lifecycle_stage';
# Should show: fk_contacts_lifecycle_stage, fk_companies_lifecycle_stage

# Check migrations_log table
SELECT COUNT(*) FROM migrations_log;
# Should show: 2 (migration 023 and 024)

SELECT * FROM migrations_log ORDER BY executed_at DESC;
# Should show both migrations with status='success'
```

### Step 5: Test Contact Lifecycle Functions
```php
// In any CRM page after running migrations
require_once __DIR__ . '/includes/functions.php';

// Get all stages
$stages = getLifecycleStages('contact');
// Should return: lead, opportunity, won, lost

// Get contact stage
$stage = getContactLifecycleStage(1);
// Should return: 'lead' or other valid stage_key

// Update contact stage
$success = updateContactLifecycleStage(1, 'opportunity', 1);
// Should return: true

// Verify database
SELECT lifecycle_stage FROM contacts WHERE id=1;
# Should show: 'opportunity'
```

### Step 6: Test AJAX Handlers
Use browser DevTools to make requests:

```javascript
// Test move_contact handler
fetch('/crm/clients_appstack.php?action=move_contact', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({
    csrf_token: 'GET_FROM_PAGE',
    contact_id: 1,
    new_stage: 'won'
  })
})
.then(r => r.json())
.then(d => console.log(d));
// Should return: {success: true}

// Test move_company handler (existing)
fetch('/crm/clients_appstack.php?action=move_company', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({
    csrf_token: 'GET_FROM_PAGE',
    company_id: 1,
    new_stage: 'qualified'
  })
})
.then(r => r.json())
.then(d => console.log(d));
// Should return: {success: true}
```

### Step 7: Test Migration Manager API
```javascript
// Get list of pending migrations
fetch('/crm/api/migrations-manager.php?action=list', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({csrf_token: 'GET_FROM_PAGE'})
})
.then(r => r.json())
.then(d => console.log(d.pending));
// After all migrations applied, should return empty array

// Get history
fetch('/crm/api/migrations-manager.php?action=history', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({
    csrf_token: 'GET_FROM_PAGE',
    status: 'success'
  })
})
.then(r => r.json())
.then(d => console.log(d.history));
// Should show migration 023 and 024 records

// Verify database health
fetch('/crm/api/migrations-manager.php?action=verify', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({csrf_token: 'GET_FROM_PAGE'})
})
.then(r => r.json())
.then(d => console.log(d.health));
// Should return: 'ok'
```

### Step 8: Test Clients Page
1. Navigate to Clients page
2. View companies with lifecycle stages (kanban should work)
3. Drag a company to a different stage
4. Verify it updates via AJAX
5. Refresh page to confirm persistence

### Step 9: Test Security
- Test as non-admin user → should get 403 error
- Test with invalid CSRF token → should get 400 error
- Test with invalid migration filename (path traversal) → should reject
- Test with malformed API request → should return error

### Step 10: Check Error Handling
1. Intentionally create a migration with syntax error in `/database/migrations/`
2. Try to execute it via the UI
3. Verify error message appears and is stored in migrations_log
4. Verify status shows "failed" in history

---

## File Manifest

### New Files Created
```
/database/migrations/023_consolidate_lifecycle_stages.sql
/database/migrations/024_create_migrations_log.sql
/public/crm/includes/migrations.php
/public/crm/api/migrations-manager.php
/public/crm/js/migrations-manager.js
```

### Files Modified
```
/public/crm/includes/functions.php (added 3 new functions)
/public/crm/settings.php (added migrations tab)
/public/crm/css/mowology-brand.css (added 30+ lines of styling)
/public/crm/clients_appstack.php (added move_contact AJAX handler)
```

---

## Database Schema Changes

### Lifecycle Stages Table (Enhanced)
```sql
ALTER TABLE lifecycle_stages
ADD COLUMN entity_type ENUM('contact', 'company', 'both') DEFAULT 'both';

-- Create foreign keys
ALTER TABLE contacts
MODIFY COLUMN lifecycle_stage VARCHAR(50) NOT NULL,
ADD CONSTRAINT fk_contacts_lifecycle_stage
FOREIGN KEY (lifecycle_stage) REFERENCES lifecycle_stages(stage_key);

ALTER TABLE companies
MODIFY COLUMN lifecycle_stage VARCHAR(50) NOT NULL,
ADD CONSTRAINT fk_companies_lifecycle_stage
FOREIGN KEY (lifecycle_stage) REFERENCES lifecycle_stages(stage_key);
```

### Default Lifecycle Stages (After Migration 023)
- lead (contact)
- opportunity (contact)
- won (contact)
- lost (contact)
- prospect (company)
- qualified (company)
- client (company)
- inactive (company)

All with entity_type='both' for maximum flexibility.

---

## API Response Examples

### List Migrations Response
```json
{
  "success": true,
  "pending": [
    {
      "filename": "025_future_migration.sql",
      "title": "Future Migration",
      "date": "2026-02-09",
      "purpose": "Something to do later",
      "applied": false,
      "created_at": "2026-02-09 10:00:00",
      "file_size": 1024
    }
  ],
  "applied_count": 24,
  "pending_count": 1,
  "total_count": 25
}
```

### Execute Migration Response
```json
{
  "success": true,
  "message": "Migration executed successfully: 025_future_migration.sql",
  "error": null
}
```

Or on failure:
```json
{
  "success": false,
  "message": "Migration failed",
  "error": "Syntax error in SQL: ..."
}
```

### History Response
```json
{
  "success": true,
  "status": "success",
  "count": 24,
  "history": [
    {
      "migration_filename": "024_create_migrations_log.sql",
      "executed_at": "2026-02-08 15:32:14",
      "executed_by": 1,
      "executed_by_name": "Admin User",
      "status": "success",
      "error_message": null
    }
  ]
}
```

### Verify Response
```json
{
  "success": true,
  "database": "mowology_landscape_crm",
  "mysql_version": "5.7.35-39-log",
  "tables": {
    "users": {"exists": true, "status": "ok"},
    "companies": {"exists": true, "status": "ok"},
    "contacts": {"exists": true, "status": "ok"},
    "lifecycle_stages": {"exists": true, "status": "ok"},
    "migrations_log": {"exists": true, "status": "ok"}
  },
  "migrations_applied": 24,
  "health": "ok"
}
```

---

## Future Enhancements (Out of Scope)

1. **Dry-run Preview** - Show SQL that would be executed without committing
2. **Batch Execution** - Run all pending migrations at once
3. **Rollback Capability** - Reverse previously applied migrations
4. **Dependency Tracking** - Ensure migrations run in correct order
5. **Automatic Backups** - Create DB snapshot before executing migration
6. **Schema Drift Detection** - Compare actual schema vs expected
7. **Migration Scheduling** - Schedule migrations to run at specific times
8. **Email Notifications** - Alert admins when migrations fail

---

## Troubleshooting

### Migration Fails to Execute
1. Check MySQL error in migrations_log table
2. Verify database user has ALTER TABLE permissions
3. Check if table/column already exists (check idempotency)
4. Review migration SQL syntax

### Permissions Denied Error
- Verify you're logged in as admin
- Check user.role = 'admin' in database
- Clear browser cache and re-login

### CSRF Token Errors
- Page cache may be stale
- Refresh the page
- Check that forms include csrf_token field

### Foreign Key Constraint Errors
- Ensure lifecycle_stages table has all expected stage_key values
- Check for orphaned records in contacts or companies
- Migration 023 includes data validation to prevent this

### Slow Migration Execution
- Large tables (quotes, jobs) may take time
- Check MySQL slow query log
- Monitor disk space during execution
- Consider running during off-hours

---

## Security Notes

1. **Path Traversal Protection:** Filenames are validated to only allow `[a-zA-Z0-9_\-\.]`
2. **SQL Injection Prevention:** All user input uses prepared statements
3. **Access Control:** Admin role required for all migration operations
4. **CSRF Protection:** All endpoints require valid CSRF token
5. **Audit Trail:** Every migration execution logged with user ID and timestamp
6. **Error Messages:** Detailed errors logged, generic messages shown to users

---

## Documentation Links

- **CLAUDE.md** - Project conventions and architecture rules
- **ARCHITECTURE.md** - System design and data model
- **DATABASE_SETUP_GUIDE.md** - Initial database setup instructions
- **README_IMPLEMENTATION.md** - Implementation status document

---

**End of Implementation Document**

Questions? Check the API endpoint responses and migrations_log table for detailed error information.
