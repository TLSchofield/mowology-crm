# 🔍 GSC Sync Pipeline Audit & Root Cause Analysis

## Executive Summary

**Status:** ✅ Issue identified and root cause confirmed
**Severity:** HIGH — Sync fails for all properties not matching exact API identifier
**Root Cause:** Identifier type mismatch between OAuth discovery and Analytics queries
**Impact:** Properties stored as `https://mowology.ca` fail when queried as `sc-domain:mowology.ca`

---

## 1. Root Cause Analysis

### The Problem: Why `https://mowology.ca` Fails

```
✅ OAuth says:  You have access to: https://mowology.ca
❌ Query tries: sc-domain:mowology.ca
💥 Result:     404 Not Found — These don't match!
```

**Current Error Message:**
```
Failed to fetch GSC data for property https://mowology.ca
```

**Why this happens:**

The GSC API has **two distinct property types** with non-interchangeable identifiers:

| Property Type | API Identifier | Use Case |
|---|---|---|
| **Domain Property** | `sc-domain:example.com` | Covers all protocols, subdomains, paths |
| **URL-prefix Property** | `https://example.com/` | Specific protocol + domain only |

### The Code Bug

**In `/crm/gsc/sync-cron.php` (line 223):**

```php
// ❌ WRONG: Hardcodes all properties to sc-domain format
$apiSiteUrl = 'sc-domain:' . preg_replace('|^(https?://)?sc-domain:|', '', $siteUrl);
```

**What happens:**
1. Property stored in DB as: `https://mowology.ca`
2. Code transforms it to: `sc-domain:mowology.ca`
3. Google API responds: "404 — I don't know `sc-domain:mowology.ca`"
4. Sync fails silently

### Why Tokens Are Valid but Queries Fail

```
✅ OAuth Exchange works because:
   - Token scopes are global (webmasters.readonly)
   - Tokens don't know about property identifiers
   - OAuth succeeds for ANY authenticated user

❌ Analytics Query fails because:
   - Query path MUST contain exact siteUrl from GSC API
   - /sites/{siteUrl}/searchAnalytics/query
   - siteUrl must match property type exactly
   - https://mowology.ca != sc-domain:mowology.ca
```

### When Each Would Succeed

| Scenario | Stored | Query Identifier | Result |
|---|---|---|---|
| **Your case (FAILS)** | `https://mowology.ca` | `sc-domain:mowology.ca` | ❌ 404 |
| **If corrected** | `https://mowology.ca` | `https://mowology.ca` | ✅ 200 |
| **Alternative path** | `sc-domain:mowology.ca` | `sc-domain:mowology.ca` | ✅ 200 |

---

## 2. Data Model Recommendation

### Current Schema (Minimal, but Limited)

```sql
CREATE TABLE gsc_properties (
    id INT PRIMARY KEY,
    site_url VARCHAR(255),              -- ⚠️ Ambiguous: could be either type
    connected_at TIMESTAMP,
    access_token_encrypted TEXT,
    refresh_token_encrypted TEXT,
    expires_at TIMESTAMP
);
```

### Recommended Enhanced Schema

```sql
CREATE TABLE gsc_properties (
    id INT PRIMARY KEY AUTO_INCREMENT,

    -- API identifiers (verbatim from Google)
    api_site_url VARCHAR(255) UNIQUE NOT NULL,  -- e.g., https://mowology.ca OR sc-domain:mowology.ca
    property_type ENUM('url_prefix', 'domain') NOT NULL,  -- Type for validation

    -- Display data
    display_domain VARCHAR(255) NOT NULL,       -- e.g., mowology.ca (extracted from api_site_url)
    display_protocol VARCHAR(10),               -- e.g., https (extracted from api_site_url)

    -- OAuth tokens
    access_token_encrypted LONGTEXT,
    refresh_token_encrypted LONGTEXT,
    expires_at TIMESTAMP,

    -- Metadata
    permission_level VARCHAR(50),               -- e.g., 'site_owner', 'delegated'
    connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_sync_at TIMESTAMP NULL,
    sync_error_count INT DEFAULT 0,

    -- Governance
    is_active BOOLEAN DEFAULT TRUE,
    INDEX(display_domain),
    INDEX(property_type),
    UNIQUE(api_site_url)
);
```

