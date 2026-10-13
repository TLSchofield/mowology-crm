# Traps already hit — read before any change to schedule, clock or GPS

Add a new entry the same day a mistake is found: date, what happened, the signature, the rule.

## 2026-10-13 — The rollover cron skipped contract visits silently, and runs at 8 pm, not 11
`auto_rollover.php` skipped any past-due recurring visit once `rollover_count` ≥ `max_rollover_days`
(3), when the next day already had a visit, or when the plan had ended — with no contract check.
Oct 5–11 it auto-skipped monthly-contract visits (CTR-0002/0004/0005/0007, PLN-0063/0069/0072/0076/0103…)
and nobody decided; the schedule just read "Skipped — last done N days ago". Its cPanel line says
23:00 but the server clock is Eastern: `cron_runs` shows it at 20:00 Pacific. **Rule:** a binding
contract or prepaid visit is never skipped by a cron — `MissedVisitRules::rolloverAction()` holds it
open and Otto's missed-visit review shows it next day; a visit decided in the review
(`otto_missed_review_items`, migration 1349) is left alone. Times in a cron's comment are the
server's; check `cron_runs` before promising a time.

## 2026-10-13 — Equipment input: plates and a shared worktree
- **Plates print the label and the value on separate lines** ("MODEL" / "HRC216K3HXCH" / "SERIAL NO." /
  "MAKA-2058647" on Tim's Honda). `LabelReaderService::read()` only read same-line values and named the
  machine "Model". **Rule:** model/serial reading lives in `EquipmentPlateParser::parse()`; the label reader
  falls back to it. Add new plate layouts as fixtures in `EquipmentPlateParserTest`, never a second regex.
- **A model number is not fuzzy.** The catalogue's fuzzy tier would queue HRX217 as "nearly" HRC216 —
  machines resolve with `CatalogService::resolve(…, suggest: false)` via `EquipmentCatalogService::linkModel()`.
- **`git commit` in a shared worktree commits the WHOLE index** — another agent's staged files went
  into the equipment iOS commit (b533037b, message amended to say so). **Rule:** commit with explicit
  paths (`git commit -m … -- <paths>`), never a bare `git commit` after `git add`.

## 2026-10-12 — Fable review: time, pay, visits, GPS, suppliers
Independent reviews found 33 defects; fixed the same day. **Rules now enforced:**
- **Pay** is BC overtime per DAY (8 h → 1.5×, 12 h → 2×) and per Monday–Sunday WEEK (40 h), summed
  over the pay period — `OvertimeCalculator::calculatePeriod()`. Payroll used to apply 40 h once to a
  two-week lump with no daily rule (overpaid ~$350–590 per person per period at Mowology).
- **Stale shifts** are judged by elapsed time ≥ `auto_clock_out_hours` (12 on prod), never by date —
  overnight snow/salt shifts were closed at midnight with a future clock-out.
- **Approved weeks are locked.** Every edit/add/void of time (`TimeEntryGuard::refusal()`) refuses;
  reopen the sheet (timesheets API `reopen`, needs a reason) first. Only an admin changes their own
  time (Nigel and the truck logins are managers). Manual entries need a clock-out and may not overlap.
- **Recalculate the entry's week**, not today's — every timer stopped, both weeks on a move.
- **DST:** minutes come from zone data (`TimeclockRules::minutesBetween()`). Production tzdata has BC
  on permanent UTC-7 from 2026-11-01 — there is no fall-back that night. Don't hard-code DST dates.
- **Duration and end_time move together** (set_duration, orphan-timer cron); voided shifts are not hours.
- **Stop/complete/skip need crew on the visit**, an own running timer, or admin/manager.
- **Visits:** completed never becomes skipped/weather/cancelled; a second "completed" is a no-op (the
  desktop Complete + its timer stop ran the hooks twice); a day that already has a live visit for the
  plan never gets a holiday-bumped second one; skipped visits are not billed by Complete Stop; stop
  extras are billed once, as an invoice line. Moving a stop never carries or orphans finished visits;
  every move stamps `original_scheduled_date`, and a recurrence edit keeps those moved visits.
- **GPS:** mock fixes never trigger arrival/dwell/departure or count as client route proof; clients see
  only fixes on the property; event coordinates only while collection is allowed; withdrawn consent
  silences the quiet-phone alert.
- **Suppliers:** the From ADDRESS must belong to the supplier (DMARC/SPF checked) before a filled form
  goes out; copies only to the supplier's domain; an expired licence is never attached.
- **Weather/bylaw moves** never land on a Sunday/BC holiday (snow & salt excepted) or outside the city's
  equipment hours; a bylaw retime checks the END time too.
- **iPhone queues** drop an item only on an explicit 4xx; one drain at a time; items carry the user
  id; replays reuse the original timestamp; the driver pre-trip question keys off server shift state.
- **Deploying from a shared worktree:** `deploy-merged.py HEAD HEAD~1` uploaded the OLD files when
  other agents had committed in between (the 3-way merge "merged in" live's old copy and still printed
  "live"). Deploy with explicit SHAs (`<sha> <sha>~1`) and confirm `prod-file.sh` says `same`.
**Dropped:** none of the findings failed verification. #5 (DST) holds only for historic data and hosts
with older tzdata — production has no 2026-11-01 change. **Open:** supplier mail still sends from
no-reply@ with Reply-To office@ (MessagingService); Ada/Tim to decide.

## 2026-10-12 — Fable review, round 2: web timers, crons, GPS endpoints
- `app/Modules/Jobs/Api/job-timer.php` (web/Android) stamped `NOW()` on offline replays while
  `timer.php` already used `OfflineTime::accept()`; its auto clock-in bypassed `clockIn()`. Both now
  take the tap time (`client_time`, else the offline queue's `X-Queued-At`). **Rule:** a second
  endpoint for the same action gets the same fix the same day — grep for the sibling.
- Approved weeks were rewritten by crons: `recalculateTimesheetTotals()` now skips (and logs) an
  approved week; approving refuses while a shift or job timer is still open in it
  (`TimeEntryGuard::approveRefusal()`).
- The rollover cron closed live timers at `NOW()`, uncapped: it now caps like the orphan-timer cron
  (`TimeclockRules::cappedTimerMinutes()`), flags them and recalculates.
- **CSRF added** to job-timer, location-ping, truck-ping, clusters (session path; JWT exempt). Every
  web caller was updated AND its pinned `?v=` bumped (`schedule.php` pill workflow,
  `appstack_footer.php` widget + pull-forward) — the host caches JS 24 h, and an old script without
  the token would have 403'd crew job starts. **Rule:** adding CSRF to a crew endpoint = update every
  caller + bump its `?v=` in the same deploy.
- iPhone `ClockQueue` dropped a punch after 5 server errors and counted it as sent; it is now kept,
  marked refused and shown on the Time Clock screen until the person clears it.

## 2026-10-10 — GPS break flags flooded by the truck and forgotten clock-outs
First 14-day run flagged 19 of 24 shifts: the truck login is a vehicle, and 12 h shifts were
forgotten clock-outs. Fixed in `BreakService` (people only; ≥ 690 min ignored) → 6 flags. GPS
still can't see a lunch on the job site. **Rule:** flags stay off (`break_flags_enabled` = 0)
until there is a break button; exclude devices, not people.

## 2026-10-10 — Supplier forms: print-and-sign and owner-only questions
Foreshore's credit application had no form fields and asked for SIN, bank reference, credit card,
trade references and a personal guarantee. A filler that only handles fillable forms would drop
it silently. **Rule:** record it `unfillable` and tell Tim "needs you"; never auto-fill the
owner-only list in `CompanyProfileService`.

## 2026-10-10 — lastInsertId() read after another statement returned 0
The form recorder inserted, ran an UPDATE, then read the insert id → 0; the poll reported
"0 forms" for a saved form. **Rule:** read the PDO insert id immediately after the INSERT.

## 2026-10-10 — Google geocoding refused the CRM's key
The Maps key is referer-restricted (browser-only); the Geocoding API rejects it. **Rule:** server
geocoding uses `SupplierDirectoryService::googleGeocode()` with a server key or falls back to
`SupplierDirectoryService::nominatim()`; only building-level points become places.

## 2026-10-10 — `time-clock.php` refused POSTs without a CSRF token
Six callers (driver-portal clock in/out, schedule, team page) sent none. Nigel got "Invalid CSRF
token"; the truck tablet (Home Base, which sent one) worked 6 min later. Driver-portal clock-out
also read a failure as success. **Rule:** "the tablet works, the phone doesn't" → compare logins
and landing pages first.

## 2026-10-10 — Unscheduled tray empty for everyone
`getUnscheduledVisits()` selected a wrong column and its catch returned `[]`. **Rule:** a
`catch → return []` widget hides schema mistakes as "nothing to do"; run the SQL once on
production after touching it.

## 2026-10-08 — One crew script froze every Android tap
The pull-forward feature (loaded on every crew page) froze taps on Nigel's phone and the truck
tablet, and on iPhone its root sheet blocked the invoice sheet. **Rule:** crew-wide scripts and
root-level sheets ship behind a flag, off by default; enable for Tim's phone first.

## 2026-10-08 — CSS deploy invisible on the schedule
`public/crm/jobs/schedule.php` pins `mobile-cards.css?v=` by hand and the host caches 24 h.
**Rule:** bump that `?v=` with every `mobile-cards.css` change. Also `_av()` only exists after
`appstack_head.php` — calling it in `$extraHead` is a silent fatal (HTTP 200, truncated page).
Verify a deploy by the page ending in `</html>`.

## 2026-10-06 — Truck's auto-started timer blocked the crew from completing
Timer ownership was per login; the truck tablet's proximity auto-start owned the only timer and
Tim's Mark Complete failed. Fixed on the deploy branch (completing ends every active timer on the
visit); not present in this checkout — unverified here.

## 2026-09-25 — Trucks were excluded from the silent sweep; a 21 h blackout raised nothing
**Rule:** exclude a device class from nudges, never from detection. Also: Location permission can
be "Allow all the time" while the device's Location switch is off ("GPS unavailable", code 2).

## 2026-09-25 — Cron "Never run" forever
A cron that does not call `recordCronRun()` (and `require_once` `app/Services/CrmFunctions.php`)
looks identical to an unscheduled one.

## 2026-09-24 — `MW_CSRF_TOKEN` exists only on AppStack pages
The native GPS engine posted the token from Home Base/app-launch (standalone) → 403 → `return
null`, no log. Nigel's phone went 3,487 fixes → 1. **Rule:** shared JS refreshes via
`public/crm/api/get-csrf.php`; a bare `return null` on auth failure is an outage with no
signature — log it.

## 2026-09-21 — Automatic clock-in skips anything hooked to the Clock In button
The driver question lived in the button handler; arrival detection clocked him in instead.
**Rule:** shift gates key off server shift state.

## 2026-09-19 — GPS audit: dead phones looked alive
Clients re-sent the last position on a timer while the server stamped `NOW()`; offline queues
replayed into a rate limit that answered "success, skipped"; driving past another crew's job put
you on the clock. **Rule:** trust device time and accuracy, judge on the server, and look for
silence.

## Two stop endpoints
A stop field added to `calendar-stops.php` but not `day.php` never reached iPhone (May 2026).

## 2026-10-12 — A status read threw away an offline punch; job timers ignored the tap time
iPhone `TimeClockViewModel.loadStatus()` cleared `ClockQueue` whenever the server answered, so
opening the app after signal returned could discard an offline clock-in. Separately, `timer.php`
stamped `NOW()` on start/stop, so a job started in a dead zone at 9:00 and synced at 2:00 started
at 2:00 (Android 1.4.0 sent `client_time` and it was ignored too). **Rule:** a queue is drained,
never cleared, before reading server state; every offline action carries its tap time as
`client_time` and the server decides with `OfflineTime::accept()` (clock, timer). Fixed in iOS
1.3.26 and the timer endpoint the same day.
