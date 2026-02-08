# Deployment Checklist — Schedule Drag-and-Drop Feature

**Feature:** Drag-and-drop calendar rescheduling
**Status:** ✅ Implemented and Committed
**Date:** February 9, 2026
**Commit:** `a9619b8`

---

## 📋 Files to Deploy

### New Files (Must Deploy)
```
✅ /public/crm/api/reschedule-job.php
   Size: ~4.9 KB
   Purpose: Backend API for job rescheduling

✅ /public/crm/js/schedule-drag-drop.js
   Size: ~6.2 KB
   Purpose: Frontend drag-and-drop JavaScript logic
```

### Modified Files (Must Deploy)
```
✅ /public/crm/jobs/schedule.php
   Changes: Added draggable attributes, data attributes, feedback toast div
   Lines changed: ~15 lines added

✅ /public/crm/css/mowology-brand.css
   Changes: Added drag visual states, toast styling, animations
   Lines added: ~75 lines
```

### Documentation (Optional but Recommended)
```
📄 SCHEDULE_DRAG_DROP.md - Complete technical documentation
📄 SCHEDULE_DRAG_DROP_QUICK_START.md - Quick reference guide
📄 DEPLOYMENT_CHECKLIST_SCHEDULE.md - This file
```

---

## 🚀 Pre-Deployment Steps

### Step 1: Verify Files Locally

```bash
# Navigate to project directory
cd /Users/timschofield/Projects/mowology-crm

# Verify files exist
ls -la public/crm/api/reschedule-job.php
ls -la public/crm/js/schedule-drag-drop.js
ls -la public/crm/jobs/schedule.php

# Check file sizes
du -h public/crm/api/reschedule-job.php
du -h public/crm/js/schedule-drag-drop.js
```

### Step 2: Test Locally

```bash
# Start MAMP
# Navigate to: http://localhost:8888/crm/jobs/schedule.php

# Test drag-and-drop:
# 1. Try dragging any job card to another day
# 2. Should see success toast
# 3. Check database to verify date changed
# 4. Refresh page - change should persist

# Open DevTools (F12) and check:
# - No JavaScript errors in Console
# - Network tab shows POST to /crm/api/reschedule-job.php returns 200
# - Response shows success and updated job data
```

### Step 3: Database Verification

```bash
# Verify jobs table has required columns:
# - id (primary key)
# - job_number
# - scheduled_date
# - scheduled_time_start
# - scheduled_time_end
# - updated_at

# Connect to local database and run:
DESC jobs;

# Should show all these columns exist
```

---

## 📤 Deployment Steps

### Via FTP (Recommended for Shared Hosting)

1. **Connect to mowology.ca via FTP**
   - Host: mowology.ca
   - Username: (cPanel username)
   - Password: (cPanel password)
   - Port: 21 (standard FTP) or 22 (SFTP)

2. **Upload New Files**
   ```
   Local: /Users/timschofield/Projects/mowology-crm/public/crm/api/reschedule-job.php
   Remote: /home/mowology/public_html/crm/api/reschedule-job.php

   Local: /Users/timschofield/Projects/mowology-crm/public/crm/js/schedule-drag-drop.js
   Remote: /home/mowology/public_html/crm/js/schedule-drag-drop.js
   ```

3. **Upload Modified Files**
   ```
   Local: /Users/timschofield/Projects/mowology-crm/public/crm/jobs/schedule.php
   Remote: /home/mowology/public_html/crm/jobs/schedule.php

   Local: /Users/timschofield/Projects/mowology-crm/public/crm/css/mowology-brand.css
   Remote: /home/mowology/public_html/crm/css/mowology-brand.css
   ```

4. **Verify File Permissions**
   - All files should be **644** (rw-r--r--)
   - Directories should be **755** (rwxr-xr-x)

5. **Clear Browser Cache**
   - Hard refresh: Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)

### Via Git (If using GitHub deployment)

The changes are already committed. Just push to GitHub and the hosting will auto-deploy:

```bash
git push origin main
# Auto-deployment will pull latest changes to live server
```

### Via SSH (If you have SSH access)

```bash
# Connect to server
ssh user@mowology.ca

# Navigate to web root
cd /home/mowology/public_html

# Pull latest changes (if using git)
cd /home/mowology/public_html
git pull origin main

# OR upload files manually via scp
scp local_file user@mowology.ca:/home/mowology/public_html/path/to/file
```

---

## ✅ Post-Deployment Testing

### Step 1: Verify Files Uploaded

```bash
# Via cPanel File Manager or FTP
# Check these files exist on server:
- /crm/api/reschedule-job.php ✅
- /crm/js/schedule-drag-drop.js ✅
- /crm/jobs/schedule.php ✅
- /crm/css/mowology-brand.css ✅
```

### Step 2: Test on Live Server

```
URL: https://mowology.ca/crm/jobs/schedule.php

1. Log in to CRM
2. Navigate to Schedule
3. Try dragging a job card to another day
4. Expected: Green toast appears "JOB-XXXX rescheduled to [date]"
5. Refresh page
6. Expected: Job is still on new date (change persisted)
7. Check database to confirm scheduled_date changed
```

### Step 3: Check Browser Console

```
On https://mowology.ca/crm/jobs/schedule.php:
1. Press F12 to open DevTools
2. Go to Console tab
3. Expected: No red error messages
4. Warnings are OK, errors are NOT OK
5. If errors appear, check exact error message
```

### Step 4: Monitor Network Requests

```
1. Open DevTools (F12)
2. Go to Network tab
3. Drag a job card
4. Look for request to: /crm/api/reschedule-job.php
5. Expected Status: 200 (success) or 4xx/5xx (error with message)
6. Response should show JSON with success:true
```