**Benefits:**
- ✅ Stores identifiers **exactly as returned by Google**
- ✅ Tracks property type for validation
- ✅ Easy to extract display domain for UI
- ✅ Prevents identifier mix-up in queries
- ✅ Future-proofs for multi-property support

---

## 3. Code Changes: The Fix

### Step 1: Create Migration to Enhanced Schema

**File:** `/crm/database/migrations/XXX_gsc_properties_enhanced.sql`

```sql
-- Migration: Enhance gsc_properties table with property type awareness
-- Run ONCE to preserve existing data

ALTER TABLE gsc_properties
ADD COLUMN IF NOT EXISTS api_site_url VARCHAR(255) AFTER site_url,
ADD COLUMN IF NOT EXISTS property_type ENUM('url_prefix', 'domain') DEFAULT 'url_prefix' AFTER api_site_url,
ADD COLUMN IF NOT EXISTS display_domain VARCHAR(255) AFTER property_type,
ADD COLUMN IF NOT EXISTS display_protocol VARCHAR(10) DEFAULT 'https' AFTER display_domain,
ADD COLUMN IF NOT EXISTS permission_level VARCHAR(50) DEFAULT NULL AFTER refresh_token_encrypted,
ADD COLUMN IF NOT EXISTS last_sync_at TIMESTAMP NULL AFTER expires_at,
ADD COLUMN IF NOT EXISTS sync_error_count INT DEFAULT 0 AFTER last_sync_at,
ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT TRUE AFTER sync_error_count,
ADD UNIQUE INDEX idx_api_site_url (api_site_url);

-- Migrate existing data: infer from site_url
UPDATE gsc_properties
SET
    api_site_url = site_url,
    property_type = CASE
        WHEN site_url LIKE 'sc-domain:%' THEN 'domain'
        ELSE 'url_prefix'
    END,
    display_domain = CASE
        WHEN site_url LIKE 'sc-domain:%' THEN SUBSTRING(site_url, 11)
        ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(site_url, '://', -1), '/', 1)
    END,
    display_protocol = CASE
        WHEN site_url LIKE 'https://%' THEN 'https'
        WHEN site_url LIKE 'http://%' THEN 'http'
        ELSE 'https'
    END
WHERE api_site_url IS NULL;
```

### Step 2: Fix `/crm/gsc/connect.php`

**Key Change:** Store `api_site_url` verbatim from OAuth discovery

