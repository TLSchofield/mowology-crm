# Otto — Operations head: plan (Phase 1, awaiting Tim's approval)

Otto keeps today's work on track. He suggests; Tim clicks. Nothing Otto does changes a schedule, a timesheet or messages a crew without Tim's click.

## 1. What the code and data already hold

**Today's crews, stops and route**
- `getCalendarStops($from, $to)` (`app/Modules/Jobs/Services/Plan/CalendarStops.php:70`) returns stops with `crew_ids` (from `calendar_stops.crew_id` plus `calendar_stop_crew`), visits, status and route order.
- `computeDayBattleCard($date)` (`Plan/DayBattleCard.php:19`) gives revenue, margin, stop count, drive estimate, crew overlap, weather risk and a verdict. Otto reuses it and does not compute a second one.
- Tables: `calendar_stops` (status `scheduled…skipped`, `route_order`), `job_visits` (`status`, `assigned_crew_id`, `completed_at`, `actual_duration_minutes`), `time_clock_entries` (who is on the clock now).
- The dashboard Work Queue already counts "Stuck visits" (`app/Services/CrmFunctions.php` ~2494). Otto names them and does not count them a second time.

**Weather**
- `weather_schedule_guard.php` runs daily at noon (`app/Modules/Jobs/Cron/weather_schedule_guard.php:84-101`). It writes `job_visits.weather_status` (`OK`/`BORDERLINE`/`NOT_OK`), a snapshot and `weather_action_log`.
- The guard **auto-reschedules** a visit when `ops.auto_reschedule_enabled` is on and the package has `auto_reschedule=1` (line 235).
- Tim's decisions already land in `weather_action_log` (`MANUAL_KEEP`, `DISMISSED`, reschedule) through `app/Modules/Jobs/Api/weather-actions.php:97-153`. Otto learns from this history.
- The forecast comes from Open-Meteo through the file cache (`getWeekForecast`), so a card load makes no paid API call.
- The seasonal outlook is `SeasonalOutlookService::activeOutlook()` (line 325), refreshed by a cron at 5 am.
- Bug found: the guard's `recordCronRun` message reads `rescheduled`/`flagged` keys that are never set (line 316-326), so it always logs "0 rescheduled, 0 flagged".
- Risk found: `weather-actions.php` POSTs have no CSRF check and no permission check.

**GPS and timesheet gaps**
- Silent trackers: `TrackingHealthService` has `isSilent()` (:35), `likelyCause()` (:51) and `clockedInTrackedUsers()` (:87). It reads `crew_location_history` and `device_tracking_health` (migration 1116).
- Missing clock-outs: `auto_clockout.php:64-96` closes shifts older than `auto_clock_out_hours` at clock_in+N h. Its only marker is `notes LIKE '%auto-completed by cron%'` with `status='completed'`, so these are payroll guesses Tim should correct.
- Orphan job timers: `stop_orphaned_job_timers.php:83-122` sets `job_time_entries.auto_stopped=1, flagged_anomaly=1`.
- Visits with no time: a completed `job_visits` row with no `job_time_entries.visit_id` row (FK added in `200_job_plans_visits_stops.sql:288`).
- Existing editors to link to or reuse: `timeclock/timesheet-detail.php`, `api/time-entry-edit.php`, `api/job-time-entry-edit.php`, `timeclock/tracking-health.php`, `ops/weather_actions.php`. `api/route-reconciliation.php` (truck GPS compared with clock-ins) has its logic inline in the page file, so it is a v2 candidate for extraction.

**Penny's pattern** (this is the template)
- Desk service with `ready()` and `stats()`.
- Badges come from the owner's decisions on her suggestions, as last-N streaks that can be lost.
- A question service with answers that teach her.
- The brain counts from a baseline stored in `ops_settings`.
- API uses `?mode=`, has a shim, requires a permission and a CSRF token on POST, and calls `session_write_close`.
- **Deck gate:** `dept-heads-deck.php:12-19` returns early unless the user has `userHasPermission('expenses.approve')` AND Penny's desk is `ready()`. Otto inherits that gate unless the lead changes it.

## 2. Unknowns, and how each is settled (cheapest first)

1. Has migration 1116 been run? Settle on prod via Chrome: `SHOW TABLES LIKE 'device_tracking_health'`.
2. Are the crons actually running? Check `cron_runs` last `ran_at` for `weather_guard`, `auto_clockout`, `tracking_silent_alert` and `stop_orphaned_job_timers`, and the cPanel crontab.
3. Is the guard already auto-moving visits? Check the `ops_settings` weather `auto_reschedule_enabled` value and `SELECT COUNT(*) FROM service_packages WHERE auto_reschedule=1`.
4. Is there enough decision history to learn from? `weather_action_log` grouped by `action_type` for the last 180 days.
5. How big is each gap? Over the last 30 days, count auto-completed clock entries, `auto_stopped` timers, and completed visits with no time entry. That shows whether "no time" is mostly office completions, which would be noise.
6. Is `job_visits.weather_status` filled for today and tomorrow? The guard only runs at noon.
7. How are crews modelled? Does `calendar_stop_crew` see real use? Which users have `location_tracking_enabled` set and a `device_type`?
8. Timezones: MySQL `NOW()` is Eastern and the app is Pacific. Every comparison goes through `UNIX_TIMESTAMP` on both sides, with pure rules unit-tested at fixed timestamps (local).
9. HeadBrain API shape: read `feature/sam-sales-head` when it lands, and rebase onto it (local).

