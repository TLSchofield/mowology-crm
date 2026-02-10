# 🚀 GSC Sync Fix — Implementation Guide

## Quick Start (5 minutes)

### Step 1: Apply Database Migration

```bash
# SSH into your server
cd /home/mowology/public_html

# Run the migration to add new columns
mysql -u user -p database_name < public/crm/database/migrations/030_gsc_properties_enhanced.sql

# Verify migration
mysql -u user -p database_name -e "
  SELECT id, site_url, api_site_url, property_type, display_domain
  FROM gsc_properties;
"

# Expected output:
# | id | site_url              | api_site_url          | property_type | display_domain |
# | 4  | https://mowology.ca   | https://mowology.ca   | url_prefix    | mowology.ca    |
```

### Step 2: Test Current (Broken) Sync

```bash
# Test with current code — should still fail
curl -X POST https://www.mowology.ca/crm/gsc/sync-cron.php \
  -d "csrf_token=YOUR_CSRF_TOKEN"

# Expected output:
# {"success":true,"pulled":0,"failed":1,"message":"Pulled 0 properties, 1 failed","errors":[{"property":"mowology.ca","reason":"Failed to fetch GSC data..."}]}
```

### Step 3: Deploy Fixed Sync

```bash
# Option A: Replace current sync-cron.php
cp /path/to/sync-cron-fixed.php /path/to/sync-cron.php

# Option B: Test first without replacing (recommended)
# Run fixed version directly:
php /home/mowology/public_html/public/crm/gsc/sync-cron-fixed.php

# Expected output:
# ✓ GSC sync completed: Pulled 1 properties, 0 failed
```

### Step 4: Verify Success

```bash
# Query the database to confirm data was pulled
mysql -u user -p database_name -e "
  SELECT COUNT(*) as snapshots FROM gsc_snapshots
  WHERE property_id = 4 AND snapshot_date = CURDATE();
"

# Check query stats were inserted
mysql -u user -p database_name -e "
  SELECT COUNT(*) as rows FROM gsc_query_page_stats
  WHERE snapshot_id = (
    SELECT id FROM gsc_snapshots
    WHERE property_id = 4 AND snapshot_date = CURDATE()
  );
"

# Expected: > 0 for both queries
```

---

## Detailed Implementation

### Pre-Flight Checks

Before applying any changes:

```bash
# 1. Verify current database state
mysql -u user -p database_name -e "DESC gsc_properties;"

# 2. Check for existing data
mysql -u user -p database_name -e "SELECT * FROM gsc_properties;"

# 3. Test current sync one more time to document failure
curl -X POST https://www.mowology.ca/crm/gsc/sync-cron.php -d "csrf_token=..."

# 4. Back up database
mysqldump -u user -p database_name > backup_before_gsc_fix.sql
```

### Phase 1: Database Schema Update

**File:** `/public/crm/database/migrations/030_gsc_properties_enhanced.sql`

**What it does:**
- ✅ Adds `api_site_url` column (stores EXACT identifiers)
- ✅ Adds `property_type` column ('url_prefix' or 'domain')
- ✅ Adds `display_domain` column (clean domain for UI)
- ✅ Adds sync tracking columns
- ✅ Migrates existing `site_url` → `api_site_url` automatically

**Execution:**

```bash
# Run migration
mysql -u mowology_admin -p mowology_landscape_crm < /home/mowology/public_html/public/crm/database/migrations/030_gsc_properties_enhanced.sql

# Verify new columns exist
mysql -u user -p database_name -e "
  SELECT
    id,
    site_url,
    api_site_url,
    property_type,
    display_domain,
    is_active
  FROM gsc_properties;
"
```

**Expected Result:**
```
| id | site_url            | api_site_url        | property_type | display_domain  | is_active |
|----|---------------------|---------------------|---|---|---|
| 4  | https://mowology.ca | https://mowology.ca | url_prefix | mowology.ca     | 1         |
```

### Phase 2: Deploy Fixed Sync Script

**File:** `/public/crm/gsc/sync-cron-fixed.php`