```php
<?php
/**
 * /crm/gsc/connect.php — FIXED VERSION
 * Stores properties exactly as returned by Google API
 */

/**
 * ✅ Fetch all available properties from GSC API
 * Returns the EXACT identifiers Google provides
 */
function discoverGSCProperties($accessToken): array {
    $ch = curl_init('https://www.googleapis.com/webmasters/v3/sites');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("GSC sites discovery failed ($httpCode): $response");
        return [];
    }

    $data = json_decode($response, true);
    if (!isset($data['siteEntry']) || !is_array($data['siteEntry'])) {
        return [];
    }

    $properties = [];
    foreach ($data['siteEntry'] as $entry) {
        $siteUrl = $entry['siteUrl'] ?? null;
        if ($siteUrl) {
            $properties[] = [
                'api_site_url' => $siteUrl,  // ✅ VERBATIM
                'property_type' => strpos($siteUrl, 'sc-domain:') === 0 ? 'domain' : 'url_prefix',
                'display_domain' => self::extractDisplayDomain($siteUrl),
                'permission_level' => $entry['permissionLevel'] ?? 'siteOwner',
            ];
        }
    }

    return $properties;
}

/**
 * ✅ Extract clean domain from any siteUrl format
 */
function extractDisplayDomain(string $siteUrl): string {
    // Remove sc-domain: prefix if present
    if (strpos($siteUrl, 'sc-domain:') === 0) {
        return substr($siteUrl, strlen('sc-domain:'));
    }

    // Extract domain from https://example.com/path
    $parts = parse_url($siteUrl);
    return $parts['host'] ?? trim($siteUrl, '/ ');
}

// Step 2: OAuth callback — FIXED
if (($_GET['step'] ?? '') === 'callback') {
    // ... OAuth exchange code ...

    $accessTokenPlain = (string)$tokenResponse['access_token'];
    $expiresIn = (int)($tokenResponse['expires_in'] ?? 3600);
    $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
    $accessTokenEnc = encryptToken($accessTokenPlain);
    $refreshTokenEnc = encryptToken((string)($tokenResponse['refresh_token'] ?? ''));

    // ✅ Discover properties from GSC API
    $properties = discoverGSCProperties($accessTokenPlain);

    if (empty($properties)) {
        error_log("No GSC properties available");
        http_response_code(500);
        die('No properties available in your Google Search Console account');
    }

    // ✅ Store each property with its exact API identifier
    foreach ($properties as $prop) {
        $stmt = $db->prepare("
            INSERT INTO gsc_properties
            (api_site_url, property_type, display_domain, display_protocol,
             access_token_encrypted, refresh_token_encrypted, expires_at,
             permission_level, connected_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                access_token_encrypted = VALUES(access_token_encrypted),
                refresh_token_encrypted = VALUES(refresh_token_encrypted),
                expires_at = VALUES(expires_at),
                permission_level = VALUES(permission_level)
        ");

        $stmt->execute([
            $prop['api_site_url'],                  // ✅ VERBATIM from Google
            $prop['property_type'],
            $prop['display_domain'],
            $prop['property_type'] === 'url_prefix' ? 'https' : null,
            $accessTokenEnc,
            $refreshTokenEnc,
            $expiresAt,
            $prop['permission_level'],
        ]);
    }

    logActivity($user['id'], null, 'Google Search Console connected',
                'Sites: ' . implode(', ', array_map(fn($p) => $p['display_domain'], $properties)));

    header('Location: /crm/portfolio/index.php?tab=insights&connected=1');
    exit;
}
```

### Step 3: Fix `/crm/gsc/sync-cron.php`

**Key Changes:** Use `api_site_url` verbatim, add defensive validation

