# API Reschedule Debug Improvements

## Problem
The drag-and-drop feature was returning a generic HTTP 500 error without details about what was actually failing.

## Solutions Implemented

### 1. Enhanced JavaScript Logging (`schedule-drag-drop.js`)

**What happens now when you drag a job:**

- All values being extracted are logged to the console with detailed information
- The complete payload sent to the API is logged
- All validation messages now persist for 30 seconds (not just 3 seconds)
- Error responses are logged with full details

**Browser Console Output Examples:**

```javascript
// When dropping on a time slot
Dropped on time slot: {
  'data-date': '2026-02-12',
  'data-hour': '9',
  'parsed hour': 9,
  newDate: '2026-02-12',
  newTime: '09:00:00'
}

// Before API call
Sending reschedule request: {
  payload: { job_id: 123, scheduled_date: '2026-02-12', scheduled_time_start: '09:00:00' },
  jobId: 123,
  jobNumber: 'JOB-2026-0001',
  newDate: '2026-02-12',
  newTime: '09:00:00'
}

// If API succeeds
Reschedule successful: { success: true, message: '...', job: {...} }

// If API fails
API error response: { status: 500, data: { error: 'Database error: ...' } }
```

### 2. Enhanced PHP API Logging (`reschedule-job.php`)

**Server-side improvements:**

- Logs the incoming request payload immediately upon receipt
- Logs user authentication status
- Logs the exact SQL query being executed
- Logs query parameters for debugging
- Logs query preparation and execution status
- Provides detailed error messages instead of generic "Database error occurred"
- Includes PDO error info in responses

**What gets logged on the server:**

```
Reschedule API called with payload: {"job_id":123,"scheduled_date":"2026-02-12","scheduled_time_start":"09:00:00"}
User authenticated: 1 (admin)
Executing update query: UPDATE jobs SET scheduled_date = ?, scheduled_time_start = ?, updated_at = NOW() WHERE id = ?
With parameters: ["2026-02-12","09:00:00",123]
Update succeeded for job ID: 123
```

**If something fails:**

```
Database update failed: [00000] HY000 - Column not found
```

The error message will now include the actual database error instead of being generic.

## How to Debug

### From Browser Console (F12)
1. Open DevTools
2. Go to Console tab
3. Drag and drop a job
4. Look for logs starting with "Dropped on time slot", "Sending reschedule request", etc.
5. Check for any validation or API error messages

### From Server Logs
Contact your hosting provider to check the PHP error log, or look for logs at:
- `/home/mowology/public_html/error_log` (cPanel)
- `/var/log/php-fpm.log` (FPM)
- Your hosting control panel's error log viewer

## Expected Error Messages

If something is wrong, you should now see specific errors like:

| Error | Meaning | Solution |
|-------|---------|----------|
| `Invalid date format: "2026-13-01"` | Date in wrong format | Check date is YYYY-MM-DD |
| `Invalid time format: "9:00:00"` | Time without zero-padding | Time must be HH:MM:SS (09:00:00) |
| `Database error: Unknown column 'scheduled_time_start'` | Column missing from jobs table | Check database schema |
| `You do not have permission to reschedule this job` | User not admin or not assigned | Check user role and job assignment |
| `Job not found` | Job ID doesn't exist | Job may have been deleted |

## Testing Checklist

- [ ] Open browser DevTools (F12)
- [ ] Go to Schedule page
- [ ] Drag a job to a different time slot
- [ ] Check Console tab for logs
- [ ] Look for the payload being sent
- [ ] Verify date format is correct (YYYY-MM-DD)
- [ ] Verify time format is correct (HH:MM:SS)
- [ ] Check for any error messages and note them

## Common Issues & Fixes

### Issue: "Database error: ... Unknown column ..."
**Cause:** The jobs table is missing a column
**Fix:** Run database migrations or check database schema

### Issue: "Server error: 500"
**Cause:** PDO connection or other exception
**Fix:** Check server error logs, verify database credentials, ensure PDO extension is loaded

### Issue: Validation message shows wrong format
**Cause:** The date or time being extracted has extra spaces or characters
**Fix:** Check the HTML `data-date` and `data-hour` attributes are correct
