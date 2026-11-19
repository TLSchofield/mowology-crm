# iPhone app — a full day offline, recorded at the time it happened (2026-10-12)

Tim asked whether the iOS app meets the 24-hour offline standard. It doesn't, for two reasons
found in the code this session:

1. **An offline clock action can be discarded.** `TimeClockViewModel.loadStatus()` (lines 146-148)
   clears `ClockQueue` whenever the server answers. `TimeClockView` calls `loadStatus()` on appear,
   so opening the app after signal returns can throw away a queued clock-in/out before
   `drainAndReconcile()` runs. When it is sent, there is no `client_time`, so it is recorded at
   arrival time. (The server already honours `client_time` — `clock.php` → `OfflineTime::accept`.)
2. **Job timers record arrival time.** `startJobTimer()` / `stopJobTimer()` in
   `TimeclockFunctions.php` write `NOW()`; `timer.php` ignores any phone time. Android 1.4.0
   already sends `client_time` to `/schedule/timer` (`ArrivalPrompts.kt`, `DayRepository.kt`) and it
   is ignored too — so the server half fixes both phones.

Already fine: GPS fixes (`FixStore`, store-first, device time, 72 h), job photos (durable disk,
`queuedAt` sent), the day's schedule (cached 14 days).

## Unknowns and how each is settled

| # | Unknown | Settled by |
|---|---|---|
| 1 | Who else calls `startJobTimer`/`stopJobTimer` — would a new optional `$at` change them? | `graphify touches`; the new parameter goes last with default `null` → `COALESCE(?, NOW())`, the pattern clockIn/clockOut already use (live since today) |
| 2 | A stop tapped before its start, or a start tapped before clock-in | `OfflineTime::accept(..., notBefore)` — stop uses the timer's `start_time`; same rules as clock-out |
| 3 | Time zone of the stored time | Same path as clock-in: `OfflineTime` returns PHP local `Y-m-d H:i:s`; MySQL session zone is aligned to PHP (`TimeclockFunctions.php` note at line 171). Proven by today's clock change |
| 4 | Auto clock-in made by a queued job start | Use the same tapped time for the auto clock-in row |
| 5 | The visit's own completion time | Read `updateVisitStatus()` before changing; pass the time through if it stamps `NOW()` |
| 6 | iOS date format the server reads | `ISO8601DateFormatter` (`.withInternetDateTime`) gives `2026-10-12T14:30:05Z`; `strtotime` reads it. Covered by a PHP test using that exact string |
| 7 | Queued items made by the current build (no tap time stored) | `ClockQueue.PendingAction.queuedAt` and `PendingTransition.queuedAt` already hold the tap time — send those; nothing new to store |
| 8 | GPS capacity | 6,000 fixes ≈ 25 h at the server's 15 s policy, but only ~8 h if the policy ever drops to the 5 s floor. Raise to 12,000. Not Android's 60,000: iOS rewrites the whole JSON file every 3 s, and 60,000 fixes is ~9 MB per write |

## Steps (each proven before the next)

1. **Server — timer uses the tap time** (`TimeclockFunctions.php`, `timer.php`).
   `startJobTimer`/`stopJobTimer` take optional `$at`; `timer.php` passes
   `OfflineTime::accept($input['client_time'] ?? null, now, notBefore)` and returns `time_flag`.
   No `client_time` → exactly today's behaviour.
   *Proof:* unit test for the stop/start time rules; `scripts/prod-run.sh` preview of a start + stop
   with a `client_time` 40 min old on a real visit (rolled back), showing the stored times; then the
   3-way-merged deploy, OPcache reset, one live call without `client_time`.
2. **iPhone — never discard a queued clock action.** `loadStatus()` drains the queue first when
   there's anything pending, then reads the server. Every queued clock action and job start/stop/skip
   sends `client_time` from its `queuedAt`.
3. **iPhone — GPS capacity 12,000.**
4. **Build and prove on the Mac.** Local `xcodebuild` on the iMac. Airplane-mode run in the
   simulator: clock in, start a job, kill the app, reopen online. The server should show the tapped
   times with "sent late" flags. Then push `feature/language` for TestFlight.
5. **Penny/Otto notes:** the "sent late" flag already reaches the timesheet review; record the
   change in `otto-operations` and the Obsidian Known-Failure-Patterns entry (queue cleared by a
   status read).

Not in scope: `PingQueue` (old location pings, 8 h expiry). `FixStore` replaced it for the trail.
