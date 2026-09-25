# Timeclock & Payroll — Shift-Bounded Job Time

Last updated: 2026-05-15

## Overview

Payroll operates on a strict source-of-truth hierarchy:

```
1. SHIFT CLOCK     time_clock_entries.clock_in → clock_out
                   Hard outer bound per day per user. Pay cannot exceed this.

2. SCHEDULE        job_visits.scheduled_date + assigned_crew_id
                   Validates that timers fire on real assigned visits.

3. JOB TIMER       job_time_entries — valid only when bounded within shift window
                   Per-visit segments; sum = actual_duration_minutes for billing.

4. GPS (advisory)  gps_dwell_minutes, route-reconciliation
                   Anomaly detection only. Never overrides shift or timer for pay.
```

## Components

| Component | Path | Purpose |
|-----------|------|---------|
| Core functions | [`app/Modules/Team/Services/TimeclockFunctions.php`](../../app/Modules/Team/Services/TimeclockFunctions.php) | `stopJobTimer()`, `recalculateTimesheetTotals()`, `getJobTimeEntriesForRange()` |
| Orphaned timer cron | [`app/Modules/Team/Cron/stop_orphaned_job_timers.php`](../../app/Modules/Team/Cron/stop_orphaned_job_timers.php) | Hourly: stops any active job timer running > 12h, caps to shift |
| Job entry edit API | [`app/Modules/Team/Api/job-time-entry-edit.php`](../../app/Modules/Team/Api/job-time-entry-edit.php) | Admin: void or set_duration on individual job_time_entries |
| OT calculator | [`app/Modules/Team/Services/OvertimeCalculator.php`](../../app/Modules/Team/Services/OvertimeCalculator.php) | BC Employment Standards Act (daily 8h/12h + weekly 40h) |
| Timesheet detail | [`public/crm/timeclock/timesheet-detail.php`](../../public/crm/timeclock/timesheet-detail.php) | UI: per-day breakdown, anomaly warnings, admin void/correct |
| Migration | [`database/migrations/1031_job_time_entry_anomaly_flags.sql`](../../database/migrations/1031_job_time_entry_anomaly_flags.sql) | Adds `flagged_anomaly`, `auto_stopped` columns to `job_time_entries` |

## Key Behaviours

### stopJobTimer()

When a job timer stops (manually or auto-stopped on clock-out):

1. Compute raw duration via `TIMESTAMPDIFF(MINUTE, start_time, NOW())`
2. Look up the crew's shift for the day the timer *started*
3. If `raw_duration > shift_total`: cap to `shift_total`, set `flagged_anomaly = 1`
4. Update `job_time_entries.duration_minutes` and `job_visits.actual_duration_minutes`

### recalculateTimesheetTotals()

Called after every clock-out, job timer stop, or admin correction. Uses a JOIN-based per-day cap:

- Aggregate job entries per day (`dj`)
- Aggregate shift clock entries per day (`ds`)
- For each day: `capped = (shift IS NULL or 0) ? job : min(job, shift)`
- Sum capped daily totals → `timesheets.total_job_minutes`
- `total_travel_minutes = max(0, total_shift_minutes - total_job_minutes)`

The per-day cap prevents a single runaway timer from inflating the weekly job total above the weekly shift total.

### Orphaned Timer Cron

Runs hourly via cPanel:
```
0 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Team/Cron/stop_orphaned_job_timers.php
```

Finds any `job_time_entries` with `status='active'` and `start_time < NOW() - INTERVAL 12 HOUR`. For each:
- Sets `end_time = NOW()`
- Caps duration to shift (or 720min fallback if no shift found)
- Sets `auto_stopped = 1`, `flagged_anomaly = 1`
- Logs to `visit_audit_log`
- Calls `recalculateTimesheetTotals()`

### Anomaly Detection (UI)

`timesheet-detail.php` flags any day where `total_job_min > total_shift_min` as an anomaly. Shows an orange warning bar above the day's entries. Admin can void or correct individual entries inline without leaving the page.

## Schema

```sql
-- job_time_entries (relevant columns added 2026-05-15, migration 1031)
flagged_anomaly  TINYINT NOT NULL DEFAULT 0  -- 1 = capped or auto-stopped
auto_stopped     TINYINT NOT NULL DEFAULT 0  -- 1 = stopped by orphan cron, not user

-- timesheets
total_shift_minutes   INT  -- from time_clock_entries (source of truth)
total_job_minutes     INT  -- per-day capped from job_time_entries
total_travel_minutes  INT  -- shift - job (approximation of non-job time)
```

## Root Cause: The PLN-2026-0046-V023 Incident (May 12, 2026)

GPS auto-start fired when the crew entered the 150m geofence. The job timer ran
unattended from 2026-05-12 09:25 to 2026-05-13 16:29 (next day clock-out), giving
`duration_minutes = 1863` (31h 3m) for an 8h 43m shift day.

**Fixes applied:**
1. Entry #96 corrected to 482min (shift 523min − other same-day jobs 41min)
2. `stopJobTimer()` now caps at shift time for all future stops
3. Orphaned timer cron catches any still-running timers hourly
4. `recalculateTimesheetTotals()` uses per-day ceiling so stored totals can never exceed shift
5. Anomaly warning UI surfaces shift/job mismatches before approval