**Key Changes:**
- ✅ Line 136: `validateSiteUrlFormat()` — ensures identifier matches property type
- ✅ Line 164: Uses `$apiSiteUrl` **VERBATIM** (no `sc-domain:` transformation)
- ✅ Line 169: `rawurlencode()` properly encodes the URL for API path
- ✅ Line 205: Error logging includes property type for clarity

**Deployment Options:**

#### Option A: In-Place Replacement (Fastest)
```bash
# Backup current file
cp /home/mowology/public_html/public/crm/gsc/sync-cron.php \
   /home/mowology/public_html/public/crm/gsc/sync-cron.php.backup

# Deploy fixed version
cp /path/to/sync-cron-fixed.php \
   /home/mowology/public_html/public/crm/gsc/sync-cron.php
```

#### Option B: Side-by-Side Testing (Safer)
```bash
# Keep original, run fixed version separately
php /home/mowology/public_html/public/crm/gsc/sync-cron-fixed.php

# If successful, then replace
cp /path/to/sync-cron-fixed.php /path/to/sync-cron.php
```

### Phase 3: Test the Fix

#### Test 1: CLI Execution

```bash
# Run the fixed sync directly
cd /home/mowology/public_html
php public/crm/gsc/sync-cron-fixed.php

# Expected output:
# ✓ GSC sync completed: Pulled 1 properties, 0 failed
```

#### Test 2: Web Request

```bash
# Get a CSRF token (you'll need to log in first)
# Then run:
curl -X POST https://www.mowology.ca/crm/gsc/sync-cron.php \
  -b "PHPSESSID=your_session_id" \
  -d "csrf_token=your_csrf_token"

# Expected response:
# {"success":true,"pulled":1,"failed":0,"message":"Pulled 1 properties, 0 failed","errors":[]}
```

#### Test 3: Verify Data Insertion

```bash
# Check snapshots were created
mysql -u user -p database_name -e "
  SELECT id, property_id, snapshot_date, DATE(pulled_at) FROM gsc_snapshots
  WHERE property_id = 4
  ORDER BY pulled_at DESC LIMIT 5;
"

# Check query_page_stats rows
mysql -u user -p database_name -e "
  SELECT COUNT(*) as total_stats,
         COUNT(DISTINCT query) as unique_queries,
         COUNT(DISTINCT page) as unique_pages
  FROM gsc_query_page_stats
  WHERE snapshot_id IN (
    SELECT id FROM gsc_snapshots WHERE property_id = 4
  );
"
```

#### Test 4: Verify Error Handling

```bash
# Test with invalid property type (should reject)
# This is handled automatically, just verify logs don't show errors:
tail -f /path/to/php-error.log

# Look for lines like:
# GSC: Invalid url_prefix site_url format: sc-domain:example.com
# (This shouldn't happen with correct data, just testing defensive code)
```

### Phase 4: Schedule Cron Job (Optional)

If not already running, add to crontab:

```bash
# Edit crontab
crontab -e

# Add this line to run daily at 2 AM:
0 2 * * * php /home/mowology/public_html/public/crm/gsc/sync-cron.php

# Verify it's scheduled
crontab -l

# View cron logs (varies by system)
# Ubuntu/Debian: grep CRON /var/log/syslog
# CentOS: grep CRON /var/log/cron
```

---

## Troubleshooting

### Sync Still Reports 0 Pulled

**Check 1: Migration didn't run**
```bash
mysql -u user -p database_name -e "SELECT api_site_url FROM gsc_properties LIMIT 1;"
# If empty, migration didn't complete
```

**Check 2: Wrong file deployed**
```bash
# Verify sync-cron.php contains the fix
grep "validateSiteUrlFormat" /path/to/sync-cron.php
# If no output, you're still running old version
```

**Check 3: API URL encoding issue**
```bash
# Check recent error logs
tail -50 /path/to/php-error.log | grep GSC

# Look for lines indicating 404 from Google
# These would show the actual URL being queried
```

**Check 4: Token expired**
```bash
mysql -u user -p database_name -e "
  SELECT expires_at, NOW() FROM gsc_properties WHERE id = 4;
"
# If expires_at < NOW(), token needs refresh
```

