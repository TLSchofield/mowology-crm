# Ada's checks — (1), (2), the security checks and the nightly security posture (7) BUILT; the rest PLANNED

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
  (`AdaRules::PROXIES`: trackimo_poll → truck positions, push_drain → Apple push; only a proxy whose verdict is `ok` promotes — a `quiet` one proves nothing);
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
| Apple push (APNs) | failed / expired share; stale tokens; **red when any `pending` row is scheduled more than 30 min ago** (`AdaRules::PUSH_STUCK_MINUTES`, audit 2026-10-12 #23) | `push_queue.status`, `device_tokens.last_seen_at`; failures also go to the PHP error log from `app/Services/Push/ApnsService.php` | error log not readable as a table |
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
| Failed-login spikes — BUILT 2026-10-12 (`AdaSecurityRules::loginFailuresCheck()`, read by `AdaSecurityWatch`) | `login_attempts` per clock hour, last 24 h; any hour over 50 is red and urgent (Charlie emails once per hour, key `urgent:security:logins:<hour>`). A successful login deletes its own email+IP rows, so counts are a floor |
| Role / password / account / migration / log-clear changes — BUILT 2026-10-12 (`AdaSecurityRules::sensitiveChangesCheck()`) | `activity_log` rows of the last 24 h matching `AdaSecurityRules::ACTION_LIKES` ('Updated roles…', 'Changed employee #N account' with a role/password line, 'Changed password…', 'Toggled active status…', 'Created employee…', 'Migration run', 'system_log_cleared'); amber with who/when, red when the system log was cleared; a role change or a cleared log is urgent through `CharlieUrgentService::find()` |
| Audit copy outside the database — BUILT 2026-10-12 (`AuditExportService`, cron `audit_export`) | daily append of activity_log to `/home/mowology/audit/activity_log.jsonl`, hash-chained; check its `cron_runs` like any job ('warning' = it fell back to inside the web root). Chain re-verified nightly by check (7)#7 |
| Admin-only endpoints still guarded | cron web shims use a shared `_guard.php` (Tech-Debt-Map, 2026-06-18); recheck unverified |

## (7) Nightly security posture — BUILT 2026-10-13

Cron `security_posture` (daily 03:40, `app/Modules/Technology/Cron/security_posture.php`, shim
`public/crm/cron/security_posture.php` behind `_guard.php`). Facts gathered by
`SecurityPostureService::run()`, decisions in `SecurityPostureRules` (tested in
`tests/Unit/Technology/SecurityPostureRulesTest.php`), stored one row per check in table
security_posture_checks (migration `database/migrations/1347_security_posture.sql`) and read back
cheaply by `SecurityPostureService::deskChecks()` into the desk's "Security" group (with the
failed-login, sensitive-change, unused-login and JWT-key checks). A row "Nightly security checks"
turns amber when the run is missing or older than 36 h. **Alerts:** each NEW red goes to Tim once
through `CharlieUrgentService::find()` → `SecurityPostureService::urgent()`, keyed
`urgent:posture:<check>:<time it turned red>` — a red that stays red is not re-sent; a red that
clears and returns is new. Ada only checks: nothing here changes a setting, credential, cron or Stripe.

| # | Check (system) | Green means | Amber / red | Source |
|---|---|---|---|---|
| 1 | posture:blocked-paths | every URL in `SecurityPostureRules::BLOCKED_PATHS` answers 401/403/404/410 over HTTPS from the server itself | red on any 2xx (lists the URLs); amber on 3xx/5xx | curl to https://mowology.ca |
| 2 | posture:stray-files | no csv/zip/gz/tgz/tar/xlsx/sql/bak/dump under the web root outside uploads/, _media/, vendor/ and database/migrations/ | amber, up to 20 paths | file walk of PUBLIC_ROOT (also finds `error_log` files for #14) |
| 3 | posture:db-privileges | `SHOW GRANTS FOR CURRENT_USER()` gives data privileges only | red while ALL PRIVILEGES / CREATE / DROP / ALTER: "app login can change tables — finish step 3 (data-only user)" | MySQL |
| 4 | posture:stripe-webhook | the endpoint for /api/stripe/webhook is enabled and sends charge.dispute.created, charge.dispute.closed, charge.refunded, payment_intent.succeeded, payment_intent.payment_failed (or `*`) | amber naming missing events or no endpoint; red when disabled; "can't tell" with no key | read-only GET /v1/webhook_endpoints with STRIPE_SECRET_KEY |
| 5 | posture:payment-mail | in the last 7 days no source has held mail while none verified | red when Yardi or Interac has held > 0 and verified = 0 ("real Yardi/Interac mail may be failing the signature check") | etransfer_notifications.sender_verified (Yardi auto-recorded rows count as verified; Fwd: notices are not "held") |
| 6a | posture:backup-db | an ok db heartbeat in the last 36 h | red | backup_heartbeats, posted by `scripts/backup-nightly.sh` via `scripts/lib/heartbeat.sh` to `public/crm/api/backup-heartbeat.php` |
| 6b | posture:backup-files | an ok files heartbeat in the last 36 h | amber | same |
| 7 | posture:audit-copy | activity_log.jsonl (home audit dir, AUDIT_EXPORT_DIR or STORAGE_ROOT/audit) written within 36 h and its last 500 lines' hash chain verifies (`SecurityPostureRules::verifyAuditTail()`) | red when missing, stale or broken | the file |
| 8 | posture:certificate | mowology.ca:443 certificate has 21+ days left | amber < 21 days, red < 7 | stream_socket_client capture_peer_cert |
| 9 | posture:dependencies | a composer heartbeat within 14 days with 0 advisories | amber on advisories > 0, an old report or none | `scripts/composer-audit-report.sh` (Mac; root + public/) |
| 10 | security:login-failures, security:sensitive-changes | see (6) above | see (6) | `AdaSecurityWatch` — same Security group |
| 11 | posture:drift | every file in app/Storage/deploy-manifest.json still has the sha1 it was deployed with | amber with the list; red when a changed file is under app/Core, app/Modules/Accounting or public/api; "can't tell" before the first manifest | manifest written by `scripts/lib/deploy_manifest.py`, called by `scripts/deploy-checked.sh` (and so by `scripts/deploy-merged.py`) |
| 12 | posture:page-render | /, /services, /about, /contact, /privacy-request, /loginAuth/login.php, /forgot-password.php → 200 and end with </html>; a bad-token customer invoice → non-5xx friendly page ending </html> | red on a wrong status or a cut-off page | curl, full body |
| 13 | posture:migrations | every numbered NNN_*.sql (server folder + the manifest's list) has a success row in migrations_log | amber with the numbers (done-/problem-/todo- files ignored, like the migrations manager) | migrations_log |
| 14 | posture:php-errors | ≤ 50 error lines (`SecurityPostureRules::isErrorMessage()`: PHP severities, Uncaught, SQLSTATE, error/exception/failed — not progress notes) in 24 h across ini_get('error_log'), ~/error_log and every error_log file in the web root | amber > 50, red > 500; top 5 messages deduped and redacted (`SecurityPostureRules::redact()`) | last 16 MB of each log ("at least" when the window runs past it) |
| 15 | posture:mail-dns | SPF allows the SMTP host's IP (ip4/a/mx/include evaluated), a DMARC record, a DKIM key at default._domainkey (or DKIM_SELECTOR) | amber when any is missing | dns_get_record |

## Per tenant (Ittai) — later

No tenant model exists in code (no `tenant_id` anywhere, grep 2026-10-11). Per-tenant health
waits for it; design each check so "which tenant" is a column, not a rewrite.
