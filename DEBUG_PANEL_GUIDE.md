# CRM Debug Panel - User Guide

## Overview

The **Debug Panel** is a development tool included in all CRM pages. It provides real-time insights into page performance, errors, and system state without modifying the page layout.

**Location:** Bottom-right corner of the screen
**Style:** Matrix-style terminal (green text on black background)
**Status:** Disabled by default (privacy/security)

---

## Enabling the Debug Panel

### Method 1: URL Parameter (Temporary)
Add `?debug=1` to any CRM page URL:
```
https://mowology.ca/crm/dashboard_appstack.php?debug=1
```

### Method 2: Browser Cookie (Persistent)
Set cookie via browser console:
```javascript
document.cookie = "debug_panel=enabled; path=/";
```

### Method 3: Environment Variable (Permanent)
Set in `/public/app_config/config.php`:
```php
define('DEBUG_MODE', true);
```

---

## Features

### 1. Page Information
- **Page:** Current PHP file being viewed
- **Method:** HTTP method (GET, POST, etc.)
- **Tab:** Active navigation tab
- **Time:** Server timestamp

### 2. Performance Metrics
- **Execution Time:** Time spent processing the page (milliseconds)
- **Memory Usage:** Current memory consumption
- **Peak Memory:** Highest memory used during execution
- **Database Queries:** Count of SQL queries executed (if tracking enabled)

**Optimal Values:**
- Execution time: < 500ms
- Memory: < 50MB
- Peak memory: < 100MB

### 3. User & Session
- **User:** Currently logged-in user
- **Session ID:** First 12 characters of session ID

### 4. Error Tracking
- Shows count of errors logged during page load
- Displays error messages with timestamps
- Captures JavaScript console errors and promise rejections

### 5. Quick Links
- **[Refresh]** - Reload page with debug panel visible
- **[Hide]** - Disable debug panel temporarily
- **[Download]** - Export debug log as text file
- **[Clear]** - Clear all logged errors

---

## Interpreting Debug Data

### Green Indicators (Good)
- Execution time < 1000ms
- Memory usage < 50MB
- Zero errors

### Yellow Indicators (Warning)
- Execution time 1000-2000ms
- Memory usage 50-80MB
- 1-3 errors logged

### Red Indicators (Critical)
- Execution time > 2000ms
- Memory usage > 100MB
- 4+ errors or PHP warnings

---

## Common Debug Scenarios

### Slow Page Load
**Check:** Execution Time
- If > 2000ms: Database query issue or heavy processing
- Navigate to the page with `?debug=1` and check query count
- Look for repeated queries that could be optimized

**Solution:**
- Check database indexes
- Implement query caching
- Look for N+1 query problems

### High Memory Usage
**Check:** Memory Usage / Peak Memory
- If > 100MB: Possible memory leak or large dataset load
- Check what data is being loaded on the page

**Solution:**
- Use pagination for large result sets
- Unset large variables after use
- Implement lazy loading

### JavaScript Errors
**Check:** Error Section
- Red errors appear in the debug panel
- Browser console (F12) shows full stack trace

**Solution:**
- Check browser console for details
- Download debug log for reference
- Report errors with full stack trace

---

## Using the Debug Panel for QA Testing

### Checklist Before Deployment
1. **Load each CRM page with `?debug=1`**
   - [ ] Dashboard
   - [ ] Clients
   - [ ] Quotes
   - [ ] Jobs
   - [ ] Invoices
   - [ ] Schedule
   - [ ] Portfolio (all tabs)
   - [ ] Products
   - [ ] Territory Map
   - [ ] Settings

2. **For each page, verify:**
   - [ ] Execution time < 1000ms
   - [ ] Memory usage < 50MB
   - [ ] Zero errors logged
   - [ ] Session ID present
   - [ ] Correct user shown

3. **Perform user actions and recheck:**
   - [ ] Filter results
   - [ ] Search queries
   - [ ] Create/edit operations
   - [ ] Form submissions

### Performance Regression Testing
```bash
# Before optimization
curl "https://mowology.ca/crm/dashboard_appstack.php?debug=1" \
  -H "Cookie: debug_panel=enabled" \
  | grep "execution_time"

# After optimization
curl "https://mowology.ca/crm/dashboard_appstack.php?debug=1" \
  -H "Cookie: debug_panel=enabled" \
  | grep "execution_time"
```

---

## Troubleshooting

### Debug Panel Not Appearing
1. **Check if debug is enabled:**
   - URL should have `?debug=1`
   - Or cookie `debug_panel=enabled` set

2. **Check browser console (F12):**
   - Look for JavaScript errors
   - Verify panel HTML is in page source

3. **Check file permissions:**
   - Ensure `/crm/includes/debug-panel.php` exists and is readable

### Debug Panel Shows "N/A" for Queries
- Database query tracking not implemented
- This is optional; panel still shows performance metrics

