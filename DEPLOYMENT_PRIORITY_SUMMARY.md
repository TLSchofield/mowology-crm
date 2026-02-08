# Production Deployment Priority Summary

**Date:** February 7, 2026
**Status:** ✅ Ready for immediate deployment
**Total Fixes:** 3 (2 critical + 1 recommended)

---

## 🚨 CRITICAL FIXES (Deploy Immediately)

### Fix #1: Job Creation Fatal Error
- **Commit:** `de32198`
- **File:** `public/crm/includes/functions.php`
- **Issue:** Duplicate `crm/` in include path causes fatal error when creating jobs from quotes
- **Impact:** Users cannot accept quotes and create jobs
- **Urgency:** CRITICAL

### Fix #2: Google Search Console Module Crash
- **Commit:** `5ecc582`
- **File:** `public/crm/gsc/connect.php`
- **Issue:** Undefined constant `SITE_URL` causes fatal error
- **Impact:** Cannot access GSC module at all
- **Urgency:** CRITICAL

---

## 🟡 RECOMMENDED FIX (Deploy Soon)

### Fix #3: PHP 8.1 Deprecation Warnings
- **Commit:** `84fe0b4`
- **File:** `public/crm/jobs/view.php`
- **Issue:** htmlspecialchars() called with null values causes deprecation warnings
- **Impact:** No functional issue, but will become error in PHP 9.0
- **Urgency:** RECOMMENDED (can wait for next maintenance window if needed)

---

## Files to Upload (In Priority Order)

### Priority 1: Critical Fixes

```
1. public/crm/includes/functions.php → /crm/includes/
2. public/crm/gsc/connect.php → /crm/gsc/
```

### Priority 2: Recommended Fix

```
3. public/crm/jobs/view.php → /crm/jobs/
```

---

## Quick Deployment (5 minutes)

1. **Open FTP client** (Transmit, Cyberduck, FileZilla, etc.)
2. **Connect to:** mowology.ca (with your cPanel credentials)
3. **Upload critical files:**
   - From: `/Users/timschofield/Projects/mowology-crm/public/crm/includes/functions.php`
   - To: `/crm/includes/functions.php`
   - From: `/Users/timschofield/Projects/mowology-crm/public/crm/gsc/connect.php`
   - To: `/crm/gsc/connect.php`
4. **Test on live server:**
   - Try accepting a quote and creating a job → Should work
   - Try accessing Portfolio → Insights → Should load without errors
5. **Done!** Critical fixes are live

---

## Post-Deployment Testing

### Test Critical Fix #1
1. Log in to https://mowology.ca/crm/
2. Go to Quotes
3. Accept any quote (change status to "Accepted")
4. Click "Create Job"
5. Expected: ✅ Job created successfully

### Test Critical Fix #2
1. Log in to https://mowology.ca/crm/
2. Go to Portfolio → Insights
3. Expected: ✅ Page loads, no fatal errors
4. Can see GSC connection status

---

## Risk Level: 🟢 LOW

- **Isolated changes:** Each fix is in a single file
- **No dependencies:** Fixes don't depend on each other
- **Backward compatible:** No breaking changes
- **Easy rollback:** Just re-upload original files if needed
- **No database changes:** Only PHP code changes

---

## Emergency Rollback

If anything goes wrong:

```bash
# Via FTP:
1. Download backup copies from production
2. Re-upload original versions
3. Test again
```

Takes about 2 minutes.

---

## Commit Details for Git

```bash
# Pull commits to verify they're in the repo
git log --oneline -3

# Expected output:
# 5ecc582 Fix undefined SITE_URL constant in Google Search Console module
# 84fe0b4 Fix PHP 8.1 deprecation warnings in job view
# de32198 Fix duplicate crm/ directory in include paths
```

---

## Questions?

- See `URGENT_DEPLOYMENT_FIXES.md` for detailed deployment steps
- See `PRODUCTION_FIX_LOG.md` for technical details
- See `DEPLOYMENT_INSTRUCTIONS.md` for general deployment process

---

## Next Steps

✅ All fixes are committed and ready
✅ All documentation is complete
✅ All testing has been done locally

→ **Ready to deploy immediately**

Just upload the files and test. That's it!
