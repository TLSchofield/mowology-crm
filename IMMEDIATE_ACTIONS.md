# Mowology CRM: Immediate Actions Required

**Status:** Critical Schema-Migration Gap Detected
**Date:** February 10, 2026
**Severity:** CRITICAL - Database migrations incomplete

---

## What's the Problem?

Your `SCHEMA_MASTER.sql` contains 89 tables, but your migration system only creates 66 tables (69.7% coverage). **27 critical tables are missing from migrations**, including:

- **users** — Authentication table (BLOCKER)
- **sessions** — Session management (BLOCKER)
- **quotes** — Quote management (BLOCKER, but ALTER statements reference it!)

If you run migrations on a fresh database, **it will be incomplete and non-functional**.

---

## Action 1: Fix Duplicate seo_page_drafts (5 minutes)

**File:** `/Users/timschofield/Projects/mowology-crm/database/migrations/problem-501_marketing_automation_tables.sql`

**Problem:** `seo_page_drafts` is created in BOTH:
- `done-100_seo_recommendations.sql`
- `problem-501_marketing_automation_tables.sql`

**Fix:**

```diff
diff --git a/database/migrations/problem-501_marketing_automation_tables.sql b/database/migrations/problem-501_marketing_automation_tables.sql
index ...
--- a/database/migrations/problem-501_marketing_automation_tables.sql
+++ b/database/migrations/problem-501_marketing_automation_tables.sql
@@ -X,XX +X,XX @@
-CREATE TABLE IF NOT EXISTS `seo_page_drafts` (
-  `id` int UNSIGNED PRIMARY KEY AUTO_INCREMENT,
-  `page_slug` varchar(100) NOT NULL,
-  `generated_content` longtext,
-  `status` enum('draft','approved','published') DEFAULT 'draft',
-  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
-  INDEX idx_status (status)
-) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
-
CREATE TABLE IF NOT EXISTS `seo_recommendation_status_history` (
  ...
```

**Action:** Remove the `CREATE TABLE seo_page_drafts` block from this file (keep any ALTER TABLE statements).

---

## Action 2: Create Migration for Missing Core Tables (30 minutes)

**File to create:** `/Users/timschofield/Projects/mowology-crm/database/migrations/030_create_missing_core_tables.sql`

This migration creates all 27 missing tables. The detailed SQL is in `DATABASE_MIGRATION_ANALYSIS.md` (search for "### Foundation Tables (CRITICAL)").

**Key tables to prioritize** (must come FIRST in migration 030):

1. `users` — Authentication (referenced by activity_log FK)
2. `sessions` — Session storage
3. `quotes` — Referenced by 4 ALTER migrations (008, 010, 001_add_roi, 007)
4. `unit_types` — Referenced by products and templates
5. `products` — Product catalog
6. `product_categories` — Product taxonomy
7. All other missing tables...

**Critical dependency rule:**
- Run migration 001 first (creates contacts, companies, properties)
- Run migration 030 second (creates users, sessions, quotes)
- Run all other migrations in numbered order

---

## Action 3: Test Migration Order (20 minutes)

After creating migration 030, test the full migration sequence on a fresh database:

```bash
# Create a test database
mysql -u root -p << EOF
DROP DATABASE IF EXISTS mowology_test;
CREATE DATABASE mowology_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
EOF

# Run migrations in order
cd /Users/timschofield/Projects/mowology-crm/database/migrations

# Foundation
mysql -u root -p mowology_test < 001_restructure_core_tables.sql

# Missing tables (NEW)
mysql -u root -p mowology_test < 030_create_missing_core_tables.sql

# Everything else in order
for file in 002_*.sql 007_*.sql 008_*.sql 010_*.sql 011_*.sql 012_*.sql \
            013_*.sql 014_*.sql 020_*.sql 021_*.sql 023_*.sql 024_*.sql \
            025_*.sql 026_*.sql 028_*.sql 029_*.sql 110_*.sql \
            112_*.sql 113_*.sql 114_*.sql create_*.sql done-*.sql \
            problem-*.sql; do
    echo "Running: $file"
    mysql -u root -p mowology_test < "$file" || echo "ERROR in $file"
done

# Verify table count
mysql -u root -p mowology_test -e "SELECT COUNT(*) as table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA='mowology_test';"
# Should show: 89 (or 93 with the 4 migration-only CMS tables)
```

---

## Action 4: Update SCHEMA_MASTER.sql (15 minutes)

**Problem:** SCHEMA_MASTER.sql is missing 4 newer CMS tables created by migrations:
- cms_media_variants
- cms_media_alt_suggestions
- cms_page_generations_log
- cms_case_studies_generated

**Option A (Quick):** Export from live database
```bash
mysqldump -u root -p mowology_landscape_crm > database/SCHEMA_MASTER_UPDATED.sql
# Then review and replace SCHEMA_MASTER.sql
```

**Option B (Manual):** Extract CREATE TABLE statements from:
- 112_cms_phase3_media_optimization.sql (cms_media_variants, cms_media_alt_suggestions)
- 113_cms_phase4_template_generation.sql (cms_page_generations_log)
- 114_cms_phase5_portfolio_integration.sql (cms_case_studies_generated)

And add them to SCHEMA_MASTER.sql in the CMS section.

---

## Action 5: Update Documentation (10 minutes)

Create or update these documentation files:

1. **MIGRATION_CHECKLIST.md** — Steps to run migrations correctly
2. **DATABASE_SETUP.md** — Fresh database setup instructions
3. **SCHEMA_VERSION.txt** — Document current schema version

Mention in CLAUDE.md:
```
Database migrations are ordered by filename (001, 002, 007, etc.).
Always run migration 001 first, then 030, then the rest in order.
See MIGRATION_CHECKLIST.md for full setup instructions.
```

---

## Detailed Analysis

See these files for full details:

- **DATABASE_MIGRATION_ANALYSIS.md** — Complete 200+ line analysis with SQL for all 27 missing tables
- **MIGRATION_GAP_SUMMARY.txt** — Executive summary with quick reference
- **MASTER_SCHEMA_TABLES.txt** — Complete table-by-table mapping with dependencies

---

## Checklist

- [ ] **Action 1** — Remove duplicate seo_page_drafts CREATE from problem-501 file (5 min)
- [ ] **Action 2** — Create 030_create_missing_core_tables.sql (30 min)
- [ ] **Action 3** — Test migrations on fresh database (20 min)
- [ ] **Action 4** — Update SCHEMA_MASTER.sql with 4 missing CMS tables (15 min)
- [ ] **Action 5** — Update documentation (10 min)
- [ ] **Verify** — Run final test to ensure 89+ tables are created

**Total time:** ~1.5 hours

---

## Why This Matters

1. **Production Database Risk**: If you ever need to rebuild from migrations, you'll be missing critical tables
2. **Development Setup**: New developers can't set up fresh databases correctly
3. **Deployment**: Automated deployment/migrations will be incomplete
4. **Audit**: Schema drift between live and migrations creates technical debt

---

## Questions?

Refer to:
- `/Users/timschofield/Projects/mowology-crm/DATABASE_MIGRATION_ANALYSIS.md` — Full technical details
- `/Users/timschofield/Projects/mowology-crm/MIGRATION_GAP_SUMMARY.txt` — Quick reference
- `/Users/timschofield/Projects/mowology-crm/MASTER_SCHEMA_TABLES.txt` — Table mapping

---

## Contact

For assistance with implementing these changes, refer to CLAUDE.md section "3. CRM — AppStack Page Template" for database update procedures.
