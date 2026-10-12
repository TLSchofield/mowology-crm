# Ada's checks — (1), (2) and the logins check BUILT 2026-10-11; the rest PLANNED

Every check below is PLANNED. The data source named for each is EXISTING (verified 2026-10-11
in this repo unless marked). Production can differ from the repo — confirm with
`scripts/prod-file.sh` before building on a file.

Each check produces: system, state (green / amber / red), "last worked" date, one evidence line,
and — when not green — a hand-off (see `operating-rules.md`).

## (1) Scheduled jobs — BUILT 2026-10-11

Built in `AdaRules::cronCheck()` / `AdaDeskService` exactly as the table below, with these
decisions settled on production data (2026-10-11):
- the registry is read as TEXT from the live page (`AdaRules::parseRegistry()`), never executed;
- interval comes from `expr` only — `ran_at` is ~3 h behind the cPanel clock, so time of day is unusable;
- missed = no run for 2 intervals + 10 min; recovered = failed within 3 days, now working (amber);
- "does it record" = the script text calls `recordCronRun(` or names table `cron_runs`
  (`cron_runs_log` does not count). Text without it but with runs on record still reports
  through a shim — runs on record win;
- a job that keeps no record but whose work shows elsewhere is green by proxy
  (`AdaRules::PROXIES`: trackimo_poll → truck positions, push_drain → Apple push);
- never ran + records → amber "probably not in the cPanel schedule". Weak for scripts that exit
  before recording when idle (reconsent_sender does) — the wording says so.


Sources: table `cron_runs` (written only by `recordCronRun()` in `app/Services/CrmFunctions.php`)
and the registry `$crons` in `public/crm/database_appstack.php` (key, expr, app path).

| Check | Rule (first draft — tune on real data) | Source |
|---|---|---|
| Failing | last run status `error`, or 3+ `warning` in a row | `cron_runs.status`, `error_message` |
| Missing | registry key with no run in 2× its interval (from `expr`) | registry `expr` vs max(`ran_at`) |
| Never reports | registry key whose script never calls `recordCronRun()` — can't be judged, say so | grep of the `app` path |
| Suspiciously quiet | summary says zero found / scanned for N runs while another source shows work exists | `cron_runs.summary` + the feature's own table |
| Near its time limit | `duration_ms` above ~80% of the interval or the host's limit | `cron_runs.duration_ms` |
| Wrong key | script records under a key the registry does not list | `cron_runs.cron_key` vs registry keys |

Known gaps in the source (grep 2026-10-11): these cron files do not call `recordCronRun()`, so
they will read as "never run" whatever their health — `contract_billing`, `receipt_archive`,
`seasonal_outlook_refresh`, `automation_runner`, `campaign_sender`, `invoice_overdue`,
`invoice_reminders`, `optin_resend_sender`, `seasonal_triggers`, `quote_request_reminder`,
`trackimo_poll`, `push-drain`; `data_retention` writes a different table (`cron_runs_log`).
Prod copies may differ (unverified). For these, Ada reports "cannot tell" and hands a developer
the one-line fix; she does not guess from symptoms.

"Suspiciously quiet" pairs to build first (each pair verified to exist as tables / summaries):
- `yardi_eft_inbox_poll` summary "Scanned 0 email(s)" vs remittances sitting in office@ — the
  second source needs a read of the mailbox count; method unverified (see traps).
- `tracking_silent_alert` vs `device_tracking_health.last_point_at` for clocked-in users.
- `trackimo_poll` (no run record) → use age of newest `vehicle_location_pings` row instead.
- `charlie_morning_brief` → age of the `head_brief_ready` row (older than
  `HeadBriefReadyService::MAX_AGE_SECONDS` = 3600 s means the rebuild is failing).

## (2) Outside connections — BUILT 2026-10-11 (Google Maps and mailbox logins still PLANNED)

