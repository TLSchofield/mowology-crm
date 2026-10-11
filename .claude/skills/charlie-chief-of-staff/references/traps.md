# Traps already hit — read before changing the brief or the opening screen

Add a new entry the same day a mistake is found: date, what happened, the signature, the rule.

## 2026-10-10 — iPhone opening screen never showed the brief
`MorningBriefLoader` cancelled the fetch at 2.5 s; Charlie's live build takes 7–11 s on production
(he asks every head; Otto alone had 104 items). Nothing arrived, so nothing was saved, so the
"saved brief" fallback was always empty — a silent skip, no error. Fixed: never cancel (1.3.18) and
the server-kept ready copy (`head_brief_ready`, migration 1319, 1.3.19).
**Rule:** time the server call on production before choosing a client timeout; a fallback filled
only by the call being timed out is no fallback.

## 2026-10-10 — Service works from the app, fails from the cron
`HeadBriefReadyService::buildCharlie()` used `TeamCardService`, which team-mobile loads and the
`charlie_morning_brief` cron does not → every cron rebuild logged "Class TeamCardService not
found" while the app path worked. Fixed by requiring it inside the service.
**Rule:** a service requires what it uses; after hooking one into a cron, read that cron's next
`cron_runs.summary`. The cron also has to load `app/Services/CrmFunctions.php` itself for
`recordCronRun()` and `getWorkQueueItems()` — the CLI bootstrap does not.

## 2026-10-10 — The live brief is slow, and the cron now pays for it every 15 minutes
`CharlieBriefService::collect()` asks every head one after another (~11 s of a 29 s cron budget);
the web Action Board still pays it on open. **Open.** Profile per-head time before optimising.
Also open: `otto_decide` / `otto_undo_auto` in team-mobile do not call `tmDropReady()`, so those
items stay on the opening screen until the next rebuild.
**Rule:** any new app action that removes a Charlie item calls `tmDropReady()`.

## 2026-10-11 — Ready copy and live reply drift apart
The ready copy is built by `HeadBriefReadyService::buildCharlie()`, a second copy of team-mobile's
owner reply. A field added only to team-mobile never reaches the opening screen.
**Rule:** change both in the same commit (Integration-Map "Charlie's ready brief").

## 2026-10-11 — The phone re-orders what the server ranked
The opening screen shows Charlie's column only, and `MorningBriefStore.build` re-sorts it by
"new" then priority, ignoring the server's score. "Why is X first on my phone?" is answered by that
function, not by `CharlieRankService`. **Open** (owner wants cost-of-waiting order, three lines).

## 2026-10-11 — Once-a-day rule hid the brief after a new build
Tim couldn't see the brief after installing a build because that day's had already played.
`MorningBriefStore.oncePerDay` set to false (1.3.23). **Rule:** don't turn it back on without
Tim's go-ahead.

## Building a brief writes
`CharlieForemanService::card()` is not read-only: it syncs `charlie_items`, freezes the day's
`charlie_briefs` snapshot and may queue a "which first?" question. It runs on every Action Board
open, every team-mobile owner brief and every cron run. **Rule:** never call it from a path that
must be side-effect free (tests, previews); heads' `brief()` must stay read-only.
