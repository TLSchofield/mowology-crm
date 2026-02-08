# Quote Workflow — Map Loading Fix

## Problem

The measure tool and location map were not loading on `/crm/quote-workflow.php?request_id=9`

### Root Cause

**Race condition in Google Maps API initialization:**

The Google Maps script was being loaded with `async defer` and a `callback=initMaps` parameter, but the `initMaps()` function wasn't defined until much later in the inline `<script>` tag. This caused:

1. Google Maps library finishes loading
2. Tries to call `initMaps()` callback
3. Function doesn't exist yet → initialization fails silently
4. Maps containers remain empty

## Solution

Two-part fix:

### 1. Pre-declare `initMaps` in the `<head>` (via `$extraHead`)

Before the Google Maps script loads, declare a stub function that acts as a placeholder:

```php
$extraHead = '<script>
    // Pre-declare initMaps stub so Google Maps callback doesn't fail
    function initMaps() {
        // This will be defined in the main script section below
    }
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=..." async defer></script>';
```

This ensures the callback always succeeds.

### 2. Explicit initialization on page load

At the end of the inline script (just before `</script>`), add fallback logic:

```javascript
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        // Call initMaps explicitly if Google Maps loaded but callback didn't fire
        if (typeof google !== 'undefined' && google.maps && !territoryMapInstance) {
            initMaps();
        }
        setTimeout(resizeMaps, 500);
    });
} else {
    // Document already loaded
    if (typeof google !== 'undefined' && google.maps && !territoryMapInstance) {
        initMaps();
    }
    setTimeout(resizeMaps, 500);
}
```

This ensures `initMaps()` is called even if:
- The callback fails to execute
- The page is already loaded when the script is parsed
- Multiple initialization attempts don't cause conflicts (checked with `!territoryMapInstance`)

## File Modified

`/public/crm/quote-workflow.php`

- Line 265-271: Added `$extraHead` with pre-declared `initMaps` stub + Google Maps script
- Line 1248-1265: Updated initialization logic with fallback mechanism

## Testing

1. Navigate to a quote request: `https://www.mowology.ca/crm/quote-workflow.php?request_id=9`
2. Check right panel:
   - **Property Location** map should show (roadmap view)
   - **Measure Tool** map should show (satellite view with drawing tools)
3. Test measure tool:
   - Select "Polygon" or "Rectangle" button
   - Draw on the satellite map
   - Area and perimeter should calculate and display
   - Save the area to confirm database save works

## Browser Debugging

If maps still don't appear, open browser DevTools and run:

```javascript
// Check if Google Maps loaded
console.log('google.maps:', typeof google.maps);
console.log('territoryMapInstance:', territoryMapInstance);
console.log('measureMapInstance:', measureMapInstance);

// Manually trigger initialization
if (typeof initMaps === 'function') {
    initMaps();
}
```

## Related CSS

The measure tool styling is defined in `/public/crm/css/mowology-brand.css`:

- `.mw-map-container` — height 250px, territory map
- `.mw-measure-map-container` — height 350px, measure tool map
- `.mw-measure-tools` — flex layout for tool buttons
- `.mw-measurement-display` — styling for calculated area/perimeter

All styles use Mowology brand tokens (`--mw-green`, `--mw-dark`, etc.).

## API Key

Google Maps API key is embedded in the script URL:
- Key: `AIzaSyCN-LxvQe4twbQ4O56zkd_3zxCU5blUNFs`
- Libraries: `drawing`, `geometry`
- Restrictions: IP-restricted to cPanel server

If key is invalid or expired, no maps will render. Check cPanel → Google Cloud API console.

---

**Last Updated:** 2026-02-06  
**Fixed By:** Claude Code
