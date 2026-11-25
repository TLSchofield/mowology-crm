# Android trip + clock-out: process and architecture audit (2026-11-24)

Prompted by Tim: "so many problems… it must be because it's over-complicated." Evidence below is from reading the code on `codex/native` (mowology-android-codex), the iPhone app, the server, and production records for one driver.

## What it is today (measured)
| | Android | iPhone | Server |
|---|---|---|---|
| Trip/clock-out code | 1,221 lines in `trip/` + 71 trip/clock references in the 900-line `DayViewModel` + gate/declaration logic + routing in `DayScreens` | 802 lines (views + view model + models) | 605 lines |
| Ways to be "in a trip" | 6+ places hold state: Room queue; prefs `driver_declaration_pending_shift_*`; prefs `driver_declaration_shift_*`; prefs `pending_clock_out_at_*` and `_offline_*`; 5 flags in `TodayState` (`needsPostTripReport`, `postTripOwnerId`, `driverDeclaration*`); `editorOwner`/`stateOwner` in the trip view model | one: the queue-implied open trip, else the server's `trip_state` | one: a pre-trip with no post-trip |
| Loops | a 1-second poll of the queue, forever; a 250 ms poll waiting for the post-trip | none | – |
| Clock-out with an open trip | remember an "intent", flip a full-screen gate that replaces the whole app (`return` in `DayScreens`), poll until the ledger says allowed, then send the clock-out | tap Clock out -> refresh -> if open trip show the post-trip sheet -> when saved, clock out | – |
| One screen, three jobs | `TripReportScreen` is declaration + pre-trip + post-trip behind a "Report type" radio, also reachable from the Trip tab | separate, single-purpose views | – |

## Why it keeps breaking (root causes, in order)
1. **Android invented its own definition of "open trip."** It treats a *declaration* as opening a trip (`declarationOpensTrip` is even a test); the server and the iPhone open a trip only at the *pre-trip*. Any stray declaration therefore demands another post-trip: the loop on the driver's phone (seven stray "Declaration - Sent" rows).
2. **A blank form is the default.** After a post-trip, a refusal or a discard, the screen falls back to a blank Driver declaration form. Pressing Queue creates a durable junk row *before* checking it belongs to a shift, then shows an internal message ("shift marker could not be saved").
3. **A gate built from flags and polling instead of a sequence.** The clock-out flow depends on remembered intents, flags in another view model and two polling loops, which can disagree (pending intent gone but flag still set = trapped screen).
4. **No one flow spec, no end-to-end test.** The pieces were built as ~20 small Codex tasks (p1-08 … p1-13, "trip-vehicle-string-ids") each adding rules and tests of its own assumptions. Unit tests passed while the real sequence (clock in -> declare -> pre-trip -> post-trip -> clock out) was never run on a device: the emulator harness does not currently start and the iOS simulator runtime is missing.
5. **Plain-language and escape-hatch gaps.** Raw status text ("Server status is up to date", "Trip state: open"), internal error strings, the jargon "Queue report", a "Report type" radio a driver should never see, and no always-available way out or "call the office".

## Verdict
Yes: it is over-built for what it does, and the size comes from solving the same question (is a trip open?) in several places. The server needs one rule; the iPhone already shows the simple shape. Offline queueing is a real requirement and stays; everything around it should shrink.

## Target design (what "simple" means here)
**One question, one function.** `tripNow(queue, server) -> NONE | OPEN` where OPEN = a pre-trip exists with no later post-trip (queue first, else server). Used by every screen; no other copies.
**Three single-purpose moments, none of them a form the driver has to find:**
1. *Clock in* -> a dialog "Are you driving today?" Yes / No (once per shift; nothing may stand in front of it). Yes -> offers the vehicle check ("Start vehicle check" button, "Not now" allowed only if the office policy allows).
2. *Vehicle check (pre-trip)* -> one screen, checklist, big Pass/Fail rows, one primary button "Save and send".
3. *Clock out* -> tap Clock out -> if a trip is open, a sheet "Finish your trip first": ending odometer (start shown, numeric keypad), notes, "Save and clock out". Saving writes the post-trip and the clock-out in order into the same durable queue; nothing polls or flags. "Not now" closes the sheet and leaves him clocked in.
**Deleted:** the "Report type" screen and Trip-tab form chooser, the full-screen gate route, the pending-clock-out prefs and `awaitPostTripThenClockOut`, both polling loops (observe Room as a Flow), the declaration prefs markers (the shift id lives in the queued declaration).
**Never trap:** every screen has a visible way out and, on any error, a "Call the office" button.

## AAA standard for these screens (house rule v1.2.2)
Text 7:1; focus rings 4.5:1; every control at least 48 dp (56 dp for the main action); text from the phone's size setting; nothing floating over content; one primary action per screen; status as icon + word, never colour alone; errors say what happened and what to do, in the driver's words ("Your ending odometer (72,270) is lower than the start (72,276). Check it, then Save again."); no internal terms (queue, ledger, marker, server status). Check with the Ittai contrast script and the Codex design studio before building.

## Plan
1. **Now (urgent, small):** finish the two running Codex builds: stray declarations refused before any write; a declaration no longer opens a trip; "Are you driving today?" always first; stuck gate cleared. Ship to the one driver's phone only after review.
2. **Next (the simplification):** replace the gate/intent/polling design with the linear sequence above, one screen per moment, matching the iPhone; delete the code it makes unnecessary. Design the three screens in the Codex studio first (AAA), then build with Codex, then review.
3. **Prove it:** a written 8-step scenario run end to end on an emulator before any crew phone gets it: clock in; answer No; clock in next day, answer Yes; pre-trip; clock out without post-trip (sheet appears); post-trip with a bad odometer (clear message, fix, resend); offline post-trip then reconnect; kill the app mid-sheet and reopen; sign out and back in. Needs the emulator harness fixed (provisioner fix merged; nightly refresh pending).
4. **Measure:** crew clock-outs ended by the 12-hour auto clock-out (20 and 23 Nov were) should be zero; taps from Clock out to done at most 3; zero junk declarations.

## Acceptance criteria
- Only one place computes trip state; the Android trip/clock code is smaller than today's (target: under ~800 lines in `trip/` and no trip logic in `DayViewModel` beyond one call).
- No background polling loops for trip state.
- All eight scenario steps pass on the emulator.
- AAA checks pass on the three screens (contrast, 48/56 dp targets, text scaling to 200%).
- Zero auto clock-outs for a driver who did the post-trip in the following two weeks.
