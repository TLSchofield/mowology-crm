# Android crew app — native clock in/out, job cards and timesheet (plan, 2026-10-12)

Tim 2026-10-12: native clock in/out — "as it attaches to the job cards shouldn't they be native as
well?" — "and timesheet screen". Trigger: Marc Nelitz, 8 Oct — Nigel on site 10:20–10:35, job timer
started at 10:34:51 and stopped 17 s later, visit never completed; the nightly roll-forward would
have auto-skipped it. A native day screen can prompt on arrival and on leaving.

## Today (checked 2026-10-12)
- App `ca.mowology.crew` 1.3.3 (build 13): a Capacitor shell opening `crm/timeclock/my-schedule.php`.
  Native already: GPS engine (~1,660 lines Kotlin: service, geofences, Room queue, sync, boot),
  push (146 Android pushes in 30 days), camera permission. Every screen is web.
- iPhone is fully native and already uses JWT endpoints for all three screens:
  `/api/schedule/clock` (in/out, status, `mode=week` timesheet), `/day`, `/week`, `/timer`
  (+ `mode=active`), `/visit-work`, `/visit-photos`, `/tracking` (geofences), `/trip-report`.
- The web page already hands the native GPS engine a 16 h app token at clock-in (`startSession`).
- APK is built `assembleDebug` — **no release signing key**.

## Unknowns and how each is settled (cheapest first)
| # | Unknown | Settled by |
|---|---|---|
| 1 | Token for native screens before clock-in (the GPS token is issued AT clock-in) | read `capacitor-bridge.js` + the endpoint that mints it; if it needs a clocked-in user, add a "session → app token" call for the logged-in web session (server, small) |
| 2 | Every field the iPhone screens send/expect | the Swift models are the spec (`ios/.../Features/Schedule`, TimeClock, Timesheet); no guessing from PHP |
| 3 | Offline behaviour | the owner's rule (`feedback_server_first_local_backup`): send to the server first, keep a local copy only as backup; GPS stays store-first. Reuse the Room database already in the app |
| 4 | Release signing | Tim decides: create one release key now (kept with the build secrets, never in git) — next install is a one-time reinstall per phone; every update after that is normal |
| 5 | Testing without crew phones | Android emulator on this Mac (SDK present; emulator image unverified) for screens; one test on Nigel's phone before release |

## Steps (each proven before the next)
1. **Server** — confirm or add the token call (1); unit tests. Deploy.
2. **Native Today screen** (Kotlin + Jetpack Compose inside the existing app): clock in/out, breaks,
   the day's job cards in route order — start / stop timer, complete, skip *with a reason* (new:
   the reason Otto needs), notes and checklist via `visit-work`. Photos and receipts stay web for
   now (open the existing pages).
3. **Arrival / leaving prompts** from the geofences the engine already loads: "At Marc Nelitz's —
   start the lawn cut?" / "Leaving — mark it done?" Tap to act; nothing automatic.
4. **Native Timesheet** — the week from `clock?mode=week`, same as the iPhone.
5. **Release 1.4.0** behind a switch (`native_day` in app-version.json): off → the web tabs as today.
   Emulator, then Nigel's phone, then switch on. Update `surfaces.md` parity rows.

Not in this pass: receipts capture (next), quotes, Home Base cards, the office screens.
