# 🚨 Urgent Production Fixes — Ready to Deploy

**Generated:** February 7, 2026
**Status:** 3 fixes ready for deployment (2 critical + 1 recommended)

---

## Quick Summary

Three production errors discovered and fixed:

1. **❌ CRITICAL:** Job creation fails when creating jobs from quotes
2. **❌ CRITICAL:** Google Search Console module crashes on undefined constant
3. **⚠️ MEDIUM:** PHP deprecation warnings appearing in job view

Deploy fixes 1 & 2 immediately. Fix 3 is recommended but can wait if needed.

---

## Fix #1: Critical Path Error (Commit de32198)

### What's Broken
- Users cannot create jobs from accepted quotes
- Throws fatal error: `Failed opening required '/home/mowology/public_html/crm/crm/includes/roi-functions.php'`
- Affects: Quote acceptance → Job creation workflow

### What Changed
- File: `public/crm/includes/functions.php`
- Lines 271 & 288: Fixed duplicate `crm/` in include path
- Changed from: `dirname(__DIR__) . '/crm/includes/roi-functions.php'`
- Changed to: `__DIR__ . '/roi-functions.php'`

### How to Deploy
1. Via FTP: Upload `public/crm/includes/functions.php` to `/crm/includes/`
2. Via cPanel: File Manager → navigate to `/crm/includes/` → replace functions.php
3. Via SSH: `scp local_file.php user@mowology.ca:public_html/crm/includes/functions.php`

### How to Test
1. Log into CRM as admin
2. Accept any quote (change status to 'accepted')
3. Create a job from that quote
4. Verify: No PHP fatal errors appear
5. Verify: Job is created successfully

---

## Fix #2: Critical Undefined Constant in GSC Module (Commit 5ecc582)

### What's Broken
- Google Search Console module crashes with fatal error
- Accessing `/crm/gsc/connect.php` throws: `Uncaught Error: Undefined constant "SITE_URL"`
- Affects: Google Search Console OAuth connection and status page

### What Changed
- File: `public/crm/gsc/connect.php`
- Lines 79, 106, 116: Removed undefined `SITE_URL` constant
- Changed from: `SITE_URL ?? 'https://mowology.ca'`
- Changed to: `$siteUrl = 'https://mowology.ca'`

### How to Deploy
1. Via FTP: Upload `public/crm/gsc/connect.php` to `/crm/gsc/`
2. Via cPanel: File Manager → navigate to `/crm/gsc/` → replace connect.php
3. Via SSH: `scp local_file.php user@mowology.ca:public_html/crm/gsc/connect.php`

### How to Test
1. Log into CRM as admin
2. Navigate to: Portfolio → Insights (or directly to `/crm/gsc/connect.php`)
3. Verify: Page loads without fatal errors
4. Verify: Connection status displays correctly

---

## Fix #3: PHP 8.1 Deprecation Warnings (Commit 84fe0b4)

### What's Broken
- Deprecation warnings in browser console when viewing job details
- Message: `htmlspecialchars(): Passing null to parameter #1... is deprecated`
- Line: `/crm/jobs/view.php on line 277`
- Impact: Low (warning only, not breaking), but will become error in PHP 9.0

### What Changed
- File: `public/crm/jobs/view.php`
- Lines 192, 209, 277, 288-290, 296: Added null safety checks
- All `htmlspecialchars()` calls now handle null values gracefully
- Provides fallback values: 'N/A', 'Unknown', or empty string

### How to Deploy
1. Via FTP: Upload `public/crm/jobs/view.php` to `/crm/jobs/`
2. Via cPanel: File Manager → navigate to `/crm/jobs/` → replace view.php
3. Via SSH: `scp local_file.php user@mowology.ca:public_html/crm/jobs/view.php`

### How to Test
1. Log into CRM as admin
2. View any job details page
3. Open browser console (F12 → Console tab)
4. Verify: No deprecation warnings appear
5. Verify: All fields display correctly (Company, Contact, Phone, Property)

---

## Deployment Strategy

### Option A: Deploy All Fixes Now (Recommended)

Upload all three files in one go:

```bash
# Copy all fixed files to your FTP client
1. public/crm/includes/functions.php → /crm/includes/
2. public/crm/gsc/connect.php → /crm/gsc/
3. public/crm/jobs/view.php → /crm/jobs/

# Test on live server
# Verify all fixes work
```

### Option B: Deploy Critical Fixes First, Deprecation Fix Later

If you want to be conservative:

