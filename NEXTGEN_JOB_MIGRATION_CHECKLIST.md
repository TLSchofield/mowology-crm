# Next-Gen Job Creation — Migration Deployment Checklist

**Deployment Date:** [To be scheduled]
**Environment:** [Staging first, then Production]
**Owner:** [DevOps / Database Admin]

---

## Pre-Deployment Checks

### Database Health
- [ ] MySQL 5.7+ running and accessible
- [ ] Database: `mowology_landscape_crm` exists and is accessible
- [ ] Admin user has ALTER TABLE, CREATE TABLE, INSERT permissions
- [ ] Current database size noted (for rollback planning)
- [ ] Recent backup exists and is verified (critical!)

### Application State
- [ ] All users logged out of CRM (no active sessions during migration)
- [ ] No jobs being created/edited during migration
- [ ] No invoice generation running
- [ ] Background tasks paused (if any)

### Staging Environment
- [ ] Fresh backup of production database restored to staging
- [ ] All 4 migrations copied to `database/migrations/` directory
- [ ] Migration runner script updated (if using custom runner)
- [ ] Settings > Database/Migrations UI available for testing

---

## Migration Execution (Staging First)

### Step 1: Run Migration 025 (Service Packages)

```bash
# Via CLI (if using migration runner):
php /public/crm/api/migrations-manager.php action=execute migration=025_create_service_packages.sql

# OR via web UI:
# Login to CRM → Settings → Database / Migrations
# Click "Execute" on: 025_create_service_packages.sql
```

**Verification:**
- [ ] Migration completes successfully (status = "success")
- [ ] `service_packages` table created
- [ ] 8 default packages seeded:
  - [ ] Lawn Mowing Standard
  - [ ] Lawn Mowing Large
  - [ ] Hedge Trim Light
  - [ ] Hedge Trim Heavy
  - [ ] Spring Cleanup
  - [ ] Garden Maintenance
  - [ ] Snow Removal Per Visit
  - [ ] Snow Removal Seasonal

**Check in database:**
```sql
SELECT COUNT(*) FROM service_packages;
-- Should return: 8

SELECT * FROM service_packages LIMIT 1;
-- Should show package with all columns populated
```

**Rollback (if needed):**
```sql
DROP TABLE service_packages;
```

---

### Step 2: Run Migration 026 (Billing Templates)

```bash
php /public/crm/api/migrations-manager.php action=execute migration=026_create_billing_templates.sql

# OR via web UI: Click "Execute" on migration 026
```

**Verification:**
- [ ] Migration completes successfully
- [ ] `billing_templates` table created
- [ ] 4 default templates seeded:
  - [ ] Per Visit
  - [ ] Monthly Grouped
  - [ ] Monthly Flat
  - [ ] Seasonal Prepay

**Check in database:**
```sql
SELECT COUNT(*) FROM billing_templates;
-- Should return: 4

SELECT * FROM billing_templates WHERE is_default = TRUE;
-- Should show "Per Visit" as default
```

**Rollback (if needed):**
```sql
DROP TABLE billing_templates;
```

---

### Step 3: Run Migration 027 (Proof of Work)

```bash
php /public/crm/api/migrations-manager.php action=execute migration=027_create_job_proof_of_work.sql

# OR via web UI: Click "Execute" on migration 027
```

**Verification:**
- [ ] Migration completes successfully
- [ ] `job_proof_of_work` table created with correct schema
- [ ] Table has unique constraint on job_id

**Check in database:**
```sql
DESCRIBE job_proof_of_work;
-- Should show all columns:
--   id, job_id, required_checklist_items, required_photo_types, etc.

SHOW INDEXES FROM job_proof_of_work;
-- Should show UNIQUE index on job_id
```

**Rollback (if needed):**
```sql
DROP TABLE job_proof_of_work;
```

---

### Step 4: Run Migration 028 (Update Jobs Table)

```bash
php /public/crm/api/migrations-manager.php action=execute migration=028_update_jobs_for_service_packages.sql

# OR via web UI: Click "Execute" on migration 028
```

