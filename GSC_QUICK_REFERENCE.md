# GSC Integration — Quick Reference

## What Was Fixed

| Bug | Root Cause | Fix |
|-----|-----------|-----|
| **Property Mismatch** | Database: `https://mowology.ca` vs API: `sc-domain:mowology.ca` | Added `normalizeSiteUrl()` helper |
| **Refresh Token Lost** | Google doesn't return refresh_token on reconnect | Added `prompt=consent` + conditional preservation |
| **Snapshot ID = 0** | `lastInsertId()` returns 0 on UPDATE | Changed to SELECT-after-upsert |
| **Wrong Snapshot ID** | Query used `gp.id` instead of `gs.id` | Fixed JOIN alias to `gs.id AS snapshot_id` |
| **CSRF Vulnerable** | POST had no CSRF token validation | Added validation + FormData with token |

---

## Post-Deployment Verification

### Quick Test (2 minutes)
```bash
# 1. Check DB has snapshot data
mysql> SELECT COUNT(*) FROM gsc_snapshots;
# Should return: 1 or higher

# 2. Check query/page stats linked correctly
mysql> SELECT snapshot_id, COUNT(*) FROM gsc_query_page_stats GROUP BY snapshot_id;
# Should show: snapshot_id > 0 with rows

# 3. Test "Sync Now" button
# Portfolio → GSC Insights → Click "Sync Now"
# Should show: {"success":true,"pulled":1,"failed":0}
```

### Full Test (10 minutes)
1. Visit `/crm/gsc/connect.php` — should show "Connected since [date]"
2. Portfolio → GSC Insights — should display:
   - ✅ Top Search Queries
   - ✅ Top Performing Pages
   - ✅ Optimization Opportunities (if CTR < 3%)
3. Click "Sync Now" — should reload with latest data
4. Check tomorrow's cron log for automated sync

---

## How It Works Now

### OAuth Flow
```
User clicks "Connect"
→ prompt=consent forces token issue
→ Store: site_url = 'mowology.ca'
→ Preserve refresh token on reconnect
```

### Sync Flow
```
Retrieve property: site_url = 'mowology.ca'
→ Convert to API format: 'sc-domain:mowology.ca'
→ POST to Google API
→ Store snapshot: gsc_snapshots.id = correct ID
→ Link stats: gsc_query_page_stats.snapshot_id = correct ID
```

### Portfolio Display
```
Query gsc_query_page_stats WHERE snapshot_id = gs.id
→ Show Top Queries, Top Pages, Low-CTR Opportunities
```

---

## Files Changed

```
public/crm/gsc/connect.php ............ OAuth + token preservation
public/crm/gsc/sync-cron.php ......... Snapshot ID + CSRF + refresh token checks
public/crm/gsc/snapshots.php ......... Fixed JOIN and snapshot_id alias
public/crm/portfolio/index.php ....... Added CSRF token to sync call
```

---

## Debugging

**Issue: "No query data available"**
- Check: `SELECT COUNT(*) FROM gsc_query_page_stats WHERE snapshot_id > 0;`
- If 0: Snapshot ID bug (should be fixed)
- Check error_log for API errors

**Issue: "Sync failed" error**
- Check: `SELECT refresh_token_encrypted FROM gsc_properties;` — should NOT be empty
- If empty: Reconnect at `/crm/gsc/connect.php`
- Check error_log: `/home/mowology/public_html/error_log`

**Issue: CSRF token error**
- Make sure portfolio/index.php has latest `syncGSCData()` function
- Clear browser cache: Hard refresh (Ctrl+Shift+R)

---

## Commands Reference

### Test Cron
```bash
php /home/mowology/public_html/crm/gsc/sync-cron.php
```

### Check DB
```bash
# Verify connection
SELECT * FROM gsc_properties;

# Check latest snapshot
SELECT * FROM gsc_snapshots ORDER BY pulled_at DESC LIMIT 1;

# Count stats
SELECT COUNT(*) FROM gsc_query_page_stats;
```

### Check Logs
```bash
tail -50 /home/mowology/public_html/error_log | grep GSC
```

---

## Support

**Everything working?** ✅ You're done!

**Having issues?**
1. Check error_log for "GSC:" messages
2. Verify refresh_token not empty in gsc_properties
3. Reconnect if needed: `/crm/gsc/connect.php`
4. Run manual sync test above
5. Hard refresh Portfolio page

---

**Commit:** `7c41eb4`
**Status:** Production Ready ✅
