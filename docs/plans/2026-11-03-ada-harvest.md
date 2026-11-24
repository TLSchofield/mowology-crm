# Ada harvest — what the operations and finance plugins add to System Health (2026-11-03)

Status: **APPROVED ("Go") and BUILT 2026-11-03** — see "Build record" at the end. Plan as approved: Survey of `operations/skills/{runbook, risk-assessment,
compliance-tracking, status-report, process-doc}` and finance `audit-support` + `sox-testing`
(Sonnet, numbers only). Diffed against what Ada runs on production today (live copies pulled into the
branch first — commit e708f4ece: Pulse live checks, server-crash watch, Mark fixed, which the branch
never had).

## What the survey found

Five of the seven skills hold **no numbers at all** (runbook, process-doc, compliance-tracking,
status-report are templates; the only numeric scale in operations is risk-assessment's 3×3 matrix).
The strong source is `audit-support`: the evidence standard, the remediation record, the ITGC
checklist and the "a backup is not a control until it has been restored" rule. Ada already does the
mechanics (thresholds, one line per problem, hand-offs, a new red emailed once); what she lacks is
**memory and judgement over time**: no "for 17 days" on anything but the nightly reds, no trend, no
way for Tim to *accept* a known risk so it stops being noise, no periodic access review, no proof a
backup can be read back.

## What the live data says (read-only, `prod-run.sh`, 2026-11-03 14:05)

| Source | Today |
|---|---|
| Nightly posture | 2 red: `db-privileges` (since **Oct 13**), `drift` "99 files changed since deployed" (since **Oct 17**); 4 amber: 116 migrations "never run", 1 composer advisory (symfony/http-foundation), Stripe webhook missing the two dispute events, 1 stray `.json.gz` |
| The drift red | **a false alarm of our own making**: `deploy-file.sh` / `deploy-step.sh` never write the deploy manifest; only `deploy-checked.sh` does. Every file deployed since Oct 17 reads as "changed on the server" |
| Pulse (5 min) | last run 14:05; `queues` warn 288× in 24 h, `head_cards` warn 93× / fail 14×, `php_fatals` warn 6× — live, noisy, not mine to change here |
| Cron runs 30 d | 54 keys; `trackimo_poll` 91 errors of 556, `sync_ledger` 5 errors, `seasonal_triggers` 2 of 2 errors |
| Backups | db / files / composer heartbeats daily (02:30); **no restore or read-back has ever been recorded** |
| Privileged logins | 1 admin (Tim), 3 active managers (last logins Oct 19–25), 1 inactive manager; never reviewed as a list |
| activity_log 30 d | 1,875 rows; 586 with user_id 0 and 301 NULL (system actors) |
| Charlie urgent | `charlie_alerts.alert_key` dedups — one email per key, already right |

## The pieces (build order; each a pure class + test, wired into the existing desk)

### A. Truthful drift, and age on every line — *fixes a live red and a rule Ada already breaks*
- `scripts/deploy-step.sh` (and `deploy-file.sh`) call `scripts/lib/deploy_manifest.py` after a
  successful upload, as `deploy-checked.sh` does. Then one manual `security_posture` run (Database
  Manager → Run, by Tim or me in Chrome) shows real drift, not 99 ghosts.
- `AdaRules::briefItems()` sets `since` from `red_since` (posture rows) or the first bad run (cron
  rows: oldest error in `recent`) instead of today; evidence lines gain " — for N days" when N ≥ 2.
  Operating rule already says "age is shown instead" — the code never did it.

### B. Cost-of-waiting order — *operating-rules.md, never implemented*
- `AdaRules::costRank(system, group)`: money in/out (stripe, autopay, yardi, interac, invoices,
  payment-mail, db-privileges, backups, audit) → 0; crew in the field (tracking, push, app, trackimo,
  pulse pages) → 1; everything else → 2. `sortChecks()` orders state, then cost rank, then name. Brief
  (5 lines max) and page follow automatically.

### C. Accepted risks — *risk-assessment: Open / Mitigated / Accepted / Closed, 3×3 level*
- New migration `1431_ada_accepted_risks.sql`: system_key, reason, accepted_by, accepted_at,
  review_by (default +90 d), released_at. Button "Accept for 90 days" beside the hand-off on System
  Health (admin, CSRF, same pattern as Mark fixed), with a one-line reason required.
- `AdaRiskRegister` (pure): `level(state, system)` = 3×3 of likelihood (red = High, amber = Medium,
  unknown = Low) × impact (cost rank 0 = High, 1 = Medium, 2 = Low) → Critical / High / Medium / Low,
  exactly the plugin matrix. `apply(checks, acceptances, now)`: an accepted check keeps its colour on
  the page but moves to an "Accepted" section with the reason and review date, leaves the counts,
  the headline, Charlie's brief and `urgentAlerts()`; past `review_by` it comes back as before with
  "accepted on … by …, review was due …". A **Critical** check cannot be accepted (the plugin's
  "pervasive control" rule: auth, backups, audit chain, DB rights stay in front of Tim) — the button
  is not offered. The two live reds today: `drift` becomes acceptable once A makes it honest;
  `db-privileges` is Critical and stays.

### D. Trend and "decisions needed" — *status-report: what changed since last time, 3 lines first*
- The nightly `security_posture` cron, after `store()`, appends today's counts
  (red/amber/unknown/green, reds by key) to ops_settings `ada_daily_counts` (JSON, 14 days).
- `AdaRules::headline(counts, yesterday)`: "2 broken (one fewer than yesterday), 4 to look at."
  Page head gains two lines: **For you: N** (hand-offs addressed to Tim) · **For a developer: N**;
  and "Next three" = the top three after sort B. Card headline uses the same function.

### E. Access review every 90 days — *audit-support ITGC: periodic access recertification*
- `AdaRules::accessReviewCheck(privilegedUsers, lastReview, now)`: amber `security:access-review`
  when never reviewed, older than 90 days, or the roster hash (ids + roles + active) differs from the
  reviewed one ("a manager was added since you last looked"); evidence lists each admin/manager with
  last login. Button "Reviewed" stores `{at, by, hash}` in ops_settings `ada_access_review`.
  Green row otherwise, with "reviewed Nov 3 (4 logins)". Today it would be amber: never reviewed.

### F. Backup read-back — *audit-support: backups with tested restores*
- `scripts/backup-nightly.sh`: after the db dump, `gzip -t` it, count `CREATE TABLE` statements, and
  post a `verify` heartbeat (`ok`, `advisories` reused as the table count, detail = file). Honest
  name: **read-back**, not a restore (no MySQL on the iMac to restore into; say so).
- `SecurityPostureRules::backupVerifyCheck(lastVerify, liveTableCount, now)`: amber when no verify
  heartbeat in 36 h, red when the dump's table count is below the live database's (`information_schema`),
  green "last night's dump reads back: 212 tables, matches the database". `HEARTBEAT_KINDS` gains
  `verify`; the endpoint already validates kinds.

### G. Evidence grade — *audit-support: undated or unattributed evidence is insufficient*
- `AdaRules::evidenceDated(evidence, lastWorked)`: true when the line carries a time ("ago", "since",
  a date) or `last_worked` is set. One info row under "Ada": "N checks report without a date — treat
  them as unproven" listing them. Unit test asserts every rule in `AdaRulesTest` fixtures passes.
  Small; last; dropped if it turns out every line already dates itself.

## Not built, and why
- **Sampling tables, workpapers, RACI, SOC 2 / ISO table, status-report prose** — Ada checks
  populations, not samples; no auditor reads her page.
- **Severity levels / time-to-acknowledge / escalation ladders** — the plugins hold none; Pulse's
  "two bad runs in a row" and Charlie's one-email-per-key already are the ladder.
- **Pulse noise** (`queues` warn 288×, `head_cards` fail 14×) — live code from another session;
  recorded for its owner, not touched here.
- **Per-tenant rows** — still waits for a tenant model.

## Dry run before anything ships (the two lessons)
- A: count what the manifest would list vs the 99 "changed" — expect the red to drop to the files
  changed by hand on the server, if any.
- C: the 3×3 over today's 17 posture + desk rows — expect exactly one Critical (`db-privileges`).
- E: the roster today (5 users) and its hash; confirm "never reviewed" wording.
- F: run the read-back on the newest local dump on the iMac; table count vs `information_schema`
  on production (read-only).

## Verification
Unit tests per rule (`tests/Unit/Technology/AdaHarvestRulesTest.php`), Codex review of
`app/Modules/Technology/Services` + the page + scripts, deploy one file per `deploy-step.sh`
(dependency first: migration file, rules, services, page), run migration 1431 (Tim, Migrations tab),
OPcache reset, System Health checked in Chrome, Charlie's brief line checked, posture cron run once.

## For Tim after this ships (goes to Tim-To-Do)
- Run migration 1431; press "Reviewed" on the access list once you have read it.
- Stripe dashboard → webhook: add `charge.dispute.created` and `charge.dispute.closed`.
- `composer update symfony/http-foundation` (CVE-2026-108082) — a developer session, your say-so.
- "116 migrations never run": most are files renamed or applied by hand before `migrations_log`
  existed; a developer session should mark them so the amber means something.

## Build record (2026-11-03)
- Commit 419dfa6cf on `feature/language`: `AdaRiskRegister`, `AdaAcceptedRisks`, migration 1431, AdaRules
  additions (costRank, badSince/withAge, headline with yesterday, trendAppend/trendYesterday, handoffCounts,
  accessReviewCheck/rosterHash, evidenceDated/evidenceCheck), `SecurityPostureRules::backupVerifyCheck`,
  desk + cron + page wiring, CSS, `scripts/deploy-step.sh` + `deploy-file.sh` manifest call,
  `scripts/backup-nightly.sh` read-back; test `tests/Unit/Technology/AdaHarvestRulesTest.php` (17 tests).
- Dry run (live, read-only, inline renamed classes): 8 red, 11 amber, 3 info / 95 rows in 520 ms. Levels:
  Critical 2 (`db-privileges`, `drift`), High 4 (push_drain, trackimo_poll missed; stripe-webhook; unused logins),
  Medium 6, Low 7. Hand-offs: 14 for Tim, 5 for a developer. Brief: money first (db rights "for 21 days", drift,
  then push, trackimo, Search Console token). Access review: never reviewed, 4 logins — TIM (admin),
  MIGHT-E TRUCK and DODGE RAM (manager, truck tablets), NIGEL (manager). Live tables 496; no read-back yet.
- Changes from the dry run: truck tablets labelled in the access list; nightly rows count as dated by
  `checked_at`; "(checked 2:20 pm)" and "no run on record, ever" count as a time. The evidence row then
  names nothing on today's data and stays hidden, as the plan allowed.
- G kept (it costs nothing and will speak when a new check forgets its date).
- **Codex** (1 review, 6 findings): 4 real and fixed (acceptance outliving a turn to Critical; accepted risks still
  reaching Charlie's urgent email; a missed cron's age counted from its last run instead of the missed line; a
  non-atomic replace), 1 cheap and done (sort tie-breaker), 1 already fixed after the dry run (checked_at/red_since).
- **Live (2026-11-03 14:35, Chrome + prod-run):** System Health shows levels (Critical 2, High 5, Medium 6, Low 8),
  "— for 21 days" / "— for 17 days", "For you: 15 · For a developer session: 6 · Next: …", the access-review row with
  its Reviewed button, Critical rows marked unacceptable; Accept buttons hidden until migration 1431 runs. The posture
  cron ran twice by hand: `posture:backup-verified` green ("496 tables, matches the database") after the first
  read-back heartbeat posted from the iMac; drift fell from 99 to **88** once the 21 stale manifest entries were
  refreshed — the 88 are real differences between production and `feature/language` (backlog). Trend snapshot
  written (`ada_daily_counts`: 8 red, 13 amber, 73 green). Ada's evidence row named one undated line ("90+ days"),
  fixed and redeployed; the row is now hidden. Manifest recorded on every deploy today (`deploy-step.sh`).
- **Not done by me:** migration 1431 (Tim runs migrations); the iMac backup agent still points at an old worktree, so
  the nightly read-back waits for Tim to re-point it (Tim-To-Do).
