# Schedule Drag-and-Drop Fixes

## Problem
The hourly grid calendar view wasn't responding to drag-and-drop operations. Jobs could not be dragged to different time slots or days.

## Root Cause
The JavaScript drag-drop event listeners were not attached to the `.mw-time-slot` elements used in the hourly grid layout. The script was looking for old container elements (`.mw-day-jobs-container`, `.mw-calendar-day`) that don't exist in this view.

## Changes Made

### 1. `/public/crm/js/schedule-drag-drop.js`
- **Added time slot detection**: Now attaches drag event listeners to all `.mw-time-slot` elements
- **Improved event handling**:
  - Job cards now have their own drag event listeners (dragstart, dragend, dragover, dragleave, drop)
  - This ensures events bubble properly from child elements to parent containers
  - Uses `closest()` method to find parent drop zones when needed
- **Enhanced drop logic**:
  - Detects which type of container was dropped on (time slot, day container, or calendar day)
  - Extracts date from `data-date` attribute
  - Extracts hour from `data-hour` attribute on time slots
  - Generates proper time in `HH:00:00` format based on the hour
  - Sends both date and time to the reschedule API
- **Better drag-leave handling**: Uses coordinate checking to determine when drag truly leaves a container

### 2. `/public/crm/css/mowology-brand.css`
- **Added time slot drag-over styling**:
  ```css
  .mw-time-slot.drag-over {
    background: rgba(45, 134, 89, 0.12);
    border: 2px dashed var(--mw-green);
    border-radius: 4px;
  }
  ```
  This shows visual feedback when hovering over a drop zone

## How to Test

1. Go to the schedule page: `https://mowology.ca/crm/jobs/schedule.php`
2. Find a job card in the hourly grid
3. Drag the job card to a different time slot (even on the same day)
4. The slot should highlight with a green dashed border
5. Release to drop - the job should reschedule to that time
6. You should see a success toast notification with the new date and time
7. The page should NOT need to refresh - the change is live
8. The job card should move to the new time slot

## Technical Details

### HTML Data Attributes
- `data-job-id` - Job ID for reschedule API
- `data-job-number` - Job number for display in toast
- `data-scheduled-date` - Original date (YYYY-MM-DD format)
- `data-scheduled-time` - Original time (HH:MM:SS format)
- `data-date` - Date of the time slot (on `.mw-time-slot`)
- `data-hour` - Hour of the time slot as integer 6-18 (on `.mw-time-slot`)

### API Endpoint
- **POST** `/crm/api/reschedule-job.php`
- **Payload**:
  ```json
  {
    "job_id": 123,
    "scheduled_date": "2026-02-10",
    "scheduled_time_start": "14:00:00"
  }
  ```
- Returns success/error status and updated job data

### Event Flow
1. **dragstart** - Job card marked as `dragging`, original date/time stored
2. **dragover** - Drop zones marked with `.drag-over` class
3. **dragleave** - `.drag-over` class removed when cursor leaves zone
4. **drop** - New slot detected, reschedule API called
5. **Success** - Toast notification shown, job data updated

## Browser Compatibility
- Works in all modern browsers (Chrome, Firefox, Safari, Edge)
- Uses native HTML5 Drag and Drop API
- No polyfills required

## Debugging
If drag-drop still isn't working:
1. Open browser DevTools (F12)
2. Check the Console tab for any JavaScript errors
3. Check the Network tab to see if the API call is being made to `/crm/api/reschedule-job.php`
4. Verify that you're logged in to the CRM
5. Check that you have permission to reschedule the job (admin or assigned user)