All of the prod checks are read-only `SELECT`s that Tim (or I, with his OK) runs once in his logged-in Chrome.

## 3. Files to create (Otto's files only)

- `database/migrations/1150_otto_suggestions.sql`: kind (`weather_move`, `weather_keep`, `clock_out`, `job_timer`, `no_time`, `silent_tracker`, `stuck_visit`), subject, for_date, suggestion_json, confidence, status (`open`/`accepted`/`edited`/`dismissed`/`expired`), outcome_json, decided_by, decided_at. Unique on (kind, subject, for_date).
- `1151_otto_questions.sql`
- `1152_otto_lessons.sql`: learned rules, scoped to a service type (weather tolerance), a crew member (patterns) or a plan (typical duration).
- `app/Modules/Operations/Services/`:
  - `OpsDeskService` (`ready`, `today`, `weather`, `gaps`, `brief`)
  - `OttoSuggestionService` (scan, decide, apply on click)
  - `OttoBadgeService`
  - `OttoQuestionService`
  - `OttoBrainService` (thin, on top of `HeadBrain`)
- `app/Modules/Operations/Api/otto.php` with modes `stats`, `suggestions`, `decide`, `questions`, `answer`, `find_slot`; shim at `public/crm/api/otto.php`.
- `public/crm/includes/otto-card.php`, `public/crm/js/otto-card.js`, and a CSS subsection "OTTO — OPERATIONS HEAD" appended after the deck section.
- `tests/Unit/Operations/*Test.php`, registered in `tests/bootstrap.php`.
- In the deck: swap the placeholder for an include, and nothing else.

## 4. What Otto shows, suggests and learns

- **Says:** "Hey Tim — 3 crews, 21 stops today, 6 done. Rain after 2 pm tomorrow puts 3 mows at risk. Nigel's phone has been quiet 40 min."
- **Stats:**
  - Today: stops done/total, crews out, on the clock now.
  - Route: the battle-card verdict and drive estimate.
  - Weather: visits flagged for today/tomorrow that are still undecided.
  - Gaps: open/auto-closed clock-outs, silent trackers, visits with no time.
  - Right first time: % of suggestions kept.
  - A winter outlook line, Nov–Mar only.
- **Suggestions,** each with Do it / Not now / Why:
  - Move or keep a flagged visit. The slot comes from `findAlternateSlot` only when Tim clicks.
  - A clock-out time from the last GPS fix or the last timer end.
  - A job-timer correction capped to the shift.
  - A duration for a no-time visit, from GPS dwell or else the estimate.
  - "Check Nigel's phone", with the `likelyCause` reason.
  - If the guard auto-moved anything: "I moved these — undo?"
- **Learns:**
  - Weather tolerance per service type, from Tim's keep/move decisions at a given rain %.
  - Crew patterns from his answers, e.g. "truck tablet off on weekends".
  - Typical visit durations from no-time fixes.
- **Badges** (last-N streaks, which can be lost):
  - Clock fixer: 10 suggested clock-outs kept within ±5 min.
  - Weather wise: last 10 weather calls agreed.
  - Quiet catcher: 5 silent flags confirmed real.
  - Gap closer: 25 gaps closed.
  - Clean week: a timesheet approved with zero open gaps.
  - 10 in a row.
- **Brain units:** weather tolerances, crew patterns, duration lessons, badges, trusted suggestion kinds. Counted from `ops_settings` `otto_brain_baseline`.
- **Questions** (asked when confidence is low):
  - "Rain 55% at 10 am on 3 hedge jobs — do you keep hedging in light rain?"
  - "Nigel's phone went quiet at 2:10 — break, dead zone, or a real problem?"
  - "V-123 has no time — was it about 45 min?"
- **`brief($ownerFirstName)`:**
  - priority 1: today's undecided NOT_OK weather, a silent tracker on the clock now, yesterday's open clock-out.
  - priority 2: this week's timesheet gaps.
  - priority 3: info.

## 5. Tests and verification

- Pure unit tests for:
  - gap rules (suggested clock-out, cap to shift)
  - tolerance learning
  - badge `compute`
  - brain `combine` through `HeadBrain`
  - `brief` shape and priority
  - timezone edges at fixed timestamps
- `vendor/bin/phpunit` all green and `php -l` on every file.
- Render the card locally with a stub DB, per the render-before-deploy rule.
- Confirm that clicking Do it changes the database row and that nothing happens without the click.
- Never deploy. Never push `feature/language`.

## 6. Decisions for Tim

1. **Who sees Otto?** Proposed gate: `jobs.edit`. The deck is currently hidden unless the user has `expenses.approve` and Penny is ready, so the lead session has to loosen the deck gate.
2. **One-click apply or link-out?** Recommended: weather move/keep, clock-out time and visit duration apply on Tim's click, with the change noted on the record. Nothing ever texts a crew in v1.
3. **The guard's existing auto-reschedule.** Keep it, show its moves with an undo, or turn it off?
4. **Thresholds.** Silent tracker at 15 min (reuse), the no-time window at 14 days, and auto-closed shifts always flagged.
5. **Fix the two findings** in separate commits (the guard log keys; CSRF and permission on `weather-actions.php`): yes or no?
