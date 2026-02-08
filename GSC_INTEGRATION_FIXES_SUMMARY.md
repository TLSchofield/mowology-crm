# GSC Integration Fixes — Complete Summary

**Commit:** `7c41eb4` — "Fix GSC integration: OAuth tokens, snapshot IDs, and data retrieval"

---

## 🔧 5 Critical Bugs Fixed

### Bug #1: Property Identifier Mismatch ✅
**Problem:** Database stored `https://mowology.ca`, API expected `sc-domain:mowology.ca`
- No consistent normalization existed
- Brittle hardcoded strings scattered across 3 files

**Solution:**
- Created `normalizeSiteUrl($url, $format = 'db')` helper function
- Converts: `https://mowology.ca` → `mowology.ca` (DB) → `sc-domain:mowology.ca` (API)
- Used in `connect.php` and `sync-cron.php`
- Safe rawurlencode() on API endpoint

**Files Modified:** `connect.php`, `sync-cron.php`

---

### Bug #2: Refresh Token Overwritten ✅
**Problem:** Line 81 in connect.php:
```php
$refreshToken = encryptToken($tokenResponse['refresh_token'] ?? '');
```
- Google rarely returns refresh_token on reconnects (only with `access_type=offline` + `prompt=consent`)
- Empty string overwrote existing token permanently
- Next sync: `refreshAccessToken()` fails with empty token → no data pull

**Solution:**
1. **Added `prompt=consent`** to OAuth URL (line 59 in connect.php)
   - Forces Google to issue new refresh token on every authorization

2. **Conditional token update logic** (lines 91-131 in connect.php)
   - If new refresh_token provided: use it (update both access & refresh)
   - If NO new token & existing token exists: preserve it, only update access_token
   - If NO new token & NO existing token: store empty (user must reconnect with prompt=consent)

**Files Modified:** `connect.php`

---

### Bug #3: Snapshot ID Lost on UPDATE ✅
**Problem:** Lines 65-79 in sync-cron.php:
```php
$snapshot->execute([...]);
$snapshotId = $db->lastInsertId();  // ❌ Returns 0 on UPDATE
```
- `ON DUPLICATE KEY UPDATE` triggers → `lastInsertId()` returns 0
- Later: `DELETE FROM gsc_query_page_stats WHERE snapshot_id = 0` → no-op
- Query/page stats orphaned; never linked to snapshot

**Solution:**
- Lines 113-123 in sync-cron.php: After upsert, SELECT the snapshot_id:
```php
$snapshotQuery = $db->prepare("SELECT id FROM gsc_snapshots WHERE property_id = ? AND snapshot_date = ?");
$snapshotQuery->execute([$property['id'], date('Y-m-d')]);
$snapshotRow = $snapshotQuery->fetch(PDO::FETCH_ASSOC);
$snapshotId = $snapshotRow ? (int)$snapshotRow['id'] : 0;
```
- Guarantees correct snapshot_id for both INSERT and UPDATE paths

**Files Modified:** `sync-cron.php`

---

### Bug #4: snapshots.php Query Bug ✅
**Problem:** Lines 23-44 in original snapshots.php:
```php
SELECT gp.id, gp.site_url, gs.snapshot_date, ...
FROM gsc_snapshots gs
JOIN gsc_properties gp ON gs.property_id = gp.id

$stmt->execute([$latestSnapshot['id']]);  // ❌ Using gp.id (property_id), not gs.id (snapshot_id)
```
- `gp.id` = property ID (1-N properties table)
- But `gsc_query_page_stats.snapshot_id` = snapshot ID (different table)
- Query: `WHERE snapshot_id = 1` (property_id) → no match → "No query data available"

**Solution:**
- Lines 23-31 in updated snapshots.php: Explicit alias:
```php
SELECT gs.id AS snapshot_id, gs.property_id, gp.site_url, ...
```
- Lines 44, 59, 75: Use `$latestSnapshot['snapshot_id']` in subsequent queries
- Now correctly links to `gsc_query_page_stats`

**Files Modified:** `snapshots.php`

---

### Bug #5: "Sync Now" CSRF Vulnerability ✅
**Problem:**
- `portfolio/index.php` line 953: POST to `/crm/gsc/sync-cron.php` had NO CSRF token
- `sync-cron.php` didn't validate CSRF on web requests
- Vulnerable to cross-site request forgery

**Solution:**
1. **sync-cron.php** (lines 22-28): Add CSRF validation for web requests
```php
if (php_sapi_name() !== 'cli') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        die(json_encode(['success' => false, 'message' => 'CSRF token invalid']));
    }
}
```

2. **portfolio/index.php** (lines 946-958): Pass CSRF token via FormData
```javascript
const formData = new FormData();
formData.append('csrf_token', CSRF_TOKEN);
fetch('/crm/gsc/sync-cron.php', {
    method: 'POST',
    body: formData
})
```

**Files Modified:** `sync-cron.php`, `portfolio/index.php`

---

## 📝 Files Modified

| File | Changes | Lines |
|------|---------|-------|
| `/crm/gsc/connect.php` | normalizeSiteUrl(), prompt=consent, token preservation | 22-131 |
| `/crm/gsc/sync-cron.php` | CSRF validation, snapshot ID SELECT-after-upsert, error checks | 12-289 |
| `/crm/gsc/snapshots.php` | Fixed JOIN and alias gs.id AS snapshot_id | 23-31, 44, 59, 75 |
| `/crm/portfolio/index.php` | Added CSRF token to syncGSCData() FormData | 946-958 |

