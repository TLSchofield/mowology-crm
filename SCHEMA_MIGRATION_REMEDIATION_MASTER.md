# Schema & Migration Remediation Master Document

**Analysis Date:** February 10, 2026
**Database:** Mowology CRM (MySQL 5.7+)
**Status:** Schema-Migration Divergence Identified & Analyzed
**Priority:** HIGH - Affects fresh database deployments

---

## Executive Summary

**SCHEMA_MASTER.sql** contains 89 production tables. **Database migrations** only create 62 (70% coverage). This creates a critical gap where:

1. ✅ Running migrations on production works (existing schema is correct)
2. ❌ Fresh database deployments FAIL (missing core tables like `users`, `sessions`, `quotes`)
3. ⚠️  Technical debt accumulates (schema and migrations drift apart)

**Solution:** Create comprehensive remediation migration + updated documentation

---

## The Problem

### Current State (Broken)

```
Fresh Database Deployment Flow:
  1. Run all migrations (001-501) →
  2. Expected: 89 tables created ✅
  3. Actual: Only 62 tables created ❌
  4. Result: DEPLOYMENT FAILS
     - users table doesn't exist (authentication breaks)
     - sessions table doesn't exist (session mgmt breaks)
     - quotes table doesn't exist (core functionality breaks)
```

### Root Cause

1. **SCHEMA_MASTER.sql** is the source of truth (current production DB)
2. **Migrations** were created incrementally and incompletely
3. **No enforcement** that migrations match the schema
4. **Some migrations missing** (27 tables)
5. **Some migrations duplicate** (seo_page_drafts in 2 files)
6. **Some tables newer** than SCHEMA_MASTER (4 CMS tables)

### Impact

