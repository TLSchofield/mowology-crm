# Marketing/SEO API Migration

**Date:** 2026-02-12

## Migration Summary

All SEO API endpoints have been migrated from `/public/crm/api/seo/` to `/app/Modules/Marketing/Api/`.

## Files Migrated (6 files)

1. **generate.php** - Manually trigger recommendation generation
2. **apply.php** - Apply a recommendation (create draft page)
3. **apply-preview.php** - Preview a recommendation before applying
4. **status.php** - Update recommendation status
5. **targets.php** - Manage SEO targets (city/postcode/neighbourhood)
6. **seasons.php** - Manage SEO seasons (seasonal campaigns)

## Changes Made

### 1. Path Bootstrap
Each migrated file now includes the paths.php bootstrap at the top:
```php
if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}
```

### 2. Path Constant Replacements

Old (incorrect) paths → New paths:

- `dirname(__DIR__, 2) . '/loginAuth/auth.php'` → `PUBLIC_ROOT . '/loginAuth/auth.php'`
- `dirname(__DIR__, 2) . '/includes/seo-functions.php'` → `CRM_INCLUDES . '/seo-functions.php'`
- `dirname(__DIR__, 2) . '/cron/seo_recommendations.php'` → `CRM_ROOT . '/cron/seo_recommendations.php'`

**Note:** The old files were using `dirname(__DIR__, 2)` which was incorrect. From `/public/crm/api/seo/`, that would resolve to `/public/crm/`, not `/public/`. The correct path should have been `dirname(__DIR__, 3)`. This migration fixes that bug.

### 3. Legacy Shims
All original files at `/public/crm/api/seo/` have been replaced with legacy shims that:
- Include the paths.php bootstrap
- Require the new file location
- Include a warning comment not to edit them

Example shim structure:
```php
<?php
/**
 * LEGACY SHIM — <filename>
 * Real logic lives at /app/Modules/Marketing/Api/<filename>
 * DO NOT add new code here. Edit the target file instead.
 */
if (!defined('APP_ROOT')) {
    // ... path bootstrap ...
}
require_once APP_ROOT . '/Modules/Marketing/Api/<filename>';
```

## Backward Compatibility

The legacy shims ensure that any existing code calling the old paths will continue to work:
- `/crm/api/seo/generate.php` → works (redirects to new location)
- `/crm/api/seo/apply.php` → works (redirects to new location)
- etc.

## Testing

All path resolutions have been verified:
- Path constants (APP_ROOT, PUBLIC_ROOT, CRM_ROOT, CRM_INCLUDES) are defined correctly
- Auth file (`/public/loginAuth/auth.php`) is found
- SEO functions (`/public/crm/includes/seo-functions.php`) is found
- Cron script (`/public/crm/cron/seo_recommendations.php`) is found

## Next Steps

1. Deploy all files to production
2. Test API endpoints on production
3. Consider removing shims in a future cleanup (after verifying no external code uses old paths)
