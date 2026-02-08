# Debug Panel - Quick Reference Card

## 🚀 Quick Start

### Enable Debug Panel
```
https://mowology.ca/crm/[page].php?debug=1
```

### What You'll See
- **Bottom-right corner:** Green terminal-style panel
- **Click header to expand/collapse**
- **4 sections:** Page Info → Performance → User/Session → Errors

---

## 📊 Key Metrics

| Metric | Good | Warning | Critical |
|--------|------|---------|----------|
| **Execution Time** | < 500ms | 500ms-1s | > 1s |
| **Memory Usage** | < 30MB | 30-50MB | > 50MB |
| **Peak Memory** | < 50MB | 50-100MB | > 100MB |
| **Errors** | 0 | 1-3 | 4+ |

---

## 🔍 Quick Diagnostics

### Problem: Slow Page
```
Check: Execution Time in Performance section
If > 1000ms → Database query issue likely
```

### Problem: High Memory
```
Check: Memory Usage row
If > 50MB → Memory leak or large dataset loading
```

### Problem: JavaScript Errors
```
Check: Errors section (red)
Errors appear in real-time as page loads
```

---

## 🎯 Common Tasks

| Task | Action |
|------|--------|
| Enable debug | Add `?debug=1` to URL |
| Keep debug on | Set cookie: `debug_panel=enabled` |
| Download log | Click `[Download]` button |
| Hide panel | Click `[Hide]` button |
| See errors | Expand errors section (red text) |

---

## 📋 Testing Checklist

Before deploying, test each page with `?debug=1`:

- [ ] Execution < 1000ms
- [ ] Memory < 50MB
- [ ] No errors logged
- [ ] Session ID present
- [ ] Correct user shown

---

## ⚙️ Configuration

### Enable for Current Session
```javascript
// Paste in browser console (F12)
document.cookie = "debug_panel=enabled; path=/";
```

### Disable Debug Panel
```
Add ?debug=0 to remove temporarily
Or clear cookie: document.cookie = "debug_panel=; max-age=0";
```

### Production Settings (Admin Only)
In `/public/app_config/config.php`:
```php
define('DEBUG_MODE', false); // Disables by default
```

---

## 🐛 Troubleshooting

| Issue | Solution |
|-------|----------|
| Panel not showing | Use `?debug=1` or set cookie |
| Panel overlapping | Click header and move panel (coming soon) |
| N/A for Queries | Database query tracking not implemented |
| Cookies not working | Check if in private/incognito mode |

---

## 📱 Keyboard Tips

```
F12              → Open browser console (see JS errors)
Ctrl+Shift+R     → Hard refresh (Windows)
Cmd+Shift+R      → Hard refresh (Mac)
```

---

## 🔐 Security Notes

⚠️ **NEVER** enable debug panel in production without:
- Restricting to admin IP addresses
- Strong authentication
- Audit logging

Default: Disabled (safe for production)

---

## 📞 Support

**Debug panel not working?**
1. Clear cache and cookies
2. Check browser console for errors
3. Verify `/crm/includes/debug-panel.php` exists

---

## 💡 Pro Tips

✓ **Compare performance** before/after code changes
✓ **Export logs** for documentation
✓ **Monitor errors** as features are tested
✓ **Check memory** after filtering large datasets

---

**Last Updated:** February 8, 2026 | **Version:** 1.0