| Scenario | Impact |
|----------|--------|
| Production Deployment | ✅ Works (existing schema exists) |
| Fresh Database Setup | ❌ FAILS (missing 27 core tables) |
| CI/CD Test Database | ❌ Broken (can't run full migration suite) |
| New Developer Onboarding | ❌ Blocked (can't create local database) |
| Disaster Recovery | ❌ FAILS (can't rebuild from migrations) |

---

## What's Missing

### Critical (Blocks Functionality)

| Table | Impact | Status |
|-------|--------|--------|
| `users` | Authentication completely broken | NOT IN ANY MIGRATION |
| `sessions` | Session management broken | NOT IN ANY MIGRATION |
| `quotes` | Core quoting system broken | NOT IN ANY MIGRATION |
| `password_reset_tokens` | Password resets broken | NOT IN ANY MIGRATION |

### Feature Gap (Incomplete)

27 tables total missing, including:

**Communications** (3 tables):
- communication_log
- client_feedback
- consent_log

**Analytics** (5 tables):
- conversion_events
- lead_events
- roi_attribution
- seo_recommendations_audit
- media_activity_log

**Products** (4 tables):
- products
- product_categories
- product_cost_breakdown
- unit_types

**Plus 15 more** (see DATABASE_MIGRATION_ANALYSIS.md for complete list)

---

## What's Duplicated

### seo_page_drafts Created Twice

```
File 1: done-100_seo_recommendations.sql
  CREATE TABLE seo_page_drafts (...)

File 2: done-501_marketing_automation_tables.sql (renamed from done-501_cms_marketing.sql)
  CREATE TABLE seo_page_drafts (...)

Problem: Running both fails on second one
Solution: Keep in done-100, remove from done-501
```

---

## What's Out of Sync

### In SCHEMA_MASTER but Not Shown in Migrations

These 4 CMS tables from Phase 3 are in SCHEMA_MASTER.sql but not clearly documented:
- `cms_media_variants` (created in Phase 3 migration 112)
- `cms_media_alt_suggestions` (created in Phase 3 migration 112)
- `cms_page_generations_log` (created in Phase 4 migration 113)
- `cms_case_studies_generated` (created in Phase 5 migration 114)

**Status:** These ARE in migrations, but SCHEMA_MASTER.sql may be outdated

---

## The Solution

### Step 1: Create Comprehensive Migration (Priority 1)

**File:** `030_create_missing_core_tables.sql`

**Contains:** All 27 missing tables with:
- Proper charset: `utf8mb4_general_ci`
- Proper MySQL 5.7 syntax (no IF NOT EXISTS on ALTER)
- Proper relationships and foreign keys
- Proper indexes

**Complete SQL:** See DATABASE_MIGRATION_ANALYSIS.md, section "Complete CREATE TABLE Statements"

### Step 2: Remove Duplicate (Priority 1)

**File:** `501_marketing_automation_tables.sql` (currently named problem-501)

**Action:** Remove the duplicate `CREATE TABLE seo_page_drafts` statement
- Keep the one in: `done-100_seo_recommendations.sql`
- Delete from: `501_marketing_automation_tables.sql`

### Step 3: Update SCHEMA_MASTER.sql (Priority 2)

**Action:** Add these 4 tables if missing (or verify they exist):
- Add cms_media_variants
- Add cms_media_alt_suggestions
- Add cms_page_generations_log
- Add cms_case_studies_generated

**Current Status:** Need to verify in SCHEMA_MASTER.sql line-by-line

### Step 4: Test Migration Suite (Priority 2)

**Procedure:**
```bash
# Fresh MySQL 5.7 database
mysql> DROP DATABASE mowology_test;
mysql> CREATE DATABASE mowology_test;

# Apply all migrations in sequence
mysql mowology_test < database/migrations/001_*.sql
mysql mowology_test < database/migrations/002_*.sql
... (all through 501)

# Verify results
mysql mowology_test -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='mowology_test';"
# Should output: 89
```

### Step 5: Document Execution Order (Priority 3)

**Create:** `MIGRATION_EXECUTION_ORDER.md`

**Contains:**
- Correct sequence to run all 35 migration files
- Dependencies between migrations
- Known issues and workarounds
- Testing checklist

---

## Detailed Remediation Steps

### Create 030_create_missing_core_tables.sql

**Step 1:** Copy CREATE TABLE statements from DATABASE_MIGRATION_ANALYSIS.md
**Step 2:** Place in: `/database/migrations/030_create_missing_core_tables.sql`
**Step 3:** Test syntax on MySQL 5.7 locally
**Step 4:** Verify file with: `mysql --no-data -e "SOURCE 030_create_missing_core_tables.sql"`

**Estimated Time:** 15 minutes

### Fix Duplicate seo_page_drafts

**Step 1:** Open: `database/migrations/501_marketing_automation_tables.sql`
**Step 2:** Find: Lines with `CREATE TABLE seo_page_drafts` (around line 60-100)
**Step 3:** Delete entire CREATE TABLE block for seo_page_drafts
**Step 4:** Keep everything else in the file intact
**Step 5:** Verify: File should not contain any `CREATE TABLE seo_page_drafts` statement

**Estimated Time:** 5 minutes

### Update SCHEMA_MASTER.sql

**Step 1:** Extract from migrations 112, 113, 114:
```bash
grep -A 30 "CREATE TABLE cms_media_variants" database/migrations/*.sql
grep -A 30 "CREATE TABLE cms_media_alt_suggestions" database/migrations/*.sql
grep -A 30 "CREATE TABLE cms_page_generations_log" database/migrations/*.sql
grep -A 30 "CREATE TABLE cms_case_studies_generated" database/migrations/*.sql
```

**Step 2:** Check if these exist in SCHEMA_MASTER.sql:
```bash
grep "CREATE TABLE \`cms_media_variants\`" database/SCHEMA_MASTER.sql
```

**Step 3:** If missing, add them to SCHEMA_MASTER.sql in appropriate sections

**Estimated Time:** 10 minutes

### Test Full Migration Suite

**Step 1:** Create test database:
```sql
DROP DATABASE IF EXISTS mowology_test;
CREATE DATABASE mowology_test;
```

**Step 2:** Apply each migration:
```bash
for file in database/migrations/*.sql; do
  echo "Applying $file..."
  mysql mowology_test < "$file"
done
```

**Step 3:** Verify count:
```sql
SELECT COUNT(*) as table_count FROM information_schema.TABLES
WHERE TABLE_SCHEMA='mowology_test';
-- Should return: 89
```

**Step 4:** Check for errors:
```sql
SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA='mowology_test'
ORDER BY TABLE_NAME;
-- Cross-reference against MASTER_SCHEMA_TABLES.txt
```

**Estimated Time:** 30 minutes (including debug if needed)

---

## Complete Task Checklist

### Before Starting
- [ ] Read IMMEDIATE_ACTIONS.md (5 min)
- [ ] Read DATABASE_MIGRATION_ANALYSIS.md sections 1-3 (10 min)
- [ ] Backup current SCHEMA_MASTER.sql
- [ ] Backup entire migrations folder

### Execution
- [ ] Create 030_create_missing_core_tables.sql (15 min)
- [ ] Test new migration syntax locally (10 min)
- [ ] Fix duplicate seo_page_drafts in 501 (5 min)
- [ ] Update SCHEMA_MASTER.sql if needed (10 min)
- [ ] Run full test on fresh database (30 min)
- [ ] Verify all 89 tables created (5 min)
- [ ] Document findings and lessons learned (15 min)

### Documentation
- [ ] Create MIGRATION_EXECUTION_ORDER.md
- [ ] Add to START_HERE.md: "Running migrations from scratch"
- [ ] Update deployment guide with remediation steps
- [ ] Create post-fix validation checklist

**Total Time:** 1.5-2 hours

---

## Files Generated by Analysis

### Primary Analysis Documents

1. **DATABASE_MIGRATION_ANALYSIS.md** (35 KB)
   - Complete missing table analysis
   - Full CREATE TABLE SQL for all 27 missing tables
   - Detailed recommendations
   - Complete migration inventory with status

2. **IMMEDIATE_ACTIONS.md** (7 KB)
   - Step-by-step fixes to implement
   - Complete SQL code snippets
   - Time estimates for each action

3. **MASTER_SCHEMA_TABLES.txt** (13 KB)
   - Table-by-table status matrix
   - Which migration creates each table
   - Dependencies and execution order
   - Complete 89-table inventory

4. **MIGRATION_GAP_SUMMARY.txt** (9 KB)
   - Executive quick reference
   - Missing tables by category
   - Redundant migrations identified
   - Pre-flight deployment checklist

---

## Prevention for Future

### Policy Changes

1. **Enforce Migration Completeness**
   - Every new table must have a migration
   - Every schema change must have a migration
   - SCHEMA_MASTER.sql updated quarterly

2. **Validation Testing**
   - Add CI/CD step: "Fresh database from migrations"
   - Run monthly on test: `mysql < all_migrations.sql`
   - Verify table count = 89
   - Fail if doesn't match

3. **Documentation Standards**
   - Each migration file must list tables created/modified
   - Migration numbering: 0xx (core), 1xx (phase), 2xx (features)
   - README.md in migrations/ directory with execution order

### Process Improvements

1. **Before Merging Code**
   - Require migrations if schema changed
   - Check SCHEMA_MASTER.sql updated
   - Validate MySQL 5.7 compatibility

2. **In Deployment Checklist**
   - Test: Run migrations on fresh database
   - Verify: All 89 tables created
   - Document: Which tables used

3. **In Code Review**
   - Ask: "Does this need a migration?"
   - Check: MySQL 5.7 syntax used
   - Verify: Collation is utf8mb4_general_ci

---

## Success Criteria

After remediation, the following should be true:

- [ ] All 89 tables created by running migrations from scratch
- [ ] Fresh database deployments complete without errors
- [ ] No duplicate table creation statements
- [ ] All migrations use MySQL 5.7 compatible syntax
- [ ] SCHEMA_MASTER.sql matches migration output exactly
- [ ] ci/cd can run full migration suite automatically
- [ ] New developer can create local DB with: `mysql < migrations/*`
- [ ] Disaster recovery procedure uses migrations (not SCHEMA_MASTER.sql)

---

## Timeline

| Phase | Action | Time | Status |
|-------|--------|------|--------|
| Analysis | Complete (you are here) | Done | ✅ |
| Remediation | Create migration + fix duplicate | 1-2 hrs | 📋 TO-DO |
| Testing | Fresh database test | 30 min | 📋 TO-DO |
| Deployment | Test on staging | 1 hr | 📋 TO-DO |
| Documentation | Update guides | 30 min | 📋 TO-DO |
| Prevention | Implement policy changes | 1 hr | 📋 TO-DO |

**Total Project Time:** ~4-5 hours

---

## Next Step

👉 **Read IMMEDIATE_ACTIONS.md**

It contains:
- Exact SQL to copy and paste
- File locations
- Validation steps
- Testing procedures

All the information you need to execute the remediation in 1-2 hours.

---

**Analysis Complete: 2026-02-10 23:47**
**Prepared For:** Mowology CRM Production Deployment
**Impact:** CRITICAL - Enables fresh database deployments