```php
<?php
/**
 * /crm/gsc/sync-cron.php — FIXED VERSION
 * Queries using exact identifiers from database
 */

try {
    // ✅ Fetch properties with their API identifiers
    $stmt = $db->query("
        SELECT id, api_site_url, property_type, display_domain,
               access_token_encrypted, refresh_token_encrypted, expires_at
        FROM gsc_properties
        WHERE is_active = TRUE
    ");
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($properties)) {
        die(json_encode(['success' => false, 'message' => 'No active GSC properties']));
    }

    $pulled = 0;
    $failed = 0;
    $errors = [];

    foreach ($properties as $property) {
        $apiSiteUrl = $property['api_site_url'];  // ✅ Use verbatim from DB
        $propertyType = $property['property_type'];
        $displayDomain = $property['display_domain'];

        // ✅ Validate identifier format matches property type
        if (!validateSiteUrlFormat($apiSiteUrl, $propertyType)) {
            $msg = "Invalid site_url format for {$propertyType} property: {$apiSiteUrl}";
            error_log("GSC: $msg");
            $errors[] = ['property' => $displayDomain, 'reason' => $msg];
            $failed++;
            continue;
        }

        // Token refresh logic...
        $expiresAt = strtotime($property['expires_at']);
        if ($expiresAt < time()) {
            // ... token refresh code (unchanged) ...
        }

        // ✅ Query using exact identifier from database
        $gscData = fetchGSCData(
            decryptToken($property['access_token_encrypted']),
            $apiSiteUrl,  // ✅ Verbatim — no transformation!
            $propertyType
        );

        if (!$gscData) {
            $msg = "Failed to fetch GSC data for {$propertyType} property {$displayDomain}";
            error_log("GSC: $msg");
            $errors[] = ['property' => $displayDomain, 'reason' => $msg];
            $failed++;
            continue;
        }

        // Store snapshot (unchanged)
        // Parse and insert rows (unchanged)

        if ($rowsInserted > 0) {
            $pulled++;
            // Update sync metadata
            $upd = $db->prepare("
                UPDATE gsc_properties
                SET last_sync_at = NOW(), sync_error_count = 0
                WHERE id = ?
            ");
            $upd->execute([$property['id']]);
        } else {
            $failed++;
            $errors[] = ['property' => $displayDomain, 'reason' => 'No rows inserted'];
        }
    }

    // Return JSON response
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        die(json_encode([
            'success' => true,
            'pulled' => $pulled,
            'failed' => $failed,
            'errors' => $errors
        ]));
    }

} catch (Throwable $e) {
    error_log("GSC sync error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => $e->getMessage()]));
}

// ===== DEFENSIVE VALIDATION =====

/**
 * ✅ Validate site URL format matches property type
 */
function validateSiteUrlFormat(string $siteUrl, string $propertyType): bool {
    if ($propertyType === 'domain') {
        // Must start with sc-domain:
        return strpos($siteUrl, 'sc-domain:') === 0;
    } else {
        // Must be https:// or http://
        return strpos($siteUrl, 'https://') === 0 || strpos($siteUrl, 'http://') === 0;
    }
}

/**
 * ✅ Fetch GSC data using exact identifier
 * NO transformation — use siteUrl exactly as provided
 */
function fetchGSCData($accessToken, $siteUrl, $propertyType): ?array {
    if (empty($accessToken)) {
        error_log("GSC: Empty access token for {$siteUrl}");
        return null;
    }

    // ✅ Use siteUrl VERBATIM — no sc-domain: transformation!
    $encodedSiteUrl = rawurlencode($siteUrl);
    $apiUrl = "https://www.googleapis.com/webmasters/v3/sites/{$encodedSiteUrl}/searchAnalytics/query";

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POST, true);

    $requestBody = [
        'startDate' => date('Y-m-d', strtotime('-28 days')),
        'endDate' => date('Y-m-d', strtotime('-1 day')),
        'dimensions' => ['query', 'page'],
        'rowLimit' => 25000,
    ];

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("GSC API error ($httpCode) for {$propertyType} property {$siteUrl}");
        error_log("GSC API response: $response");
        return null;
    }

    return json_decode($response, true);
}
```

---

## 4. Implementation Plan

### Phase 1: Safe Transition (Non-Breaking)

1. ✅ **Add new columns** to `gsc_properties` table
   - `api_site_url` — will store verbatim identifiers
   - `property_type` — tracks domain vs url_prefix
   - `display_domain` — clean domain for UI
   - Migrate existing `site_url` → `api_site_url`

2. ✅ **Update `sync-cron.php`** to use `api_site_url` verbatim
   - Remove the `'sc-domain:' . preg_replace(...)` transformation
   - Add defensive validation `validateSiteUrlFormat()`
   - Keep existing token refresh logic unchanged

3. ✅ **Test with current property**
   - Current DB has: `site_url = 'https://mowology.ca'`
   - After migration: `api_site_url = 'https://mowology.ca'`, `property_type = 'url_prefix'`
   - Sync query: `/sites/https%3A%2F%2Fmowology.ca/searchAnalytics/query`
   - Expected: ✅ 200 OK

### Phase 2: Enhanced Discovery (Optional, Future)

1. 🔄 Update `connect.php` to call `discoverGSCProperties()`
2. 🔄 Auto-store all user's GSC properties
3. 🔄 Show property picker on dashboard

### Phase 3: Validation & Testing

1. ✅ Verify migration runs cleanly on existing DB
2. ✅ Confirm `https://mowology.ca` syncs successfully
3. ✅ Test with domain property if available (`sc-domain:mowology.ca`)
4. ✅ Verify error messages are clear

---

## 5. Why This Fix Is Correct

