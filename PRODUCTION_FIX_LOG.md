# Production Fixes Log

## Issue 3: Undefined SITE_URL Constant in Google Search Console Module

**Date:** February 7, 2026
**Severity:** Critical
**Status:** ✅ Fixed
**Commit:** `5ecc582`

### Error Message
```
Fatal error: Uncaught Error: Undefined constant "SITE_URL" in
/home/mowology/public_html/crm/gsc/connect.php:116
```

### Root Cause

The `SITE_URL` constant is only defined in `public/includes/bootstrap.php` (public site only), not in the CRM context. Three instances in `/crm/gsc/connect.php` tried to use this undefined constant.

### Solution

Replaced all references with hardcoded production URL variable:

```php
// ❌ WRONG
$stmt->execute([SITE_URL ?? 'https://mowology.ca']);

// ✅ CORRECT
$siteUrl = 'https://mowology.ca';
$stmt->execute([$siteUrl]);
```

### Files Changed
- `public/crm/gsc/connect.php` (lines 79, 106, 116)

### Affected Features
- Google Search Console OAuth connection
- GSC status display
- GSC disconnection

---

## Issue 2: PHP 8.1 Deprecation Warnings - Null to htmlspecialchars()

**Date:** February 7, 2026
**Severity:** Medium (deprecation warning, not breaking)
**Status:** ✅ Fixed
**Commit:** `84fe0b4`

### Error Message
```
Deprecated: htmlspecialchars(): Passing null to parameter #1 ($string) of type string
is deprecated in /home/mowology/public_html/crm/jobs/view.php on line 277
```

### Root Cause

Several database queries use LEFT JOINs which can return null values. When these null values were passed directly to `htmlspecialchars()`, PHP 8.1+ throws deprecation warnings.

**Affected lines in `public/crm/jobs/view.php`:**
- Line 192: `$job['job_number']` (from page title)
- Line 209: `$job['title']` (from job title display)
- Line 277: `$job['company_name']` (PRIMARY - the reported error)
- Line 288-290: `$job['contact_phone']` and `$job['billing_phone']`
- Line 296: `$job['property_address']` and `$job['property_city']`

### Solution

Added null coalescing operators (`??`) to provide default values before passing to `htmlspecialchars()`:

```php
// ❌ WRONG - can pass null
echo htmlspecialchars($job['company_name']);

// ✅ CORRECT - always passes a string
echo htmlspecialchars($job['company_name'] ?? 'N/A');
```

### Files Changed
- `public/crm/jobs/view.php` (5 sections fixed)

### Deployment Notes

This fix is recommended but not critical - it eliminates deprecation warnings that may become errors in PHP 9.0+. Include in the next regular update.

---

## Issue: Duplicate crm/ Directory in Include Paths

**Date:** February 7, 2026
**Severity:** Critical
**Status:** ✅ Fixed
**Commit:** `de32198`

### Error Message
```
Warning: require_once(/home/mowology/public_html/crm/crm/includes/roi-functions.php):
Failed to open stream: No such file or directory in
/home/mowology/public_html/crm/includes/functions.php on line 288

Fatal error: Uncaught Error: Failed opening required
'/home/mowology/public_html/crm/crm/includes/roi-functions.php'
```

### Root Cause

Two include statements in `public/crm/includes/functions.php` were using incorrect path resolution:

**Lines 271 and 288:**
```php
// ❌ WRONG - creates duplicate /crm/crm/ path
require_once dirname(__DIR__) . '/crm/includes/roi-functions.php';

// ✅ CORRECT - stays in same directory
require_once __DIR__ . '/roi-functions.php';
```

**Context:**
- File location: `/public/crm/includes/functions.php`
- Target file: `/public/crm/includes/roi-functions.php`
- `__DIR__` = `/public/crm/includes`
- `dirname(__DIR__)` = `/public/crm`
- Adding `/crm/includes/` to that creates `/public/crm/crm/includes/` ❌

### Solution

Changed both lines to use `__DIR__` directly since the target file is in the same directory:

**Line 271:**
```php
require_once __DIR__ . '/roi-functions.php';
```

**Line 288:**
```php
require_once __DIR__ . '/roi-functions.php';
```

### Files Changed
- `public/crm/includes/functions.php` (2 line changes)

### Affected Features
- Quote to Job conversion (`createJobFromQuote()` function)
- ROI attribution tracking
- Contact status updates when creating jobs from quotes

### Deployment Notes

This fix is **critical for production**. The error occurs when:
1. User accepts a quote (changes status to 'accepted')
2. System creates a job from the accepted quote
3. Code attempts to load `roi-functions.php` but path is wrong

**Action:** Include commit `de32198` in the next production deployment.

### Testing on Live Server

After deploying, verify:
1. Accept a quote in the CRM
2. Create a job from the quote
3. Confirm no PHP warnings/errors appear
4. Check that ROI data is correctly logged

### Similar Issues Checked

Searched entire `public/crm/` directory for other instances of this pattern:
```bash
grep -r "dirname(__DIR__) . '/crm/" /public/crm/
```

**Result:** No other instances found. Issue isolated to these two lines.

---

## Current Deployable Commits

**Priority order for deployment:**

| Priority | Commit | Date | Description | Severity |
|----------|--------|------|-------------|----------|
| **1 (Critical)** | `de32198` | Feb 7 | Fix duplicate crm/ directory in include paths | 🔴 Critical |
| **1 (Critical)** | `5ecc582` | Feb 7 | Fix undefined SITE_URL constant in GSC module | 🔴 Critical |
| **2 (Recommended)** | `84fe0b4` | Feb 7 | Fix PHP 8.1 deprecation warnings in job view | 🟡 Medium |
| **Base** | `0a17913` | Feb 7 | Initial commit: Mowology CRM foundation | ✅ Foundation |

**Deployment note:** Deploy commits de32198 and 5ecc582 first (both critical). Then 84fe0b4 is optional (preventive). All are safe to deploy together.
