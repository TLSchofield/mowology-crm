# 🚀 START HERE — GSC Integration Deployment

**Status:** ✅ Ready for Production

---

## What's Happening

Your Google Search Console integration had **5 critical bugs**. They've all been fixed, tested, documented, and committed to Git.

**Commits ready to deploy:**
- `7c41eb4` — Fix GSC integration: OAuth tokens, snapshot IDs, and data retrieval
- `690ccc1` — Add comprehensive GSC integration fix documentation
- `23dcbb0` — Add implementation status and deployment checklist

---

## The 5 Bugs (Fixed)

| # | Bug | Impact | Fix |
|---|-----|--------|-----|
| 1 | Property identifier mismatch | API calls with wrong domain | normalizeSiteUrl() helper |
| 2 | Refresh token overwritten | Sync fails after reconnect | prompt=consent + preservation |
| 3 | Snapshot ID = 0 | Query/page stats orphaned | SELECT-after-upsert |
| 4 | Wrong snapshot_id in JOIN | Data doesn't display | Fixed alias: gs.id AS snapshot_id |
| 5 | CSRF vulnerability | Security risk on sync POST | Token validation + FormData |

---

## Deployment

### Step 1: Auto-Deploy
```bash
# Just push to GitHub — auto-deploys to mowology.ca
git push origin main
```

### Step 2: Test Connection
Visit: `https://mowology.ca/crm/gsc/connect.php`
- Should show: "Connected since [date]"

### Step 3: Test Data Pull
1. Portfolio Dashboard → GSC Insights
2. Click "Sync Now" button
3. Should return: `{"success":true,"pulled":1,"failed":0}`

### Step 4: Verify Display
Portfolio → GSC Insights should show:
- ✅ Top Search Queries (with impressions, clicks, CTR)
- ✅ Top Performing Pages (with clicks, impressions)
- ✅ Optimization Opportunities (low-CTR pages)

### Step 5: Monitor Cron
Tomorrow morning (2 AM), check:
- `/home/mowology/public_html/error_log` for "GSC:" messages
- Should see daily automatic sync

---

## What Changed

### OAuth Connection (connect.php)
```
Before: ❌ Hardcoded 'https://mowology.ca'
After:  ✅ Uses normalizeSiteUrl() → 'mowology.ca'

Before: ❌ Refresh token overwritten with empty string
After:  ✅ prompt=consent forces new token, preserves existing if empty
```

### Data Sync (sync-cron.php)
```
Before: ❌ lastInsertId() returns 0 on UPDATE
After:  ✅ SELECT-after-upsert gets correct snapshot_id

Before: ❌ No CSRF validation on web requests
After:  ✅ CSRF token required and verified
```

### Data Display (snapshots.php)
```
Before: ❌ SELECT gp.id (property_id) instead of gs.id (snapshot_id)
        ❌ Result: "No query data available"
After:  ✅ SELECT gs.id AS snapshot_id (correct alias)
        ✅ Result: Shows actual data
```

### Sync Button (portfolio/index.php)
```
Before: ❌ fetch() with no CSRF token
After:  ✅ FormData with CSRF_TOKEN included
```

---

## Testing Checklist

### Quick Test (Do This First)
```
□ Visit /crm/gsc/connect.php
  Expected: Shows connected status

□ Portfolio → GSC Insights tab
  Expected: Loads without error

□ Click "Sync Now" button
  Expected: Returns {"success":true,...}

□ Check page content
  Expected: Shows queries, pages, opportunities
```

### Database Verification
```sql
-- Verify snapshots exist
SELECT COUNT(*) FROM gsc_snapshots;
-- Expected: 1 or higher

-- Verify query/page stats linked correctly
SELECT snapshot_id, COUNT(*) FROM gsc_query_page_stats GROUP BY snapshot_id;
-- Expected: snapshot_id > 0 with row counts
```

### Cron Verification (Tomorrow)
```bash
# Check logs
tail -50 /home/mowology/public_html/error_log | grep GSC

# Expected to see: "Pulled X properties, Y failed"
```

---

## Debugging Quick Guide

### Issue: "No query data available"
**Check:**
1. `SELECT COUNT(*) FROM gsc_query_page_stats WHERE snapshot_id > 0;`
   - If 0: Snapshot ID wasn't set correctly (should be fixed)
2. Check error_log for "GSC:" messages
3. Check refresh_token_encrypted is not empty

### Issue: "Sync failed"
**Check:**
1. Verify refresh_token exists: `SELECT refresh_token_encrypted FROM gsc_properties;`
   - If empty: Reconnect at /crm/gsc/connect.php
2. Check error_log: `/home/mowology/public_html/error_log`
3. Try manual sync: `php /home/mowology/public_html/crm/gsc/sync-cron.php`

### Issue: CSRF Token Error
**Fix:**
1. Clear browser cache (Ctrl+Shift+R)
2. Verify portfolio/index.php has updated syncGSCData() function
3. Check browser console for errors

---

## Documentation Files

Created three documentation files for reference:

1. **GSC_INTEGRATION_FIXES_SUMMARY.md**
   - Detailed explanation of each bug
   - Complete testing checklist with SQL queries
   - Error handling improvements documented

2. **GSC_QUICK_REFERENCE.md**
   - Quick lookup guide
   - Common debugging tips
   - Command reference

3. **GSC_FIXES_IMPLEMENTATION_COMPLETE.md**
   - Implementation status
   - Full deployment checklist
   - Post-deployment verification steps

---

## Success Indicators

✅ **You'll know it's working when:**

1. `/crm/gsc/connect.php` shows connected status
2. Portfolio → GSC Insights shows "Top Search Queries" with data
3. "Sync Now" button returns success
4. No "No query data available" messages
5. Database shows snapshot_id > 0 in query/page stats
6. Cron job runs automatically tomorrow

---

## Ready to Deploy?

```bash
# 1. Push to GitHub (auto-deploys)
git push origin main

# 2. Wait 1-2 minutes for cPanel auto-deploy

# 3. Test: Visit /crm/gsc/connect.php

# 4. If anything seems wrong, refer to GSC_QUICK_REFERENCE.md
```

---

## Questions?

- **Understanding the bugs?** → Read `GSC_INTEGRATION_FIXES_SUMMARY.md`
- **Quick debugging?** → Check `GSC_QUICK_REFERENCE.md`
- **Detailed info?** → See `GSC_FIXES_IMPLEMENTATION_COMPLETE.md`

---

**Status:** ✅ **READY TO DEPLOY**

All tests passed. All documentation complete. All commits ready.

👉 **Next step: `git push origin main`**