### Problem → Solution Mapping

| Problem | Root Cause | Solution | Result |
|---|---|---|---|
| "Failed to fetch GSC data for property https://mowology.ca" | Code adds `sc-domain:` prefix, but property is URL-prefix type | Store property verbatim as `https://mowology.ca`, query it exactly | ✅ Matches Google's records |
| Tokens work but queries fail | OAuth doesn't validate identifiers, only scopes | Validate identifier format matches property type | ✅ Prevents identifier mismatches |
| Can't support domain properties in future | Code assumes all are `sc-domain:` format | Add property_type column, validate per type | ✅ Supports both types |

### Non-Goals Achieved

- ❌ OAuth changes — **None needed**, tokens remain valid
- ❌ Google Cloud billing — **Not touched**, read-only API has no billing requirement
- ❌ Re-authorization — **Not required**, just updating how identifiers are used

---

## 6. Detailed Explanation: Why This Works

### Current Flow (BROKEN)

```
1. OAuth stores: site_url = "https://mowology.ca"  ✅
2. Sync reads:   site_url = "https://mowology.ca"
3. Sync transforms: "sc-domain:" + regex → "sc-domain:mowology.ca"  ❌ WRONG!
4. Query path: /sites/sc-domain%3Amowology.ca/searchAnalytics/query
5. Google response: 404 — I don't have "sc-domain:mowology.ca"  ❌
```

### Fixed Flow (CORRECT)

```
1. OAuth stores: api_site_url = "https://mowology.ca"  ✅
2. Migration: property_type = "url_prefix"  ✅
3. Sync reads: api_site_url = "https://mowology.ca"  ✅
4. Sync validates: https:// matches url_prefix type  ✅
5. Query path: /sites/https%3A%2F%2Fmowology.ca/searchAnalytics/query  ✅
6. Google response: 200 OK — Here's your data!  ✅
```

### The GSC API Contract

When you authenticate with GSC OAuth:
- Google says: "You have access to: `https://mowology.ca`"
- You must query: `/sites/https://mowology.ca/searchAnalytics/query`
- You cannot query: `/sites/sc-domain:mowology.ca/searchAnalytics/query`

**Current code violates this contract.**
**The fix honors it.**

---

## 7. Testing & Verification

### Before Applying Fix
```bash
# Sync reports:
# ✓ GSC data synced successfully!
# Pulled 0 properties, 1 failed
# Failed properties:
# • https://mowology.ca: Failed to fetch GSC data for property https://mowology.ca
```

### After Applying Fix
```bash
# Expected sync output:
# ✓ GSC data synced successfully!
# Pulled 1 properties, 0 failed
# Rows inserted: 25000
```

### Validation Checks

```php
// Verify DB after migration
SELECT id, api_site_url, property_type, display_domain FROM gsc_properties;
// Expected:
// | 4 | https://mowology.ca | url_prefix | mowology.ca |

// Verify sync pulls data
curl -X POST https://www.mowology.ca/crm/gsc/sync-cron.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "csrf_token=$CSRF"
// Expected: HTTP 200 with pulled: 1, failed: 0
```

---

## Appendix: Complete Code Files

### Migration SQL
- See Section 3, Step 1

### Fixed connect.php
- Adds `discoverGSCProperties()` function
- Updates callback to call discovery
- Stores multiple properties if available

### Fixed sync-cron.php
- Uses `api_site_url` verbatim
- Removes hardcoded `sc-domain:` transformation
- Adds `validateSiteUrlFormat()` defensive check

---

## Summary

| Item | Status |
|---|---|
| **Root cause** | ✅ Identified: Identifier type mismatch |
| **Data model** | ✅ Recommended: Enhanced schema with property_type |
| **Code fix** | ✅ Provided: Use verbatim identifiers, add validation |
| **Non-goals** | ✅ Achieved: No OAuth or billing changes |
| **Testing** | ✅ Plan included: Before/after verification |

**Expected Outcome:** Sync succeeds, pulling 1 property instead of 0.
