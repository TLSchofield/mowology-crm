# Phase 4 Deployment Guide: Template-Driven Landing Pages

**Status:** ✅ Ready for production deployment
**Created:** February 2026
**Implementation Complete:** All 5 files tested and documented

---

## Overview

Phase 4 enables **automated landing page generation** from reusable templates. This deployment guide walks through applying Phase 4 to your production environment.

**What staff can do after deployment:**
- Generate service landing pages in 2 minutes (vs. 15+ minutes manual)
- Create dozens of geo-targeted pages by selecting service + neighbourhood
- Auto-populate all metadata, CTAs, and content from templates
- Generate gallery pages that pull portfolio photos automatically

---

## Pre-Deployment Checklist

- [ ] Database backups taken
- [ ] Test environment has latest code from Phase 1-3
- [ ] Staff notified of upcoming feature
- [ ] You have time to troubleshoot (30-45 minutes)

---

## Step 1: Apply Database Migrations

### Migration 113: Create Generator Tables

This migration is in the base schema (migration 113) and was already applied when Phase 3 was deployed.

**Verify it was applied:**
```bash
mysql mowology_landscape_crm -e "SHOW TABLES LIKE 'cms_page_generator%';"
```

Expected output:
```
cms_page_generator_config
cms_page_generations_log
```

If NOT present, apply migration 113:
```bash
mysql mowology_landscape_crm < database/migrations/113_cms_phase4_template_generation.sql
```

### Migration 115: Seed Templates

Apply the template seeding migration:
```bash
mysql mowology_landscape_crm < database/migrations/115_seed_generator_templates.sql
```

**Verify templates were created:**
```bash
mysql mowology_landscape_crm -e "SELECT config_key, config_label, enabled FROM cms_page_generator_config ORDER BY config_label;"
```

Expected output:
```
config_key                  | config_label                    | enabled
service-landing-basic       | Service Landing Page            |       1
service-landing-portfolio   | Service Landing with Portfolio  |       1
neighbourhood-coverage      | Neighbourhood Coverage Page     |       0
```

---

## Step 2: Copy Phase 4 Files

Copy the 4 new files to their target directories:

### Core Engine
```bash
cp public/crm/includes/page-generator.php /path/to/production/public/crm/includes/
chmod 644 /path/to/production/public/crm/includes/page-generator.php
```

### API Endpoint
```bash
cp public/crm/api/generate-page.php /path/to/production/public/crm/api/
chmod 644 /path/to/production/public/crm/api/generate-page.php
```

### UI - Wizard for Staff
```bash
cp public/crm/cms/cms-page-generator-wizard.php /path/to/production/public/crm/cms/
chmod 644 /path/to/production/public/crm/cms/cms-page-generator-wizard.php
```

### UI - Template Manager
```bash
cp public/crm/cms/cms-generator-manager.php /path/to/production/public/crm/cms/
chmod 644 /path/to/production/public/crm/cms/cms-generator-manager.php
```

---

## Step 3: Verify File Permissions

```bash
ls -l /path/to/production/public/crm/includes/page-generator.php
ls -l /path/to/production/public/crm/api/generate-page.php
ls -l /path/to/production/public/crm/cms/cms-page-generator-wizard.php
ls -l /path/to/production/public/crm/cms/cms-generator-manager.php
```

All should be readable (644) and not executable.

---

## Step 4: Test the Deployment

### Test 1: Access Wizard

**URL:** `https://mowology.ca/crm/cms/cms-page-generator-wizard.php`

Expected:
- Page loads without errors
- 3 templates visible (Service Landing Page, Service+Portfolio, optionally Neighbourhood)
- Service dropdown populated (lawn-care, snow-removal, landscaping, etc.)
- Neighbourhood dropdown populated (from completed jobs)

### Test 2: Generate a Test Page

1. Select template: "Service Landing Page"
2. Select service: "Lawn Care"
3. Select neighbourhood: (pick any from dropdown)
4. Review: Page title should show substitution
5. Click Generate
6. Should redirect to page editor automatically

**Verify in database:**
```sql
SELECT id, title, slug, is_template_generated, template_source_key, status
FROM cms_pages
WHERE is_template_generated = TRUE
ORDER BY created_at DESC
LIMIT 1;
```

Expected: One row with status='draft', is_template_generated=TRUE

### Test 3: Edit and Publish

1. Click Edit on generated page (should auto-open in editor)
2. Make minor edit (change title or description)
3. Publish page
4. Visit public URL
5. Verify page displays correctly

### Test 4: Check Audit Trail

```sql
SELECT * FROM cms_page_generations_log
ORDER BY generated_at DESC
LIMIT 1;
```

Should show: page_id, generator_config_id, variables (JSON), generated_by, generated_at

---

## Step 5: Add Sidebar Link (Optional)

To add a quick link to the generator wizard in the CRM sidebar:

Edit `/public/crm/includes/appstack_sidebar.php` and find the CMS section. Add this item:

```php
[
    'icon' => 'zap',
    'label' => 'Generate Page',
    'url' => '/crm/cms/cms-page-generator-wizard.php',
    'active' => $activePage === 'cms'  // Or create new 'generate' page key
]
```

This will add a "Generate Page" link with a lightning bolt icon under the CMS menu.

---

## Step 6: Communicate to Staff

Send staff this communication:

---

**Subject: New Feature Available - Automated Page Generation**

Hi team,

We've deployed a new CMS feature that lets you generate landing pages in minutes.

**How to use it:**