### Panel Overlapping Content
- Panel is fixed bottom-right with `z-index: 99999`
- If overlapping, move panel by dragging header
- Or hide with `?debug=0` parameter

---

## Disabling Debug Panel

### Remove from Specific Page
Remove this line from any CRM page:
```php
<?php include __DIR__ . '/includes/debug-panel.php'; ?>
```

### Disable Globally
Comment out in `appstack_footer.php`:
```php
// <?php include __DIR__ . '/includes/debug-panel.php'; ?>
```

### Disable via Code
In `debug-panel.php`, change:
```php
$debug_enabled = false; // Disables all debug panels
```

---

## Advanced Usage

### Logging Custom Data
In any CRM page, add to error log:
```php
$GLOBALS['_error_log'][] = "Custom debug message";
```

This will appear in the debug panel's error section.

### Tracking Database Queries
If implementing query logging:
```php
$GLOBALS['_query_count']++;
```

This will display in the "Queries" row.

### Accessing Debug Data via JavaScript
```javascript
// Debug data is available after page load
const debugInfo = {
  page: document.querySelector('.debug-value').textContent,
  // ... parse other debug rows
};
```

---

## Performance Optimization Tips

### Based on Debug Metrics

**If Execution Time Is High:**
1. Check for blocking database queries
2. Look for synchronous AJAX calls
3. Optimize image loading
4. Implement pagination

**If Memory Is High:**
1. Use `unset()` for large variables
2. Stream results instead of loading all
3. Use generators for large datasets
4. Implement garbage collection

**If Errors Are Frequent:**
1. Fix PHP warnings immediately
2. Add error handling around external API calls
3. Validate user input
4. Log all exceptions

---

## Security Considerations

⚠️ **IMPORTANT:** Never enable debug panel in production without:
- Restricting to admin IPs only
- Using strong authentication
- Limiting visible information
- Regular review of logs

### Recommended Production Settings
```php
// Only enable for specific IPs
$admin_ips = ['203.0.113.42', '203.0.113.43'];
$debug_enabled = in_array($_SERVER['REMOTE_ADDR'], $admin_ips) &&
                 isset($_GET['debug']) &&
                 $_GET['debug'] === '1';
```

---

## Keyboard Shortcuts

The debug panel supports these quick actions:

| Action | Shortcut |
|--------|----------|
| Toggle panel | Click header |
| Download log | Click [Download] link |
| Clear errors | Click [Clear] link |
| Hide panel | Click [Hide] link |
| Refresh with debug | Click [Refresh] link |

---

## Support & Debugging

### If Debug Panel Stops Working
1. Clear browser cache
2. Clear browser cookies: `document.cookie = "debug_panel=; max-age=0"`
3. Hard refresh page: `Ctrl+Shift+R` (Windows) or `Cmd+Shift+R` (Mac)
4. Check browser console for errors

### To Report Issues
Include in bug report:
- Debug panel screenshot
- Execution time
- Memory usage
- Any error messages
- Browser/OS version
- User role

---

## Example Scenarios

### Scenario 1: Portfolio Page Slow on First Load
```
Execution: 2500ms (slow!)
Memory: 42MB (ok)
Queries: 15 (possibly high)
Errors: 0

→ Likely cause: Database query optimization needed
→ Solution: Profile queries, add indexes
```

### Scenario 2: Memory Grows After Multiple Searches
```
Page 1: Memory: 18MB
Page 2: Memory: 35MB
Page 3: Memory: 52MB (peak!)

→ Likely cause: Memory leak in search filtering
→ Solution: Check variable cleanup, unset large arrays
```

### Scenario 3: Intermittent JavaScript Errors
```
Errors: 2 (red section shows)
Error 1: "feather is not defined"
Error 2: "Cannot read 'toSvg' of undefined"

→ Likely cause: Feather Icons library issue (known)
→ Solution: Downgrade or update Feather Icons
```

---

## Best Practices

✅ **DO:**
- Enable debug during development
- Check metrics after code changes
- Monitor for memory leaks
- Test all tabs and filters
- Document baseline performance

❌ **DON'T:**
- Leave debug enabled on production
- Share debug URLs publicly
- Ignore recurring errors
- Commit debug-enabled code
- Disable error logging entirely

---

## Files

- **Implementation:** `/public/crm/includes/debug-panel.php`
- **Integration:** `/public/crm/includes/appstack_footer.php`
- **Configuration:** `DEBUG_MODE` constant in app config

---

## Version History

**v1.0** (Current)
- Initial release
- Page info tracking
- Performance metrics
- Error logging
- Session info display

**Future Enhancements:**
- Database query details
- HTTP headers inspector
- Cookie viewer
- Local storage inspector
- Network request logger

---

Last Updated: February 8, 2026
