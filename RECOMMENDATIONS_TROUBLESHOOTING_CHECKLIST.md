# Recommendations Tab - Troubleshooting Checklist

## ✓ The Fix Has Been Applied

All necessary code changes have been made. The recommendations should now display correctly.

## Quick Check (30 seconds)

- [ ] Go to `/crm/portfolio/index.php?tab=recommendations`
- [ ] Does the table show recommendations? (Should see rows, not "No recommendations yet" message)
  - [ ] **YES** → ✓ Problem solved! You're done.
  - [ ] **NO** → Continue to next section.

## If Recommendations Still Don't Display

### 1. Browser Cache (5 minutes)
```
Try one or more of these:
- [ ] Hard refresh: Ctrl+Shift+R (Windows/Linux) or Cmd+Shift+R (Mac)
- [ ] Clear browser cache and reload
- [ ] Open in Incognito/Private window
- [ ] Try different browser
```

### 2. Check What Data Is Being Returned (2 minutes)
```
[ ] Open this page: /crm/portfolio/verify-fix.php
[ ] Look at "Recommendations Data Include" section
    - Shows count of recommendations being returned?
    - Do you see "✓ X recommendations returned!" message?
    - Or do you see "✗ 0 recommendations returned"?

If 0 recommendations returned:
→ Continue to Step 3 (Direct DB Test)

If recommendations ARE shown here but not in the main tab:
→ Problem is in browser/JavaScript
→ Check browser console (F12) for errors
→ Try hard refresh (Ctrl+Shift+R)
```

### 3. Direct Database Test (3 minutes)
```
[ ] Open this page: /crm/portfolio/direct-db-test.php
[ ] Check "Test 1: Basic Count"
    - Does it show "Total rows in seo_recommendations: 141"?

[ ] Check "Test 3: Exact Query"
    - Does it show rows returned?

If YES to both:
→ Data exists and query works
→ Problem is in PHP logic or browser
→ Check Step 4 below

If NO:
→ Data problem
→ Check database directly in phpMyAdmin
```

### 4. Check PHP Error Log (3 minutes)
```
[ ] Open this page: /crm/portfolio/debug-recommendations.php
[ ] Scroll to "Test 4: PHP Error Log Check"
[ ] Are there any errors mentioning "Recommendation" or "Count"?

If YES:
→ Take note of the error message
→ The error will tell you what's wrong

If NO errors but still no data:
→ Problem might be elsewhere
→ Check browser console
→ Try clearing browser cache
```

## What Each Test File Does

| File | Purpose | Location |
|------|---------|----------|
| `verify-fix.php` | Shows what recommendations-data.php returns | `/crm/portfolio/verify-fix.php` |
| `debug-recommendations.php` | Comprehensive 4-part test | `/crm/portfolio/debug-recommendations.php` |
| `direct-db-test.php` | Tests raw SQL queries | `/crm/portfolio/direct-db-test.php` |
| `check-mysql-version.php` | Shows MySQL version | `/crm/portfolio/check-mysql-version.php` |

## Common Issues & Solutions

### Issue: "No recommendations yet" message
**Possible Causes:**
- [ ] Browser cache not cleared → Hard refresh (Ctrl+Shift+R)
- [ ] PHP error in query → Check debug-recommendations.php
- [ ] Database connection issue → Check direct-db-test.php
- [ ] Data not inserted → Check seo_recommendations table in phpMyAdmin

### Issue: See recommendations in verify-fix.php but not in main tab
**Possible Causes:**
- [ ] Browser cache → Hard refresh
- [ ] JavaScript error → Check browser console (F12)
- [ ] CSS hiding the table → Check browser inspector

### Issue: Database shows 0 recommendations
**Solution:**
- Data needs to be imported from GSC
- See `/database/INSERT_RECOMMENDATIONS_SIMPLE.sql`
- Or use GENERATE_RECOMMENDATIONS_FROM_GSC.sql script

## If You Need Help

### Information to Gather
1. Screenshot of what you see (if possible)
2. Results from `debug-recommendations.php` (copy/paste the output)
3. Browser console errors (F12 → Console tab)
4. Anything unusual in PHP error log

### Files to Check Manually
1. `/public/crm/portfolio/recommendations-data.php` - Main query file
2. `/public/crm/portfolio/index.php` (lines 34-44) - How data is loaded
3. `/public/crm/portfolio/index.php` (lines 869-874) - Empty state check
4. `/public/crm/portfolio/index.php` (lines 891-932) - Table rendering

## Verification Checklist

When the fix is working correctly, you should be able to:

- [ ] See 141 recommendations in the table
- [ ] See stats showing: Total 141, New 141, Accepted 0, Applied 0
- [ ] Filter by Status (new/accepted/applied/ignored)
- [ ] Filter by Type (create_page/improve_page/title_meta)
- [ ] Filter by Target (Vancouver/Burnaby/Richmond)
- [ ] Filter by Season
- [ ] Sort by Priority Score, Query, Volume, Position, Status, or Date
- [ ] Click "Accept" button on a recommendation
- [ ] Click "Ignore" button on a recommendation
- [ ] Pagination works if > 25 recommendations

## Files That Were Modified

Main fix:
- `/public/crm/portfolio/recommendations-data.php` (Removed window function, added separate count query)

Documentation created:
- `RECOMMENDATIONS_FIX_SUMMARY.md` (Technical summary)
- `RECOMMENDATIONS_IMPLEMENTATION_COMPLETE.md` (Detailed explanation)
- `RECOMMENDATIONS_TROUBLESHOOTING_CHECKLIST.md` (This file)

Debug tools created (safe to delete):
- `/public/crm/portfolio/verify-fix.php`
- `/public/crm/portfolio/debug-recommendations.php`
- `/public/crm/portfolio/direct-db-test.php`
- `/public/crm/portfolio/check-mysql-version.php`
- `/public/crm/portfolio/test-recommendations-query.php`

## Quick Command Reference

### Hard Refresh (Clear Cache)
- **Windows/Linux**: Ctrl + Shift + R
- **Mac**: Cmd + Shift + R

### Check Browser Console
- Press **F12** to open Developer Tools
- Click **Console** tab
- Look for red error messages

### Check Database Directly
1. Go to phpMyAdmin
2. Select your database (mowology_landscape_crm)
3. Click on seo_recommendations table
4. Check if rows are there
5. Run query: `SELECT COUNT(*) FROM seo_recommendations`

## Still Need Help?

If after following all these steps the recommendations still don't show:

1. Take screenshot of debug-recommendations.php output
2. Note which tests PASS and which FAIL
3. Check PHP error log
4. Verify database has data in seo_recommendations table
5. Contact support with this information

---

**Last Updated**: After MySQL 5.7 compatibility fix
**Status**: Ready for testing
**Expected Result**: All 141 recommendations displaying in table