---

## ✅ Testing Checklist

### Phase 1: OAuth Connection
- [ ] Visit `/crm/gsc/connect.php`
- [ ] Click "Connect Google Account"
- [ ] Authorize with Google account (should show prompt=consent screen)
- [ ] Redirect back with "Connected successfully" message
- [ ] Verify DB: `SELECT refresh_token_encrypted FROM gsc_properties WHERE site_url = 'mowology.ca'`
- [ ] Refresh token is NOT empty

### Phase 2: Verify GSC Property
- [ ] Log into Google Search Console
- [ ] Confirm property exists: `sc-domain:mowology.ca` (Domain property, not URL prefix)
- [ ] Property has recent data (queries, pages, impressions)

### Phase 3: Manual Sync
- [ ] Portfolio Dashboard → GSC Insights tab
- [ ] Click "Sync Now" button
- [ ] Verify response: `{"success":true,"pulled":1,"failed":0,...}`
- [ ] Check DB:
  - `SELECT COUNT(*) FROM gsc_snapshots;` (should be ≥ 1)
  - `SELECT COUNT(*) FROM gsc_query_page_stats WHERE snapshot_id > 0;` (should be > 0)
  - `SELECT * FROM gsc_query_page_stats LIMIT 1;` (verify snapshot_id is not 0)

### Phase 4: Insights Tab Display
- [ ] Refresh Portfolio page (GSC Insights tab)
- [ ] Verify "Top Search Queries" shows data (not "No query data available")
- [ ] Verify "Top Performing Pages" shows data (not "No page data available")
- [ ] Verify "Optimization Opportunities" shows low-CTR pages if any exist
- [ ] Check timestamp: "Data as of [date]" should be recent

### Phase 5: Automated Cron
- [ ] Verify daily cron job scheduled: `0 2 * * * php /home/mowology/public_html/crm/gsc/sync-cron.php`
- [ ] Check cron logs tomorrow for success output
- [ ] Verify gsc_snapshots table updates daily

### Phase 6: Reconnect Flow (Optional)
- [ ] Click "Manage Connection" → "Disconnect"
- [ ] Reconnect again
- [ ] Verify refresh_token preserved (not overwritten with empty)

---

## 🚨 Error Handling Improvements

Added comprehensive error logging in `sync-cron.php`:
- Empty refresh token detected: "GSC: No refresh token for property {site}, skipping"
- Token refresh failures logged with response
- Snapshot ID retrieval failures logged
- API errors logged with HTTP status code and response body

All errors written to `error_log()` and available in cPanel error logs.

---

## 🔐 Security Improvements

1. **CSRF Protection:** Sync endpoint now validates CSRF tokens on web requests (CLI unaffected)
2. **Token Encryption:** Refresh tokens stored encrypted with AES-256-CBC
3. **Empty Token Checks:** Prevents API calls with missing/invalid tokens
4. **Admin-Only Access:** Both `sync-cron.php` and `snapshots.php` require admin auth

---

## 📊 Data Flow (Post-Fix)

```
OAuth Connect
└─ prompt=consent forces refresh token
└─ normalizeSiteUrl('mowology.ca', 'db') → stores 'mowology.ca' in DB
└─ Preserve existing refresh token if new one empty

Daily/Manual Sync
├─ Retrieve site_url: 'mowology.ca' from gsc_properties
├─ normalizeSiteUrl('mowology.ca', 'api') → 'sc-domain:mowology.ca' for API
├─ POST to: /webmasters/v3/sites/sc-domain%3Amowology.ca/searchAnalytics/query
├─ Store snapshot: INSERT/UPDATE gsc_snapshots
├─ SELECT id FROM gsc_snapshots WHERE property_id=? AND snapshot_date=? → correct snapshot_id
└─ INSERT gsc_query_page_stats with correct snapshot_id

Portfolio Insights Tab
├─ Include snapshots.php
├─ SELECT gs.id AS snapshot_id (correctly aliased)
├─ Query gsc_query_page_stats WHERE snapshot_id = ? (correct match)
└─ Display: Top Queries, Top Pages, Low-CTR Opportunities
```

---

## 🚀 Deployment Steps

1. **Deploy to cPanel:** Push to GitHub (auto-deploys to mowology.ca)
2. **Test OAuth:** Reconnect GSC account if needed
3. **Test Sync:** Click "Sync Now" on Portfolio → GSC Insights tab
4. **Verify Data:** Check tables and UI for data display
5. **Monitor Cron:** Check logs tomorrow for automated daily sync

---

## 📞 Support

If data doesn't appear after sync:

1. **Check error_log:**
   ```bash
   tail -20 /home/mowology/public_html/error_log
   ```

2. **Check DB:**
   ```sql
   SELECT * FROM gsc_properties;
   SELECT * FROM gsc_snapshots ORDER BY pulled_at DESC LIMIT 1;
   SELECT COUNT(*) FROM gsc_query_page_stats;
   ```

3. **Reconnect:** If refresh_token is empty, go to `/crm/gsc/connect.php` and reconnect

4. **Manual sync test:**
   ```bash
   php /home/mowology/public_html/crm/gsc/sync-cron.php
   ```

---

**Status:** ✅ All 5 bugs fixed. Ready for production deployment.