```bash
# Step 1: Deploy critical fixes immediately
1. Upload public/crm/includes/functions.php
2. Upload public/crm/gsc/connect.php
3. Test: Job creation and GSC module work
4. Announce fixes are live

# Step 2: Deploy deprecation fix in next maintenance window
1. Upload public/crm/jobs/view.php
2. Test: No warnings in job view
3. Done
```

**Recommendation:** Option A. All fixes are safe, non-breaking, and simple. Deploy together.

---

## Files to Upload

### Critical Fix #1: Path Resolution (de32198)

**Source:** `/Users/timschofield/Projects/mowology-crm/public/crm/includes/functions.php`
**Destination:** `/home/mowology/public_html/crm/includes/functions.php`
**Size:** ~28 KB
**Changes:** 2 lines (include path fixes)

### Critical Fix #2: SITE_URL Constant (5ecc582)

**Source:** `/Users/timschofield/Projects/mowology-crm/public/crm/gsc/connect.php`
**Destination:** `/home/mowology/public_html/crm/gsc/connect.php`
**Size:** ~8 KB
**Changes:** 3 lines (constant fixes)

### Recommended Fix: Deprecation Warnings (84fe0b4)

**Source:** `/Users/timschofield/Projects/mowology-crm/public/crm/jobs/view.php`
**Destination:** `/home/mowology/public_html/crm/jobs/view.php`
**Size:** ~15 KB
**Changes:** 5 sections (null safety checks)

---

## Pre-Deployment Checklist

- [ ] Back up production files (FTP → download all three files first)
- [ ] Review changes above
- [ ] Ensure you have cPanel/FTP access working
- [ ] Identify a test user who can verify the fixes
- [ ] Plan testing: "Create quote → Accept → Create job" workflow
- [ ] Have rollback plan (restore from backup if needed)

---

## Post-Deployment Testing

### Test Critical Fix (Job Creation)

1. **Log in to CRM**
   - Navigate to: https://mowology.ca/crm/

2. **Find or create a quote**
   - Go to Quotes section
   - Either use existing quote or create new one

3. **Accept the quote**
   - Change status from "Draft" or "Sent" to "Accepted"
   - Save

4. **Create job from quote**
   - Look for "Create Job" button
   - Click and complete job creation form
   - Submit

5. **Verify success**
   - Job should be created without errors
   - Navigate to Jobs section to see new job
   - Open the job to view details

**Expected result:** ✅ Job created successfully, no fatal errors

### Test Deprecation Fix (Job View)

1. **Open any job**
   - Navigate to: https://mowology.ca/crm/jobs/
   - Click any job to view details

2. **Open browser console**
   - Press: F12 (or right-click → Inspect)
   - Go to: Console tab

3. **Check for warnings**
   - Should be NO messages about "htmlspecialchars() ... is deprecated"

4. **Verify display**
   - Look at "Customer" section:
     - Company: Should show company name or "N/A"
     - Contact: Should show contact name or "N/A"
     - Phone: Should show phone number or "N/A"
   - Look at "Property" section:
     - Should show full address or "N/A"

**Expected result:** ✅ No warnings, all fields display with fallback values where needed

---

## Rollback Instructions

If something goes wrong:

1. **Via FTP:**
   - Download your backup copies
   - Re-upload the original versions
   - Test again

2. **Via cPanel:**
   - File Manager
   - Find the uploaded file
   - Right-click → Delete or Restore
   - Re-upload correct version

3. **Contact Support if:**
   - Files won't upload
   - File permissions are wrong (should be 644)
   - FTP connection issues

---

## Questions Before Deploying?

Review these documents:
- `PRODUCTION_FIX_LOG.md` - Detailed technical explanation of each fix
- `DEPLOYMENT_INSTRUCTIONS.md` - Step-by-step deployment guide
- `DEV_SETUP_COMPLETE.md` - Local testing environment setup

---

## Summary

| Item | Status |
|------|--------|
| Critical fix #1 ready | ✅ Yes (de32198 - path fix) |
| Critical fix #2 ready | ✅ Yes (5ecc582 - SITE_URL fix) |
| Recommended fix ready | ✅ Yes (84fe0b4 - deprecation fix) |
| All tests passing | ✅ Yes |
| Documentation complete | ✅ Yes |
| Ready to deploy | ✅ YES |

**Next step:** Upload the three fixed files to production. All can be deployed simultaneously or separately based on your preference. Deploy at least the two critical fixes (de32198 and 5ecc582).