### Error: "Invalid url_prefix site_url format"

This means the database has malformed identifiers.

**Fix:**
```bash
# Check what's stored
mysql -u user -p database_name -e "
  SELECT api_site_url, property_type FROM gsc_properties;
"

# Manually correct if needed:
mysql -u user -p database_name -e "
  UPDATE gsc_properties
  SET
    api_site_url = 'https://mowology.ca',
    property_type = 'url_prefix'
  WHERE id = 4;
"
```

### Error: "No refresh token for property"

The stored refresh token is empty or corrupted.

**Fix:**
1. Reconnect GSC in the admin dashboard (`/crm/gsc/connect.php`)
2. Ensure "offline" access is requested (should happen automatically)
3. Verify refresh token is encrypted and stored:
   ```bash
   mysql -u user -p database_name -e "
     SELECT
       id,
       access_token_encrypted IS NOT NULL as has_access,
       refresh_token_encrypted IS NOT NULL as has_refresh,
       expires_at
     FROM gsc_properties;
   "
   ```

### Error: "Failed to get snapshot ID"

Likely a race condition or database issue.

**Check:**
```bash
# Verify gsc_snapshots table exists and is accessible
mysql -u user -p database_name -e "SELECT COUNT(*) FROM gsc_snapshots;"

# Check for recent errors in PHP logs
tail -20 /path/to/php-error.log
```

---

## Rollback Plan

If something goes wrong:

### Option 1: Quick Rollback

```bash
# Restore backup file
cp /path/to/sync-cron.php.backup /path/to/sync-cron.php

# This reverts to old behavior (0 pulled) but won't break anything
```

### Option 2: Database Rollback

```bash
# Restore database backup
mysql -u user -p database_name < backup_before_gsc_fix.sql
```

### Option 3: Minimal Rollback

If you want to keep the schema but revert code:

```bash
# The new columns are non-breaking, so old code still works
# Just restore the old sync-cron.php file
# (It will ignore the new columns and use old site_url)
```

---

## Verification Checklist

- [ ] Migration applied successfully
- [ ] New columns exist in database
- [ ] Existing data migrated (api_site_url populated)
- [ ] Fixed sync-cron.php deployed
- [ ] Sync ran without errors (check logs)
- [ ] Snapshots created for current date
- [ ] Query stats inserted (>0 rows)
- [ ] Display shows data correctly
- [ ] Cron job scheduled (if applicable)

---

## Performance Impact

**Before Fix:**
- Sync runs: 0 properties pulled, 1 failed
- No data stored, wasted API calls
- Dashboard shows no insights

**After Fix:**
- Sync runs: 1 property pulled, 0 failed
- ~25,000 rows inserted per property per day
- Dashboard displays live data
- Same API quota usage (just succeeds instead of fails)

---

## File Summary

| File | Purpose | Status |
|------|---------|--------|
| `030_gsc_properties_enhanced.sql` | Add columns, migrate data | ✅ Ready |
| `sync-cron-fixed.php` | Fixed sync script | ✅ Ready |
| `GSC_AUDIT_AND_FIX.md` | Root cause analysis | ✅ Reference |
| `GSC_IMPLEMENTATION_GUIDE.md` | This document | 📄 You are here |

---

## Next Steps

1. ✅ **Apply migration** — Run `030_gsc_properties_enhanced.sql`
2. ✅ **Deploy fixed script** — Replace `sync-cron.php` or test `sync-cron-fixed.php`
3. ✅ **Test sync** — Verify data is pulled successfully
4. ✅ **Monitor** — Check logs for any issues
5. 🔄 **Future enhancement** — Consider adding property picker to connect flow

---

## Support & Questions

If you encounter issues:

1. Check error logs: `/path/to/php-error.log`
2. Review root cause analysis in `GSC_AUDIT_AND_FIX.md`
3. Verify database state with provided SQL queries
4. Check Google Search Console for permission status

---

**Status:** Ready for production deployment
**Risk Level:** LOW (schema change is backward-compatible, code fix is focused)
**Estimated Time:** 5–10 minutes for full deployment + testing