**Verification:**
- [ ] Migration completes successfully
- [ ] `jobs` table has new columns:
  - [ ] service_package_id (INT, nullable)
  - [ ] billing_template_id (INT, nullable)
  - [ ] crew_size_required (INT, default 1)
  - [ ] actual_crew_count (INT, nullable)
  - [ ] route_sequence (INT, nullable)

**Check in database:**
```sql
DESCRIBE jobs;
-- Should show new columns in output

SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME='jobs' AND TABLE_SCHEMA='mowology_landscape_crm'
ORDER BY ORDINAL_POSITION;
-- Should include: service_package_id, billing_template_id, crew_size_required, actual_crew_count, route_sequence

SHOW CREATE TABLE jobs\G
-- Should show foreign key constraints:
--   CONSTRAINT fk_jobs_service_package FOREIGN KEY (service_package_id)
--   CONSTRAINT fk_jobs_billing_template FOREIGN KEY (billing_template_id)
```

**Rollback (if needed):**
```sql
-- Note: Rollback requires data migration
-- Can't simply drop columns if they contain data
-- Better to restore from backup if this fails
ALTER TABLE jobs DROP FOREIGN KEY fk_jobs_service_package;
ALTER TABLE jobs DROP FOREIGN KEY fk_jobs_billing_template;
ALTER TABLE jobs DROP COLUMN service_package_id;
ALTER TABLE jobs DROP COLUMN billing_template_id;
ALTER TABLE jobs DROP COLUMN crew_size_required;
ALTER TABLE jobs DROP COLUMN actual_crew_count;
ALTER TABLE jobs DROP COLUMN route_sequence;
```

---

## Post-Migration Verification (Staging)

### Data Integrity
- [ ] Existing jobs still load without errors
- [ ] Existing invoices still display correctly
- [ ] Crew assignments unchanged
- [ ] Job history intact

**Test queries:**
```sql
-- Existing jobs should still work
SELECT COUNT(*) FROM jobs;
-- Should match pre-migration count

-- New columns are nullable (no data loss)
SELECT * FROM jobs LIMIT 1;
-- Should show NULL in new columns (expected)

-- Service packages accessible
SELECT sp.id, sp.package_name, j.id
FROM service_packages sp
LEFT JOIN jobs j ON sp.id = j.service_package_id
LIMIT 5;
-- Should show packages (no jobs linked yet)

-- Billing templates accessible
SELECT bt.id, bt.template_name, COUNT(j.id) as job_count
FROM billing_templates bt
LEFT JOIN jobs j ON bt.id = j.billing_template_id
GROUP BY bt.id;
-- Should show templates with 0 jobs (not yet linked)
```

### Application Functionality
- [ ] Jobs list page loads (no SQL errors)
- [ ] Job detail page loads
- [ ] Invoice list page loads
- [ ] Settings page loads
- [ ] No console errors in browser DevTools

### CRM UI Tests
- [ ] Navigate to Settings → Database / Migrations
- [ ] Migration history shows all 4 migrations as "success"
- [ ] No error messages in interface

---

## Production Deployment

### Pre-Production
- [ ] All staging tests passed
- [ ] Database backup taken (CRITICAL)
- [ ] Backup verified and restorable
- [ ] Maintenance window scheduled (30 min)
- [ ] Team notified of maintenance window

### During Deployment (Production)
- [ ] Set maintenance page (if available)
- [ ] Stop all background jobs
- [ ] Repeat all 4 migrations on production (same order)
- [ ] Run all verification checks from staging
- [ ] Run any additional production-specific tests

### Post-Deployment (Production)
- [ ] Resume background jobs
- [ ] Remove maintenance page
- [ ] Monitor for errors (check logs)
- [ ] Verify users can create jobs (manual test)
- [ ] Verify Settings > Database/Migrations shows all green ✓
- [ ] Send "deployment complete" notification to team

---

## Rollback Plan

### If Migration Fails During Execution

**Option 1: Single Migration Rollback (if not progressed)**

