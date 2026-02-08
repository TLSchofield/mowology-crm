# Jobs Module — AI Rules

## Files
| File | Purpose |
|------|---------|
| `index.php` | Job list with stat cards, status tabs, search |
| `create.php` | Job form (manual or from quote), scheduling, recurring |
| `view.php` | Job detail with notes, photos, modals (reschedule/complete/note/photo) |
| `schedule.php` | Calendar week view with color-coded service types |

## AppStack Pattern
All pages use `dirname(__DIR__) . '/includes/appstack_head.php'` and `appstack_footer.php`.
`$activePage = 'jobs'` (schedule uses `$activePage = 'schedule'`).

## Database Tables
- `jobs` — job_number (JOB-YYYY-NNNN), status, property_id, company_id, quote_id, assigned_to, scheduled_date/time
- `job_notes` — job_id, user_id, note_type (general/customer_request/issue/internal), content
- `job_photos` — job_id, filename, photo_type (before/during/after/issue), caption

## Key Functions
- `generateJobNumber()` — JOB-YYYY-NNNN format
- `getStaffMembers()` — users for assignment dropdown
- `updateJobStatus($jobId, $newStatus, $userId, $notes)` — status transition + logging
- `createJobFromQuote($quoteId, $userId)` — creates job from accepted quote

## Job Statuses
`scheduled` → `in_progress` → `completed` | `cancelled`

## CSS Classes
List: `.mw-stat-card.today/.scheduled/.in-progress/.completed`, `.mw-action-btn-start`, `.mw-action-btn-complete`
View: `.mw-note-item`, `.mw-photos-grid`, `.mw-modal-overlay`, `.mw-modal`
Schedule: `.mw-calendar-container`, `.mw-calendar-grid`, `.mw-job-card-sched`, `.mw-legend`
