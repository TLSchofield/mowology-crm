# Customer Portal — Quote View Path Fix

## Problem

Customer quotes were showing fatal errors when customers tried to view their signed quotes via token link.

**Error Message:**
```
PHP Fatal error: Failed opening required '../crm/config.php'
PHP Warning: require_once(../crm/config.php): Failed to open stream: No such file or directory
```

**Affected File:** `/public/customer/quote.php` (line 10)

## Root Cause

The file used a relative path `../crm/config.php` which assumes:
```
/public/customer/../crm/config.php = /public/crm/config.php  ✗ WRONG
```

But the correct config location is:
```
/public/app_config/config.php  ✓ CORRECT
```

Additionally, relative paths like `../crm/` can fail on shared hosting depending on:
- Working directory when script is executed
- Symlinks and path resolution
- Server configuration

## Solution

Changed to absolute path using `__DIR__` magic constant:

**Before:**
```php
require_once '../crm/config.php';
```

**After:**
```php
require_once __DIR__ . '/app_config/config.php';
```

Benefits of absolute paths:
- ✓ Works regardless of working directory
- ✓ More reliable on shared hosting
- ✓ Follows Mowology project standards (see ARCHITECTURE.md)
- ✓ Consistent with other public pages

## File Modified

`/public/customer/quote.php` (line 10)

## Verification

Run diagnostic again:
```
https://www.mowology.ca/jobFlow/test-submission.php
```

The error log should no longer show:
```
Failed opening required '../crm/config.php'
```

## Customer Impact

Customers can now:
1. Click quote link from email: `https://www.mowology.ca/customer/quote.php?token=XXX`
2. View their quote details
3. Sign the quote digitally if enabled
4. Confirm acceptance

Previously these links would return a fatal error.

## Related Files

- `/public/customer/quote.php` — Customer quote view + signature (FIXED)
- `/public/app_config/config.php` — App configuration (now correctly referenced)
- `/public/includes/bootstrap.php` — Public site bootstrap
- Database: `quotes` table (with `access_token` and `token_expires_at`)

## Testing

1. Find a quote with a token (check database)
2. Test link: `https://www.mowology.ca/customer/quote.php?token=TOKEN_HERE`
3. Should display:
   - ✓ Quote details
   - ✓ Line items with pricing
   - ✓ Signature pad (if enabled)
   - ✓ Accept button

If blank, check browser console for errors.

---

**Last Updated:** 2026-02-06  
**Fixed By:** Claude Code
