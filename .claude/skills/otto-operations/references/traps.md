# Traps already hit — read before any change to schedule, clock or GPS

Add a new entry the same day a mistake is found: date, what happened, the signature, the rule.

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
