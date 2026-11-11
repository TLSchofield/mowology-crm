# Ada — head of Technology: build plan (2026-10-11)

Agreed with Tim 2026-10-11: build Ada; security is her desk too (not a separate head).
Skill: `.claude/skills/ada-technology/`. Rules: read-only, rules not AI, evidence not alarms,
never fixes — hands a task to a developer session or to Tim.

## What production already shows (prod-run preview, 2026-10-11 14:53, rolled back)

- `sync_ledger` failed 3 nights running (Oct 7, 8, 9: "1615 Prepared statement needs to be
  re-prepared"); recovered Oct 10. Nobody was told.
- Last `cron_runs` row months ago: `automation_runner` 2026-05-17, `contract_billing` 2026-06-01,
  `invoice_overdue` 2026-06-12, `social_metrics_sync` 2026-07-13, `stop_orphaned_timers`
  2026-09-24 (`stop_orphaned_job_timers` runs daily — likely a rename). Stopped running OR
  stopped reporting — not yet known which.
- `push_queue`: 37 failed, 181 sent in 14 days (last failure Oct 7).
- `icloud_inbox_poll` took 601 s, 430 s, 248 s on Oct 8.
- `penny_prepare` max 112 s; `mia_media_tags` max 181 s.
- Users: 2 active accounts (role user) with no login in 90+ days.
- Stripe: 45 payments in 30 days, last today. GSC sync: success nightly.

## Unknowns and how each is settled (cheapest first)

| # | Unknown | Settled by |
|---|---|---|
| 1 | Live cron registry (prod `database_appstack.php` may differ from repo) | `scripts/prod-file.sh` download, diff |
| 2 | Which crons don't call `recordCronRun()` on PROD | prod-file the cron scripts named in the registry, grep |
| 3 | Why the 5 crons went quiet: stopped or stopped reporting | prod-file each script + its expected side effect table (e.g. contract invoices created after June 1) |
| 4 | Real column names: `vehicle_location_pings`, `social_accounts`, `head_brief_ready`, `push_queue` | prod-run `SHOW COLUMNS` |
| 5 | `cron_runs` index on (cron_key, ran_at) — is the brief query cheap? | prod-run `SHOW INDEX` + timing the query |
| 6 | Interval from a cron `expr` | pure function, unit tested on every registry expr |
| 7 | DEPLOYMENT.md FTP password claim | `git log -p` / grep on origin branches; report location only |
| 8 | Where 37 push failures came from | prod-run on `push_queue` real columns |

## Steps

1. **AdaHealthService** (`app/Modules/Technology/Services/`) + **AdaRules** (pure):
   - (1) scheduled jobs: failing, missing (2× interval), never reports, slow (>80% of interval),
     unknown key; green/amber/red + "last worked" + one evidence line + hand-off text.
   - (2) connections: Meta (reads `SocialAccountHealth` result), GSC, Apple push, Stripe proxy,
     Trackimo, mailboxes (configured + last poll summary), server Maps geocode.
   - (3) security, first slice: active logins unused 90+ days, admins, misplaced-secret note.
   - `brief()` — Charlie's contract, one query per source, capped; `ready()`.
   Tests: AdaRulesTest (thresholds, expr intervals, evidence lines), service test on fixtures.
2. **Register her as a head** everywhere the others are: CharlieBriefService HEADS + desks,
   CharlieRankService HEADS, TeamCardService HEADS/headKey/brain, action-board.js,
   charlie-inbox.js, PushHeads, dept-heads-deck team list, sidebar header (Database/System).
3. **Card** `public/crm/includes/ada-card.php` (+ CSS in mowology-brand.css): face, status pill,
   red/amber counts, top problems. Admin only.
4. **Health page** `public/crm/ada_appstack.php`: one row per system, green/amber/red,
   last worked, evidence, "Copy task" button. Links to Database Manager / System Log.
5. **Verify**: render locally (stub DB) → deploy with deploy-merged.py → prod page renders to
   `</html>` → Charlie's ready brief contains Ada's lines (prod-run on the ready copy) →
   iPhone sees her via the ready brief (no app build needed).
6. **Skill**: move built checks PLANNED → BUILT, add paths to `covers:`, traps from step 3.

No migration needed for phase 1 (everything read from existing tables; dismissals use Charlie's).
Later: apps (versions offered vs run), deploy drift, migrations never run, per-tenant health.