### Step 5: Database Verification

```sql
-- Connect to mowology production database
-- Check that scheduled_date was updated

SELECT id, job_number, scheduled_date, updated_at
FROM jobs
ORDER BY updated_at DESC
LIMIT 1;

-- Should show a recently updated job with new scheduled_date
```

---

## 🐛 Troubleshooting

### Issue: "Dragging doesn't work"

**Possible Causes:**
1. JavaScript file not loading
2. Browser cache not cleared
3. CSS not loading (visual feedback issue)

**Solution:**
```
1. Hard refresh: Ctrl+Shift+R
2. Check DevTools Console (F12) for errors
3. Verify /crm/js/schedule-drag-drop.js file uploaded correctly
4. Check file permissions (should be 644)
```

### Issue: "Toast notification doesn't appear"

**Possible Causes:**
1. CSS not loading
2. JavaScript variable `feedbackEl` not finding element
3. Browser JavaScript disabled

**Solution:**
```
1. Check DevTools Elements tab - find element with id="dragFeedback"
2. Verify mowology-brand.css uploaded and loaded
3. Check browser console for JavaScript errors
4. Try different browser to rule out browser-specific issue
```

### Issue: "Changes don't save to database"

**Possible Causes:**
1. API endpoint returning error (403 Forbidden, 400 Bad Request, etc.)
2. Database permissions issue
3. Job doesn't exist or user doesn't have permission

**Solution:**
```
1. Check DevTools Network tab for POST request response
2. Read error message in response JSON
3. Verify user is admin or job is assigned to user
4. Check database permissions (INSERT/UPDATE on jobs table)
5. Check server PHP error logs
```

### Issue: "Permission denied error"

**Expected Behavior:**
- Admin users can reschedule any job ✅
- Regular users can ONLY reschedule jobs assigned to them ✅
- If user tries to reschedule job not assigned to them → 403 Forbidden

**Solution:**
```
- Verify job is actually assigned to logged-in user
- Or log in as admin to reschedule any job
- Check jobs table: job.assigned_to should match user.id
```

---

## 📊 Verification Checklist

After deployment, verify ALL of these:

### Files
- [ ] `/crm/api/reschedule-job.php` exists on server
- [ ] `/crm/js/schedule-drag-drop.js` exists on server
- [ ] `/crm/jobs/schedule.php` exists (updated version)
- [ ] `/crm/css/mowology-brand.css` exists (updated version)

### Functionality
- [ ] Can drag job card from one day to another
- [ ] Toast notification appears after drop
- [ ] Database `scheduled_date` is updated
- [ ] Change persists after page refresh
- [ ] Permission validation works (non-admin can't drag others' jobs)

### Code Quality
- [ ] No JavaScript errors in browser console
- [ ] No PHP warnings/errors in server logs
- [ ] CSS loads correctly (visual feedback works)
- [ ] All data attributes present on job cards

### Edge Cases
- [ ] Dragging same job multiple times works
- [ ] Dragging to same day shows "not moved" message
- [ ] Error handling works (network offline, server error, etc.)
- [ ] Works on different browsers (Chrome, Firefox, Safari, Edge)
- [ ] Works on mobile devices (if needed)

---

## 🔄 Rollback Instructions

If something goes wrong, you can quickly rollback:

### Option 1: Upload Previous Versions via FTP
```
1. Download backup copies of original files from server
2. Re-upload original versions
3. Clear browser cache
4. Test again
```

### Option 2: Git Revert (If using GitHub deployment)
```bash
git revert a9619b8  # Revert drag-drop commit
git push origin main
# Auto-deployment will pull reverted changes
```

### Option 3: Undo via File Manager
```
1. Login to cPanel
2. File Manager
3. Navigate to /crm/jobs/
4. Right-click schedule.php → Delete
5. Upload original backup
```

**Rollback Time:** ~5-10 minutes

---

## 📞 Support Resources

If you encounter issues:

1. **Check Console Errors**
   - Press F12, go to Console tab
   - Look for red error messages
   - Take screenshot of error

2. **Check Network Requests**
   - F12 → Network tab
   - Drag a job and watch requests
   - Look for /crm/api/reschedule-job.php
   - Check status code and response

3. **Check Server Logs**
   - cPanel → Error Log
   - Look for PHP errors around deployment time
   - May indicate permission or configuration issues

4. **Review Documentation**
   - `SCHEDULE_DRAG_DROP.md` - Full technical docs
   - `SCHEDULE_DRAG_DROP_QUICK_START.md` - Quick reference
   - API endpoint source: `/public/crm/api/reschedule-job.php`
   - JavaScript source: `/public/crm/js/schedule-drag-drop.js`

---

## ✨ Success Criteria

✅ Deployment is successful when:
- Job cards are draggable on live schedule page
- Toast notifications appear with success messages
- Database jobs table shows updated scheduled_date
- Changes persist after page refresh
- No errors in browser console
- No errors in server logs
- Permission validation works correctly

---

## 📝 Sign-Off

**Deployed By:** [Your Name]
**Deployment Date:** [Date]
**Status:**
- [ ] Testing Complete
- [ ] All Checks Passed
- [ ] Ready for Production

---

## Next Steps

After successful deployment:

1. ✅ Monitor server logs for 24 hours
2. ✅ Ask users to test drag-drop functionality
3. ✅ Check database for activity_log entries (reschedule records)
4. ✅ Consider sending update email to users
5. ✅ Document any issues encountered for future reference

---

**Questions?** See `SCHEDULE_DRAG_DROP.md` for complete technical documentation.