If migration 025 fails:
```sql
DROP TABLE IF EXISTS service_packages;
-- Verify: SELECT * FROM migrations_log WHERE migration_filename LIKE '025%';
-- Delete failed entry if needed
```

If migration 026 fails (025 succeeded):
```sql
DROP TABLE IF EXISTS billing_templates;
```

If migration 027 fails (025-026 succeeded):
```sql
DROP TABLE IF EXISTS job_proof_of_work;
```

If migration 028 fails (025-027 succeeded):
```sql
-- Rollback more complex (removes added columns)
ALTER TABLE jobs DROP FOREIGN KEY fk_jobs_service_package;
ALTER TABLE jobs DROP FOREIGN KEY fk_jobs_billing_template;
ALTER TABLE jobs DROP COLUMN service_package_id;
ALTER TABLE jobs DROP COLUMN billing_template_id;
ALTER TABLE jobs DROP COLUMN crew_size_required;
ALTER TABLE jobs DROP COLUMN actual_crew_count;
ALTER TABLE jobs DROP COLUMN route_sequence;
```

**Option 2: Complete Rollback (safest)**

```bash
# Restore from pre-migration backup
mysql -u [user] -p [database] < /path/to/backup.sql

# Verify database structure restored
SHOW TABLES;
DESCRIBE jobs;
```

### Recovery Steps

1. [ ] Restore from backup (if Option 2)
2. [ ] Verify restored data integrity
3. [ ] Contact support for diagnosis
4. [ ] Schedule retry with fixes
5. [ ] Document issue in migration log

---

## Migration Monitoring

### During Execution
- [ ] Watch migrations_log table in real-time
- [ ] Monitor database CPU/memory
- [ ] Check for locks or long-running queries

**Monitor command:**
```sql
WATCH -n 1 "SELECT * FROM migrations_log WHERE created_at > NOW() - INTERVAL 5 MINUTE;"
```

### After Completion
- [ ] Check error logs (MySQL and application)
- [ ] Monitor job creation (test 3 scenarios)
- [ ] Monitor invoice generation (if any jobs complete)
- [ ] Check CRM UI for errors (DevTools console)

---

## Sign-Off

### Staging Deployment
- [ ] All migrations executed successfully
- [ ] All verifications passed
- [ ] QA approved (no issues)

Signed by: _________________________ Date: _________

### Production Deployment
- [ ] All migrations executed successfully
- [ ] All verifications passed
- [ ] Users notified
- [ ] Monitoring active

Signed by: _________________________ Date: _________

---

## Troubleshooting

### Error: "Cannot create table; database full"
**Solution:** Clear old backups, expand storage, reduce backup retention

### Error: "Foreign key constraint fails"
**Solution:** Check that billing_templates and service_packages tables created successfully before running migration 028

### Error: "Duplicate column name"
**Solution:** Migration 028 columns may already exist (check DESCRIBE jobs); skip if present

### Error: "Syntax error in SQL"
**Solution:** Ensure migration file is UTF-8 encoded, no special characters in comments

### Jobs table slow after migration
**Solution:** Run `ANALYZE TABLE jobs;` to update statistics

---

## Post-Deployment Documentation

After successful deployment, document:

- [ ] Deployment date and time
- [ ] Executed migrations and execution times
- [ ] Any issues encountered and resolved
- [ ] Rollback action (none / partial / full)
- [ ] Team members involved
- [ ] Performance impact observed
- [ ] Any follow-up tasks

---

## Success Criteria

Deployment is **SUCCESSFUL** if:

✅ All 4 migrations execute without error
✅ Service packages table has 8 rows (pre-seeded data)
✅ Billing templates table has 4 rows (pre-seeded data)
✅ Proof of work table is created and empty
✅ Jobs table has 5 new columns, all nullable
✅ Existing jobs still load and display correctly
✅ No application errors in UI or logs
✅ Users can navigate CRM normally
✅ Settings > Database/Migrations shows all green ✓

---

**Deployment Status: READY FOR EXECUTION** ✅

