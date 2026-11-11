# iPhone offline day — part 2: the review's findings (2026-10-12)

An independent review (Fable) of the offline clock/timer path found 12 issues; 8 were confirmed
against the code, one of them introduced this morning (#3). Checking them turned up a bigger gap:
**a job can't be run offline on iPhone** — Start shows "Network error", the visit stays
"scheduled", no timer runs, so it can't be completed (`VisitDetailViewModel.startJob` catch,
`VisitDetailView.swift:498/590` gate on `in_progress`).

## Unknowns and how each is settled

| # | Unknown | Settled by |
|---|---|---|
| 1 | Storing extras for a queued completion without a SwiftData schema change (a failed migration falls back to an in-memory store = the whole queue lost) | Don't touch the model: UserDefaults keyed by visit, the pattern the skip reason already uses (`skipReasonKey`) |
| 2 | Adding the owner to queued punches | `ClockQueue.PendingAction` is Codable JSON; a new optional `userId` decodes as nil for items already queued (treated as the current user) |
| 3 | Which server errors are permanent for a queued punch | Read: "already clocked in" / "not clocked in" answer `success:true` (not errors). Real errors: GPS required (`clockIn` line 75, `startJobTimer` `require_gps_for_job_start` default 1), 500s, expired login. Queued actions (they carry `client_time`) skip the GPS requirement on the server, with a note; others are kept and retried, dropped after 5 failed tries |
| 4 | What to do with a punch older than 24 h | Today: phone deletes it (my rule) or server would stamp the replay time — both wrong. Change: record at the **phone time**, flagged "older than 24 h — check" in the entry's note, up to 7 days; older than 7 days → not recorded, logged to activity_log for the office. **Decision for Tim** (it relaxes this morning's rule) |
| 5 | Duplicate punches | `clock.php` ignores `Idempotency-Key`; add the same `idempotencyCheck/Store` `timer.php` uses (`app/Core/IdempotencyHelper.php`) |
| 6 | Sign-out with unsent actions | Clock out at sign-out already needs signal. "Sign out only" (`MainTabView.swift:201`) will warn when anything is unsent; items carry the owner so another person's login never sends them |
| 7 | Can't run the airplane-mode test on this Mac (no simulator) | Compile-check here; server parts proven by unit tests + prod preview; the device checklist below runs on TestFlight |

## Steps

**Server (deploy first — everything stays compatible with today's apps)**
1. `OfflineTime`: older than 24 h (≤ 7 days) → phone time + flag "older than 24 h"; > 7 days → not trusted. Unit tests.
2. `clock.php`: honour `Idempotency-Key`; a queued punch (has `client_time`) skips the GPS requirement, noting "no location (offline)"; a too-old punch is logged to activity_log.
3. `timer.php` / `startJobTimer`: same GPS rule for a queued start.
   *Proof:* phpunit; `prod-run.sh` preview of a duplicate clock-in with one key → one entry.

**iPhone (1.3.27)**
4. One ordered resend (`AppTransitionDrainService`): clock queue first, then job actions oldest-first, one at a time; one shared run, so a second caller waits for it instead of starting another. The visit screen's own resend (`drainPendingTransitions`) waits on it instead of re-sending with "now" (#3).
5. `ClockQueue`: owner on each item; never deletes on an expired login (stops and keeps everything) or a server error (keeps, retries, gives up after 5); no 24 h delete.
6. Offline job on screen: Start and Complete work with no signal — the visit shows "In progress" / "Completed · will sync", the timer runs; extras for a queued completion are saved and sent (#4).
7. "Sign out only" warns when actions are unsent.
   *Proof:* compile-check; then TestFlight device checklist — airplane mode: clock in, start a job, add 20 min extras, complete it, clock out, close the app, reconnect, open → one shift and one job at the tapped times, extras present, notes "sent late".

**After:** Otto trap + vault entry; re-run the Fable review on the changed files.
