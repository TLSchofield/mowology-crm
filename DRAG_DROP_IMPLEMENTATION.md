# Schedule Drag-and-Drop Implementation

## ✅ WORKING - DO NOT BREAK

The drag-and-drop functionality for the hourly schedule grid is **fully functional** as of Feb 8, 2026.

### What Works
- ✅ Drag job cards from one time slot to another
- ✅ Drop jobs on different days and times
- ✅ Visual feedback (green dashed border on hover)
- ✅ Success toast notification showing new schedule
- ✅ Database updates via `/crm/api/reschedule-job-simple.php`
- ✅ Auto-refresh after 2 seconds to show job in new location

---

## Critical Files - DO NOT MODIFY WITHOUT TESTING

### 1. **Frontend: `/public/crm/js/schedule-drag-drop.js`**

**Key aspects:**
- Attaches drag listeners to `.mw-job-card-sched` elements
- Attaches drop listeners to `.mw-time-slot` elements (the hourly grid cells)
- Extracts date from `data-date` attribute on time slot
- Extracts hour from `data-hour` attribute and converts to `HH:00:00` time format
- Sends POST to `/crm/api/reschedule-job-simple.php` with:
  ```json
  {
    "job_id": 123,
    "scheduled_date": "2026-02-12",
    "scheduled_time_start": "09:00:00"
  }
  ```
- Auto-reloads page 2 seconds after success

**DO NOT CHANGE:**
- The API endpoint URL (line 198) - must stay `/crm/api/reschedule-job-simple.php`
- The `data-date` and `data-hour` attribute names (they match the HTML)
- The `location.reload()` after success (line 220)

### 2. **Backend: `/public/crm/api/reschedule-job-simple.php`**

**Purpose:** Simplified, working reschedule API

**Key aspects:**
- Simple, clean error handling
- Updates `jobs` table: `scheduled_date` and `scheduled_time_start` columns
- Requires user to be logged in via `requireLogin()`
- Returns JSON with success/error

**DO NOT CHANGE:**
- The file name or path
- The database column names (`scheduled_date`, `scheduled_time_start`)
- The endpoint URL path

### 3. **HTML: `/public/crm/jobs/schedule.php`**

**Critical data attributes on time slots (line 153):**
```php
<div class="mw-time-slot" data-date="<?php echo $dateStr; ?>" data-hour="<?php echo $hour; ?>">
```

**Critical data attributes on job cards (lines 160-163):**
```php
data-job-id="<?php echo (int)$job['id']; ?>"
data-job-number="<?php echo htmlspecialchars($job['job_number']); ?>"
data-scheduled-date="<?php echo $job['scheduled_date']; ?>"
data-scheduled-time="<?php echo htmlspecialchars($job['scheduled_time_start'] ?? ''); ?>"
draggable="true"
```

**DO NOT CHANGE:**
- These data attribute names or format
- The HTML structure of `.mw-time-slot` elements
- The `draggable="true"` on job cards

### 4. **CSS: `/public/crm/css/mowology-brand.css`**

**Key styles (lines 3473-3475):**
```css
.mw-time-slot.drag-over {
  background: rgba(45, 134, 89, 0.12);
  border: 2px dashed var(--mw-green);
  border-radius: 4px;
}
```

**DO NOT CHANGE:**
- The `.mw-time-slot.drag-over` class styling
- The cursor and user-select styles on `.mw-job-card-sched`

---

## How It Works (Flow Diagram)

```
1. User drags .mw-job-card-sched
   ↓
2. dragstart event fires
   - Stores originalDate and originalTime
   - Adds .dragging class
   ↓
3. User hovers over .mw-time-slot
   ↓
4. dragover event fires
   - Adds .drag-over class to slot (green border)
   ↓
5. User releases over .mw-time-slot
   ↓
6. drop event fires
   - Extracts newDate from data-date
   - Extracts hour from data-hour, converts to HH:00:00
   - Sends POST to reschedule-job-simple.php
   ↓
7. API Response
   ↓
   SUCCESS: Green toast + page reloads after 2 seconds
   ERROR: Red toast showing error message (persists 30 seconds)
```

---

## Important: Do NOT Use The Old API

**❌ DEPRECATED:** `/public/crm/api/reschedule-job.php`
- Has complex error handling that causes issues
- DO NOT modify this file
- DO NOT switch back to it in the JavaScript

**✅ USE THIS:** `/public/crm/api/reschedule-job-simple.php`
- Simple, clean, working implementation
- This is what the JavaScript calls

---

## Testing Checklist

After ANY changes to these files, test:

- [ ] Hard refresh browser (`Cmd+Shift+R` on Mac, `Ctrl+Shift+R` on Windows)
- [ ] Drag a job card
- [ ] Green dashed border appears on hover ✓
- [ ] Drop on different time slot
- [ ] Green success toast appears ✓
- [ ] Toast shows: "JOB-XXXX rescheduled to DAY TIME" ✓
- [ ] Page refreshes after 2 seconds ✓
- [ ] Job appears in new time slot ✓
- [ ] Page doesn't break if error occurs (red error toast for 30 seconds) ✓

---

## Common Issues & Solutions

### Issue: Jobs aren't draggable
**Check:**
- Browser console for JavaScript errors
- `draggable="true"` on job card elements
- `.mw-job-card-sched` class exists on elements

### Issue: Drop zones don't highlight
**Check:**
- `.mw-time-slot` elements exist
- `data-date` and `data-hour` attributes are set
- CSS `.mw-time-slot.drag-over` rule exists

### Issue: Job reschedules but doesn't move
**This is correct behavior** - the page auto-reloads after 2 seconds to show the job in the new location.

### Issue: API returns 500 error
**Check:**
- Using `reschedule-job-simple.php` NOT `reschedule-job.php`
- User is logged in
- Job ID exists in database
- Date format is YYYY-MM-DD
- Time format is HH:MM:SS

---

## Database Schema

The reschedule operation updates the `jobs` table:

```sql
UPDATE jobs
SET
  scheduled_date = ?,           -- YYYY-MM-DD format
  scheduled_time_start = ?,     -- HH:MM:SS format
  updated_at = NOW()
WHERE id = ?
```

**Required columns:**
- `jobs.id` - job identifier
- `jobs.scheduled_date` - DATE column
- `jobs.scheduled_time_start` - TIME column
- `jobs.updated_at` - TIMESTAMP column

If any of these columns are renamed or removed, the drag-drop will break.

---

## Future Improvements (Do NOT implement without testing)

Potential enhancements that could break this if not done carefully:

1. **Drag to same time slot** - Currently blocked. Could allow moving to different hour on same day.
2. **Drag across weeks** - Currently only works within visible week. Pagination would complicate this.
3. **Animated job card movement** - Currently relies on page refresh. Could be animated with DOM manipulation instead.
4. **Undo functionality** - Could store previous schedule before updating.
5. **Drag multiple jobs** - Currently one-at-a-time only.
6. **Sound feedback** - Could add "whoosh" sound on drop.

**If implementing any of these, follow the testing checklist above afterward.**

---

## Last Updated

- **Date:** February 8, 2026
- **Status:** ✅ WORKING & TESTED
- **Tested By:** User confirmed functional
- **Test Date:** Feb 8, 2026

---

## Sign-Off

This implementation is production-ready and stable. The drag-and-drop feature works reliably for rescheduling jobs in the hourly schedule view.

**DO NOT MODIFY without:**
1. Understanding this document completely
2. Testing all items in the Testing Checklist
3. Verifying database hasn't changed
4. Getting explicit confirmation that changes work
