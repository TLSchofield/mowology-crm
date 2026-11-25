# Task: native Android driver workflow — close the gaps with the web driver portal

Branch codex/native, package ca.mowology.crew. All of this is behind the EXISTING `native_trip_report`
switch (and `native_timeclock` where the clock is involved). Do not add new switches. Do not touch
iOS, receipts, plants, camera, quiz or tracking code. Do not enlarge DayViewModel/DayScreens/
DayRepository: put new logic in NEW small files (a `*Logic.kt` of pure functions with unit tests, a
screen file, a ViewModel) and call them from the existing ones with the smallest possible edit.

## Server contract (already live; read the reference copy of the server code you were given)
- `POST /schedule/clock` {action:'clock_out', ..., trip_gate: true}: for a LIVE tap that is not a replayed
  offline punch (`queued:false`) the server answers HTTP 409 with
  `{success:false, clocked_in:true, error_code:'post_trip_required', post_trip_required:true, trip_report_id, trip_sequence, error}`
  if the person still has an open trip. A replayed offline punch (`queued:true`) is NEVER refused.
- `POST /schedule/trip-report` {action:'pre_trip_bypass', pin, reason?, vehicle_id?}: a manager's PIN waives
  the pre-trip for one trip and opens the trip (so a post-trip is still needed). Errors are 4xx with
  `{success:false, message}`: 400 PIN required, 403 wrong PIN / not configured, 429 locked out (10 minutes).
  Success returns the usual full trip status plus `report_id`.
- `GET /schedule/trip-report?mode=history[&limit=30]` -> `{success, trips:[{report_id, report_date, trip_sequence,
  vehicle_id, pre_trip_at, post_trip_at, odometer_start, odometer_end, safe_to_drive, pre_trip_skipped,
  state:'none'|'open'|'closed', has_pdf}]}` — the person's own trips only.
- `GET /schedule/trip-report?mode=pdf&report_id=N` -> application/pdf (own reports only), 404 JSON if none.
- Some people drive only some days. NEVER assume a person is a driver. Everything below applies only when the
  person has answered "driving" for the shift (existing driver-declaration flow) or has an open trip.

## 1. Clock-out: let the server's refusal route to the post-trip screen
Today ClockOutGateLogic decides from the phone's LOCAL trip state. Keep that (it is what works offline).
- Live clock-out body gains `"trip_gate": true` (only when `native_timeclock` is on and the send is a live tap;
  never on rows built by saveUnconfirmedPunch/offline queue).
- If the live send comes back 409 with `error_code == "post_trip_required"`, do NOT show a raw error and do NOT
  leave the clock-out sitting in the pending queue as a plain retryable punch. Instead behave exactly as the
  existing REQUIRE_POST_TRIP path does (needsPostTripReport = true, postTripOwnerId, remember the pending
  clock-out, await the post-trip, then send the clock-out again). Keep the person on the clock in the UI.
- Pure function in a new file: classify an HTTP status + body into {SendOnward, RouteToPostTrip, Refused(message)}
  with tests for: 409+post_trip_required, other 409, 403, 200, malformed body, missing error_code.

## 2. Pre-trip is mandatory for someone who said "driving"
The web portal shows a pre-trip screen that cannot be dismissed until it is saved or a manager PIN waives it.
Native today only offers it from the Trip tab. After the driver declares "driving" (or the app starts and the
status says declared=true with no open trip), show the existing pre-trip editor as a full-screen, non-dismissable
gate. It ends only when: the pre-trip is saved (online OR queued offline — offline save counts), or the PIN bypass
(below) succeeds, or the person changes the answer to "not driving". Back-press must not skip it. Pure decision
function + tests (declared/driving/open-trip/closed-trip/not-driving/unasked combinations).

## 3. Manager-PIN bypass on the pre-trip gate
A "Manager override" button on the gate opens a small sheet: numeric PIN field (password-masked, never logged,
cleared on dismiss), optional reason, Submit. It POSTs `pre_trip_bypass` through the app's normal authenticated
client. It is NEVER queued or retried in the background: if offline, say "A manager PIN needs a signal — do the
pre-trip now and it will send when you are back in range." Show 403 "Incorrect PIN", 429 lockout message and
generic failure text from the server's `message`. On success refresh the trip status (the trip is now open).
Never store the PIN anywhere; never put it in a log line or crash report (check VitalsRedactor patterns).

## 4. Driver log with PDF
A "Trip log" list reachable from the Trip tab (where the pre/post-trip entry already is): GET history, rows show
date, trip number, vehicle, state, skipped-pre-trip flag. Tapping a row with `has_pdf` downloads the PDF with the
bearer token to the app's cache dir and opens it with ACTION_VIEW through the app's existing FileProvider (add a
cache-path entry only if none exists), with a share fallback. Offline: the list shows the last loaded copy (store
the JSON in the same way other cached screens do) and PDF says "needs a signal". Large PDFs must stream to disk.

## Rules
- Unit tests for every new pure function; follow the existing Logic/Test pairing. NO source-scan tests.
- No new dependencies. Kotlin only for new code. Match the Ittai design components already used in trip/.
- Do not weaken any existing queue/refuse rule, and do not add a ninth copy of one: if you must classify an HTTP
  outcome, reuse the day action rule (DayLogic) or a clearly named new pure function.
- Tests: `./gradlew --offline testMowologyDebugUnitTest assembleMowologyDebug` must pass.
- Finish with a short summary: files added/changed, anything you could not do and why.