| Connection | Signal Ada reads | Source (existing) | Gap |
|---|---|---|---|
| Meta (Facebook / Instagram) | health per account (checked hourly) | `SocialAccountHealth::sweep()` → `social_accounts.meta_json['health']`, `token_expires_at` | none — read it, don't redo it |
| Google Business Profile | posts / reviews flowing | `gbp_reviews`; GBP API access not approved (memory, unverified now) | expected amber until approved |
| Google Search Console | last successful sync | `gsc_sync_history`, `gsc_properties` | |
| Google Maps (server side) | server geocode refused | `ops_settings` description "Depot geocode failed <date>" written by `CloserService::depot()` | no general record of Maps failures |
| Apple push (APNs) | failed / expired share; stale tokens | `push_queue.status`, `device_tokens.last_seen_at`; failures also go to the PHP error log from `app/Services/Push/ApnsService.php` | error log not readable as a table |
| Mailboxes (IMAP) | configured? and did the last poll actually read mail? | `MailboxConfig::status()` (configured only) + poller summaries in `cron_runs` | login success not stored anywhere |
| Stripe | webhooks and payments still arriving | `public/api/stripe/webhook.php`, table `stripe_payments` | no key-health record; "last event age" is the proxy (unverified that age is meaningful in winter) |
| Trackimo | truck positions arriving | newest `vehicle_location_pings` row | poller records no run |

## (3) Apps — later

| Check | Source |
|---|---|
| Version each crew phone actually runs | `device_tracking_health.app_version`, `platform`, `last_seen_at` |
| Version we offer / minimum allowed | `public/crm/downloads/app-version.json` via `public/crm/api/app-version.php` |
| TestFlight builds, Xcode Cloud failures | `/appstore-cloud` (Mac-side; not reachable from the server) |
| iPhone crash reports | pymobiledevice3 off the paired phone (Mac-side, manual) |

Note (2026-10-11): repo `app-version.json` says Android 1.3.2 / iOS 1.0.0; memory says APK 1.3.4
is owed. The live file was not checked. Ada compares offered vs run, not repo vs memory.

## (4) Data soundness — later

| Check | Source |
|---|---|
| Income counted once | Penny's rules — `.claude/skills/penny-bc-bookkeeping/references/ledger.md`; Ada only flags, Penny owns |
| Bank / card statements arriving | Penny's statement-coverage service (see the Penny skill) |
| Skills still match code | `tests/Unit/Docs/HeadSkillsTest.php` result in the test run (replaced the Penny-only test on 2026-10-11) |
| Schema drift | `compareSnapshots()` on the daily snapshot (`app/Modules/Database/Cron/schema_snapshot.php`) |

## (5) Deploys — later

| Check | Source | Problem |
|---|---|---|
| Live files differ from repo | `scripts/prod-drift.sh`, `scripts/prod-file.sh` | Mac-side (FTP); the server cannot run them. A server-side file-hash list compared with a repo manifest is one option — undecided |
| OPcache serving old code | `public/crm/api/opcache-reset.php` resets only | detecting staleness (cached timestamp vs file mtime) is unverified on this host |
| Migrations shipped, never run | `database/migrations/*.sql` on the server vs `migrations_log` (same comparison `app/Modules/Database/Api/migrations-manager.php` makes) | |

## (6) Security — later

| Check | Source |
|---|---|
| Secrets outside `secrets.php` | file scan of the repo for credential-shaped strings (planned); report location only |
| `DEPLOYMENT.md` FTP password | SETTLED 2026-10-11: no password in the file — every `lftp -u` line reads `git config git-ftp.password`, on HEAD, origin/main and origin/feature/language; one commit in history. The 2026-10-03 note was wrong (corrected in _Directions.md) |
| Unused logins — BUILT (`AdaRules::loginsCheck()`) | `users.last_login` (set in `app/Core/Auth/auth.php` and `public/api/auth/token.php`) AND phone seen in `device_tracking_health.last_seen_at` — an app login can stay signed in for months, so login alone is not evidence; truck tablets excluded |
| App sign-in key — BUILT 2026-10-12 (`AdaRules::jwtSecretCheck()`) | `BLUEMOON_JWT_SECRET` defined and ≥ 32 characters — length only, the value never leaves secrets.php; red when missing/short (no fallback key since 2026-10-12, so nobody can sign in on the iPhone) |
| Admin-only endpoints still guarded | cron web shims use a shared `_guard.php` (Tech-Debt-Map, 2026-06-18); recheck unverified |

## Per tenant (Ittai) — later

No tenant model exists in code (no `tenant_id` anywhere, grep 2026-10-11). Per-tenant health
waits for it; design each check so "which tenant" is a column, not a rewrite.
