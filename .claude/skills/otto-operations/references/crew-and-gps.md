# Crews, GPS, time clock, breaks and trips

## Who sends positions
| Source | Table | Notes |
|---|---|---|
| Crew phones (Android native engine via `public/crm/js/capacitor-bridge.js`; iPhone `GPSTrackingService.swift`) | `crew_location_history` | judged by `TrackingIngestService::ingest()` |
| Truck tablet — a login with `users.device_type = 'truck'` (the Dodge Ram) | same, as a user | a vehicle, not a person: no breaks, no push/SMS, but IS in the silent sweep |
| Trackimo tracker | `vehicle_location_pings` | `app/Modules/Tracking/Cron/trackimo_poll.php` → `TruckLocationService::savePing()` |

## The server owns the boundary (Decision-Log 2026-09-19)
- A fix is kept only if the user is active, tracking is on, consent is OK (switch
  `tracking_consent_required`, OFF until both apps ship consent) and the DEVICE time falls inside
  a clock entry. Every response carries `policy{tracking_allowed, reason, tier}`
  (`TrackingIngestService::policy()`); clients must obey it.
- Tier depends on WHERE the crew is (enhanced on a job/site), not motion — standing still on a
  client's property is exactly when the record matters (salt/snow liability).
- Home pings are kept and flagged (`GeofenceService::isOfficePing()`), never suppressed while a
  job is in progress; forgot-clock-out SMS needs them.
- Timezone: DATETIME columns are local (America/Vancouver). Never append `Z`.

## Silence is the failure
- "Quiet" = on the clock, tracking on, nothing heard for 15 min (`OttoRules::SILENT_MINUTES`).
  `TrackingHealthService::sweep()` alerts; truck tablets get an office email only.
- Diagnose with per-day fix counts per user, then the `accuracy` column (native fixes carry
  accuracy every 5–6 s; widget pings are NULL and isolated). Page:
  `public/crm/timeclock/tracking-health.php`.
- A phone "that doesn't work" while the tablet does is usually a different landing page (CSRF),
  not a handset (see traps).

## Time clock
- Server: `app/Modules/Team/Services/TimeclockFunctions.php`. Web/Android POST
  `app/Modules/Team/Api/time-clock.php` (CSRF required on every POST since 2026-10-02); iPhone
  JWT `app/Modules/Schedule/Api/clock.php`, offline `ClockQueue.swift`.
- Proximity auto-start (`checkProximityAutoStart()`) can clock in and start a job together. Shift
  gates (driver question, pre-trip) must key off server shift state, never the Clock In button.
- Driver pre/post-trip: `app/Modules/Schedule/Api/trip-report.php` (iPhone) and the web form;
  the vehicle form is mandatory, clock-in stays automatic (Decision-Log).
- Forgotten clock-outs: auto clock-out cron closes at 12 h; Otto suggests the real time.
  Any shift ≥ 690 min (11.5 h) is treated as a forgotten clock-out, not a long day.
- Timer endpoints must load the plan functions or `updateVisitStatus()` is skipped silently —
  verify visit completion in the DB, not by the 200.

## Breaks (`BreakService`, migration 1318; owner 2026-10-10)
- A break = a stop of 20+ min during a shift, more than 15 min from clock in/out, not at a client
  property and not at a work place (dump, supplier, yard, shop, fuel). Recorded automatically in
  `time_clock_breaks` (source `gps`) by `BreakService::run()` from the trip cron; re-running a
  day replaces its GPS rows.
- Mowology pays breaks (`breaks_paid` = 1): records never change pay. Another tenant with unpaid
  breaks would deduct them (onboarding question).
- BC rule: no more than 5 h without a 30-min meal break → `BreakService::complianceGap()` writes
  `time_clock_entries.break_note`. Shifts ≤ 5 h or ≥ 11.5 h are never flagged; truck login excluded.
- Flags stay OFF on Otto's brief (`break_flags_enabled` = 0, default): GPS cannot see a lunch
  eaten on the job site. First run flagged 19 of 24 shifts. Turn on only with a crew break button.
  There is no break button in either app yet.

## Trips (Decision-Log 2026-10-07)
- `TripSegmentService` splits truck pings into stops (under 3 kph for 4+ min) and drives,
  labels them against properties (120 m) and `ops_places`.
- "One man" = one person in the truck, decided from crew phone GPS
  (`TripSegmentService::crewVerdict()`); Tim's toggle overrides.
- `TripCostService::process()` prices driver minutes × rate × burden + km × $0.70 default +
  same-day receipts; unnamed stops go to the brief so Tim names them once.
- UI: `public/crm/includes/otto-trips.php` + `public/crm/js/otto-trips.js`; API
  `app/Modules/Operations/Api/trips.php` (`?mode=`, never `?action=`).

## Schedule
- Stops: `getCalendarStops()`. Two JSON shapes hand-pick fields — add a stop field to BOTH
  `app/Modules/Jobs/Api/calendar-stops.php` and `app/Modules/Schedule/Api/day.php`.
- Unscheduled tray: `getUnscheduledVisits()` in `app/Modules/Jobs/Services/Plan/PlanCrew.php`
  (a swallowed bad column hid it for everyone until 2026-10-10).
- Snow/salt: signing runs `SnowContractService::setupFromSignedQuote()` — one daily route plan
  (every day incl. Christmas, Nov 1–Mar 31), winter crew from `snow_route_crew_user_ids`
  (Nigel leads so his phone records runs), crew picks the service on completion
  (`app/Modules/Schedule/Api/snow-route-choice.php` on iPhone), one invoice per run.
- Special requests on arrival: gated by `SpecialRequestGate::enforce()`, behind flags
  `special_requests_enabled` / `_user_ids` (OFF as of 2026-10-08).
