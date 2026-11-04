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

## 2026-10-12 — Fable review
Rules now enforced in code:
- Ready-brief items carry `route` (the card that holds the item; house → Charlie); the iPhone opens
  that card, not the owner named on the label (`HeadBriefReadyService::routeOf()`).
- The iPhone keeps the server's order; NEW is a mark, not a re-sort (closes the 2026-10-11 trap).
- collections_first lateness counts the billed company's billing/primary contact and the client's
  contact too; each fact query logs its own failure.
- team-mobile `act`: only snooze drops the ready copy — opening an item does not.
- `DeadlineService::brief()` writes nothing; the charlie_morning_brief cron runs `sync()`.
- Urgent alert emails that fail retry at most hourly, 3 tries (count + time in `charlie_alerts.error`).
- charlie-inbox.js "Do all" runs each item's `choice: apply` action or stops for Tim.

## 2026-11-03 — 58 of 200 lines urgent, and a status that said RED while the one thing was a yes
Live: Kaname receives 200 lines from 8 heads; 58 (29%) are priority 1 (Sam 25 of 31, Yui 9, Otto 8, Penny 7, Ada 5). A head's own priority is a claim, not a fact:
`UrgencyGuard` ranks past the head's top 5 as priority 2 (the plugin's rule: about 5 needs-you is right, twenty means the threshold is wrong) and the card says which head.
Do not demote what is STORED (`charlie_items`, learning) — only what is ranked. Also: the status (`BriefStatus`) reads the ORIGINAL priorities, or a head's real red
would be guarded away. Open question for Tim: his own rule (2026-10-11) says penalty dates first, but the ranking is learned from what he opens, so an overdue statutory
deadline can sit sixth under a quiet-client line; the status chip is where it shows today.
A dry run of a desk class with renamed inline copies must also preload every class the real one lazily requires, or the desk swallows the load error and shows an empty list.

### A date-only reminder is not evidence of non-payment (2026-11-03)
The payroll remittance reminder (monthly on the 15th) stayed open until someone pressed Done, so `BriefStatus` called it overdue and turned the whole brief RED, while Wave had remitted to CRA by debiting the bank on pay day.
Rule: a reminder for something a system pays on Tim's behalf must be checked against that system's own record before it alarms. Order: ask for the report (Penny's payroll import) → close on a match (`PayrollRemitMatch`: the report's CRA remittance equals Wave's bank debits, checked by `PayrollRemitCheck`, closed by the cron through `DeadlineService::autoClose()`) → alarm only when the report is in, the bank lines reach the due date and nothing matches.
A request line ("upload the report") must NOT use a `charlie:deadline:` key: `BriefStatus` forces red on any overdue key of that form. Still date-only and so still able to force red on no evidence: WorkSafeBC (how it is paid is unknown, Tim to say) and the other calendar reminders.
