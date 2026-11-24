# Android app: efficiency review and refactor plan (2026-11-24)
Tim: "review android for efficiency, plan redesigns if needed, refactor where necessary." Basis: measurements of `codex/native` (2f75d42) plus two read-only audits by Claude agents restricted to the Android folder; the top findings below were re-checked in the code (marked **✓ checked**). Anything unproven says so. Not run on a device: no profiling numbers exist yet, so every saving is an estimate. (Process note: a first Codex audit was stopped because `~/.codex/AGENTS.md` sends Codex to read the Obsidian vault; log kept in `~/.mowology-codex/audits/`.)

## Verdict
The app is not slow by design but it is **busy by default**: loops that never stop, a screen that redraws everything every second, GPS tuned for the maximum, seven separate offline queues each with its own worker, and 49k lines of which a third sit in one package. Most of the waste is cheap to remove. The expensive parts (one queue, smaller files) should follow the trip/clock-out rebuild, not precede it.

## Measured facts
49k lines main / 12k test; `day` 15k (31%), then `office` 6.3k, `receipts` 6.2k, `ui` 4.9k, `tracking` 3.3k. Files over 900 lines: DayScreens 1,602, ReviewScreens 1,593, SchedulePlusScreens 1,286, MwCameraActivity 1,102, DayViewModel 914, MwTrackingService 911. `minifyEnabled false`, no resource shrinking. 8 Room databases, 10 SharedPreferences files (`mw_tracking` has 192 call sites), 33 near-identical `*Switch.kt` files, five 15-minute periodic jobs, 7 durable queues, Capacitor + 5 plugins + Firebase messaging still bundled.

## Tier 0 — quick wins (S, low risk, do first, Codex builds, tests prove nothing broke)
1. **One-second clock redraws the whole Today screen** ✓ checked (`DayScreens.kt:106-111`, used at :833/:1203/:1504). Return a lambda/State read only by the running-timer bar and the figure; tick only while a timer runs; stop when backgrounded (`repeatOnLifecycle`).
2. **Brain animation clock** ✓ (`ItOffice.kt:388-395`): returns a Double read in composition so the caller redraws every frame. Read it inside the draw lambda; pause off-screen.
3. **Five-second triple refresh forever** ✓ (`DayScreens.kt:902`: unsent fixes + receipts + plants). Make it lifecycle-bound and 30 s, then move to Room Flows.
4. **Trip queue polled every second for the Activity's life** ✓ (`TripReportViewModel.kt:47`): replaced by a Room Flow in the trip rebuild.
5. **Office documents polled every 2 s on the main thread** (`OfficeDocumentsScreens.kt:28,86`): observe the store or move to IO and update only when rows change.
6. **Hang watchdog wakes every 500 ms forever** (`HangWatchdog.kt:12,42`): 2–5 s, pause in background.
7. **`allowMainThreadQueries`** (`ActionLedger.kt:144`): remove, fix whatever it exposes (S).
8. **`remember` the derived values** in `CrewStopCard`, `allVisits`, `TimeDayView`, `RouteMapScreen` (S each).
9. **R8 shrinking** on the `ittai` flavour first (no web shell): keep rules for the two Capacitor plugins and the JS interface, upload `mapping.txt` for crash reports, run unit tests + a device smoke (sign-in, camera, receipt send, a tracking shift). Background-only failures are the risk (workers/receivers are created by name), so it needs the device check before it ships.

## Tier 1 — medium (M)
- **Remove `runBlocking` from tap paths** ✓ (`DayViewModel.kt:327` clock-out gate; `TripReportViewModel.kt:183,202,213`): disappears with the trip/clock-out rebuild (see `2026-11-24-android-trip-clock-audit.md`).
- **GPS cadence — a product decision, see below.** Both modes use `PRIORITY_HIGH_ACCURACY` ✓ (`MwTrackingService.kt:408-423`); geofences are already OS-registered so arrival detection does not need continuous high accuracy.
- **Take Capacitor/Cordova out of the Ittai and tenant-test builds** (`mowologyImplementation` only; shell + two plugins into `src/mowology/`; drop the per-flavour Capacitor config generators). Do NOT touch Firebase/push until we know whether native-only mode receives push: no native `FirebaseMessagingService` was found (not verified).
- **One preferences store + one switch cache**: typed per-user keys instead of hand-rolled `…_$user` strings; replace 33 copies of the `_last_seen/_fetched_at` logic with one `SwitchCache`. Fixes the two-identities problem in `mw_tracking` (`user_id` vs `app_user_id`).
- **Dead code**: ~37 functions referenced once (list in the audit: `MailSection`, `ChaseAdminSection`, `ItReveal`, `heldPaths`, …); verify each with grep + tests, then delete. No unused classes or dependencies found.

## Tier 2 — large (L), staged, each behind a switch
- **One durable outbox instead of seven queues.** Today: trip, photos, plants, receipts (Room), day actions, office ledger, and receipt-review actions (a JSON blob in prefs), four workers that repeat the same running-flag, now+watch work, token refresh and orphan rescue. Target: one `outbox` table (user, kind, client ref UNIQUE, payload, state, attempts, order key, strict flag, progress/result JSON), one `OutboxWorker` with a handler per kind, one scheduler and one watch job (cuts five periodic jobs to one). **Migration is high-risk (money and crew data):** keep the old DBs, copy once per kind, run the new path for NEW rows only behind a switch; trip first (the rebuild already makes it the simplest), receipts last; never delete a legacy DB in the same release; add integration tests for ordering and refusal per kind.
- **Split the six large files** along the responsibilities the audit found (e.g. `DayScreens.kt` → DayApp / TodayScreen / StopCards / TimeClockScreen / TimesheetScreen; `DayViewModel` → Clock / VisitActions / Timesheet / PendingClockOutStore; `MwTrackingService` → Notifications / LocationCapture / Uploader / GeofenceManager / PolicyPoller). Mechanical, no behaviour change, done one file per PR with the existing 1,000+ tests as the safety net.
- **Compose stability**: make `TodayState` and the per-stop slices immutable/stable and remember the lambdas so a state change redraws one card, not all.
- **Compose BOM/compileSdk upgrade** (pinned to 2024.02 / compileSdk 34): separate item, after the above.

## Decisions for Tim
1. **GPS trail detail vs battery.** Proposal: on a job (enhanced) 10 s / 10 m instead of 5 s / 0 m; otherwise balanced power at 60 s / 50 m with geofences promoting to enhanced on arrival; pause when the phone is still. Trial on one phone first and compare trail quality.
2. **Retire the web shell?** Mowology still falls back to it; push may depend on it. Decide when the native-only path is proven.
3. **Appetite for the outbox migration** (large, valuable, careful).

## Order
Now: Tier 0 items 1–8 as one Codex build after the trip fix is reviewed (small diffs, pure refactors with tests) → trip/clock-out rebuild (already designed) → R8 on `ittai` with device smoke → Tier 1 → outbox (trip first) and file splits one at a time. Never two Android builds on `codex/native` at once.

## Measure it (before/after; the emulator harness must be fixed first)
APK/AAB size; cold start (`adb shell am start -W`); battery over a full shift (`dumpsys batterystats` or the app's own vitals); JankStats per screen (already collected); background wakeups per hour; unit tests green (1,036 today); lines in `trip/` and `day/`. Acceptance for Tier 0: Today screen recomposition count while a timer runs drops from every second to the timer bar only; no loop runs while the app is backgrounded.
