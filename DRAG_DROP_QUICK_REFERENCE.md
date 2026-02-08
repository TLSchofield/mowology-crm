# Drag-Drop Quick Reference

## 🎯 The 4 Critical Files

| File | What It Does | DO NOT CHANGE |
|------|-------------|---------------|
| `/public/crm/js/schedule-drag-drop.js` | Handles drag events | Line 198: API endpoint URL |
| `/public/crm/api/reschedule-job-simple.php` | Updates database | File name, column names |
| `/public/crm/jobs/schedule.php` | HTML structure | data-date, data-hour attributes |
| `/public/crm/css/mowology-brand.css` | Visual styling | .mw-time-slot.drag-over rule |

## 🚀 How to Test After Changes

```bash
# 1. Hard refresh in browser
Cmd+Shift+R (Mac) or Ctrl+Shift+R (Windows)

# 2. Drag a job
# 3. Confirm green border appears
# 4. Drop it
# 5. Confirm green success toast
# 6. Wait 2 seconds
# 7. Confirm page reloads with job in new location
```

## ❌ DO NOT DO THIS

- Don't modify `/public/crm/api/reschedule-job.php` (old, broken version)
- Don't change the API endpoint URL in the JavaScript
- Don't rename the HTML data attributes
- Don't remove the `location.reload()` after success

## ✅ DO THIS IF ADDING FEATURES

1. Read `DRAG_DROP_IMPLEMENTATION.md` completely
2. Make your changes to ONE file only
3. Run through the Testing Checklist
4. Verify jobs reschedule and move to correct slot
5. If it breaks, revert immediately
6. Ask for help if unsure

## 🔧 If It Breaks

**First thing:** Check browser console for errors
```
Open DevTools: F12
Go to Console tab
Look for red error messages
Read the error carefully
```

**Second thing:** Check which file you modified
- If it's the JavaScript, hard refresh the browser
- If it's the API, check server error logs
- If it's the HTML, check page structure is intact
- If it's the CSS, check .mw-time-slot styling

**Third thing:** Check the Testing Checklist against DRAG_DROP_IMPLEMENTATION.md

## 📞 Emergency Contacts

If drag-drop breaks:
1. **Don't panic** - it's a contained feature
2. **Revert your changes** - use git to undo
3. **Test it works again** - run Testing Checklist
4. **Document what broke** - helps prevent future issues
5. **Ask for help** - reference this document

## 🎓 Learning Resources

To understand the drag-drop better, read these files in order:
1. This file (quick overview)
2. DRAG_DROP_IMPLEMENTATION.md (detailed reference)
3. The source code files (implementation details)

## 📝 Change Log

| Date | What Changed | Status |
|------|-------------|--------|
| 2026-02-08 | Initial working implementation | ✅ Working |

---

**Remember: Test after every change!**