1. Go to CMS → Generate Page (or visit `/crm/cms/cms-page-generator-wizard.php`)
2. Step through the 4-step wizard:
   - Choose template (Service Landing, Service + Portfolio, etc.)
   - Select service (Lawn Care, Snow Removal, etc.)
   - Select neighbourhood (Burnaby, Richmond, etc.)
   - Review and generate
3. Page editor opens automatically
4. Edit if needed, then publish

**Benefits:**
- Create a service landing page in 2 minutes (vs. 15+ manually)
- All titles, descriptions, and CTAs auto-populated
- Consistent branding across pages
- Perfect for geo-targeted campaigns

**Example use cases:**
- Create "Lawn Care in [Neighbourhood]" pages for each area you serve
- Create "Snow Removal in [Neighbourhood]" pages for seasonal campaigns
- Quickly scale to 20+ pages across multiple services and areas

**Questions?** Contact your admin.

---

---

## Troubleshooting

### Issue: "No templates found" in wizard

**Cause:** Migration 115 wasn't applied or templates weren't created.

**Fix:**
```bash
mysql mowology_landscape_crm < database/migrations/115_seed_generator_templates.sql
```

Then refresh wizard page.

### Issue: Neighbourhood dropdown is empty

**Cause:** No completed jobs with neighbourhoods in database.

**Fix:** Create a test job:
1. Go to Jobs → Create Job
2. Fill details including neighbourhood
3. Mark as "Completed"
4. Now refresh wizard → neighbourhood should appear

Or directly insert test data:
```sql
INSERT INTO jobs (title, neighbourhood, status, created_by, created_at)
VALUES ('Test Job', 'Burnaby', 'completed', 1, NOW());
```

### Issue: "CSRF token invalid" error

**Cause:** Session expired or browser cache issue.

**Fix:**
1. Hard refresh wizard page (Ctrl+Shift+R)
2. Try generating again

### Issue: Page generates but variables not substituted

**Cause:** Block configuration not saved as proper JSON array.

**Fix:**
1. Check block content in `cms_blocks` table
2. Verify `config` column is valid JSON
3. If not, regenerate page with simpler template

### Issue: Slug collision error

**Cause:** Page with that slug already exists.

**Fix:**
1. Page generator adds timestamp to slug automatically
2. Or: Delete/rename existing page first
3. Then retry generation

---

## Rollback Plan

If you need to roll back Phase 4:

1. **Delete new files:**
   ```bash
   rm /path/to/production/public/crm/includes/page-generator.php
   rm /path/to/production/public/crm/api/generate-page.php
   rm /path/to/production/public/crm/cms/cms-page-generator-wizard.php
   rm /path/to/production/public/crm/cms/cms-generator-manager.php
   ```

2. **Remove sidebar link** (if added)
   - Edit `/public/crm/includes/appstack_sidebar.php`
   - Remove the "Generate Page" menu item

3. **Database:** No changes needed
   - Migration tables can remain (dormant)
   - Generated pages become regular pages (is_template_generated=TRUE just a flag)

4. **Staff:** Notify that feature is temporarily unavailable

---

## Performance Verification

After deployment, verify performance:

### Check generation speed

1. Generate 5 test pages in wizard
2. Measure time from "Generate" click to page editor open
3. Expected: < 2 seconds per page

**Logs to check:**
```bash
tail -50 /var/log/php-errors.log | grep "generator"
```

### Check database indexes

```sql
SHOW INDEX FROM cms_pages WHERE Column_name = 'slug';
SHOW INDEX FROM cms_page_generator_config WHERE Column_name = 'config_key';
```

Expected: Indexes exist on both columns

### Monitor log table growth

```sql
SELECT COUNT(*) FROM cms_page_generations_log;
```

Should grow by 1 row per generation.

---

## Success Checklist

- [ ] Database migrations 113 + 115 applied successfully
- [ ] All 4 files copied to production
- [ ] Wizard page loads at `/crm/cms/cms-page-generator-wizard.php`
- [ ] Templates visible in wizard (3 templates)
- [ ] Services dropdown populated
- [ ] Neighbourhoods dropdown populated
- [ ] Test page generated successfully
- [ ] Generated page appears in cms_pages table
- [ ] Test page edits and publishes correctly
- [ ] Generated page displays correctly on public site
- [ ] Audit trail visible in cms_page_generations_log
- [ ] Staff notified and trained

---

## Documentation

**For Staff:**
- Send link to: `https://mowology.ca/crm/cms/cms-page-generator-wizard.php`
- Point to: `CMS_PHASE_4_QUICK_REFERENCE.md` for self-service docs

**For Developers:**
- Technical reference: `CMS_PHASE_4_COMPLETE.md`
- Code examples: See "Code Examples" section in that document
- API reference: `/crm/api/generate-page.php`

---

## Next Phase: Phase 5 - Portfolio Integration

After Phase 4 is stable (1-2 weeks), deploy Phase 5:
- Portfolio photo tagging (service + neighbourhood + featured)
- Auto-population of gallery blocks from portfolio
- Case study generation from photo sets

**Status:** Schema ready, implementation guide written, ready to deploy

---

## Support

If you encounter issues during deployment:

1. Check error logs: `/var/log/php-errors.log`
2. Verify database migrations: `SELECT * FROM migrations_log ORDER BY executed_at DESC;`
3. Test individual functions in PHP:
   ```php
   require_once 'page-generator.php';
   $configs = pg_getGeneratorConfigs();
   var_dump($configs);
   ```

---

**Phase 4 Deployment:** Ready for production
**Estimated deployment time:** 15-20 minutes
**Estimated testing time:** 15-30 minutes
**Total:** 30-50 minutes

Good luck with the deployment! 🚀
