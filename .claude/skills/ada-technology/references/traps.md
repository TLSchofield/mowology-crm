# Silent failures that justify Ada — read before building any check

Each entry: date, what happened, the signature, the check that would have caught it.
Add a new entry the same day one is found. Sources: "verified 2026-10-11" = checked in a session
that day (reported by the session that commissioned this skill); "memory" / "Obsidian" = taken
from that note, dated by the note.

## 2026-10-11 — "0 found" read as "broken" (Yardi remittance reader)
`yardi_eft_inbox_poll` (`app/Modules/Accounting/Cron/yardi_eft_inbox_poll.php`) ran 2,663 times,
every summary "Scanned 0 email(s)", while office@ held 5 Yardi remittances (May–Jul). It was called
broken; the likelier cause (from its code, not yet checked against the mailbox) is its window: exact
DoNotReply@yardi.com sender, 21-day look-back, nothing before 2026-08-05 — and it never opens info@
or iCloud (Dorset's remittances), and nothing reads SparcPay. **Rule for Ada:** before calling a
quiet poller broken, compare its search window and mailboxes with where the mail actually is; report
"coverage gap" or "broken" accordingly.

## 2026-10-08 → 2026-10-11 — Google Maps key refuses every server geocode (verified 2026-10-11)
The CRM's Maps key is restricted to browser referers, so server calls get "API keys with referer
restrictions cannot be used with this API". `CloserService::depot()` swallows it and writes
"Depot geocode failed <date>" into `ops_settings` once a day — broken since 2026-10-08, no alert.
**Check:** outside connections — any stored "failed <date>" marker; plus the general rule that a
browser key must not be used server-side (Tim's console, not code). Open.

## 2026-10-11 — Charlie's ready brief stopped rebuilding (verified 2026-10-11)
`charlie_morning_brief` failed to rebuild the ready copy with "Class TeamCardService not found"
(a missing `require_once`) until someone looked. **Check:** scheduled jobs — error status; and
age of the `head_brief_ready` row above `HeadBriefReadyService::MAX_AGE_SECONDS`.

## 2026-06-18 → 2026-09-25 — Facebook / Instagram publishing dead (memory
`project_social_meta_publishing_dead_keymig.md`; Obsidian Known-Failure-Patterns)
An encryption-key change left stored tokens undecryptable; the error read "no page token" and
the accounts kept a "Verified" badge. Three months of lost posts, found by accident.
`SocialAccountHealth` was built afterwards. Tech-Debt-Map (2026-09-26) says the Facebook Page
still needed reconnecting — current state unverified. **Check:** read
`social_accounts.meta_json['health']`; never trust the badge.

## Ongoing — production OPcache serves stale PHP (memory `feedback_production_opcache_stale.md`)
The host caches PHP with timestamp checks off (see `public/crm/api/opcache-reset.php`), so an
overwritten file keeps running its old code until a reset — once for hours. **Check:** deploys —
staleness detection is unverified; until then, every deploy hand-off ends with "reset OPcache".

## 2026-09-19 — Production ahead of the repo (memory `project_prod_ahead_of_repo_drift.md`;
Obsidian "Deploying any `app/` file → production may be AHEAD of the repo")
55 files existed only on production and ~27 had live code the repo lacked; deploying the repo
copy would have deleted live features. `public/` never fully audited. **Check:** deploys — drift
(Mac-side today: `scripts/prod-drift.sh`).

## 2026-09-25 — "Never run" was a lie (Obsidian Known-Failure-Patterns)
Crons that do not call `recordCronRun()` (or call it under a different key, e.g.
`stop_orphaned_timers` vs `stop_orphaned_job_timers`) show "Never run" while working — and
look identical to "not scheduled". `auto_clockout` was fine all along; the crontab told the
truth, the dashboard did not. Twelve cron files still lack the call (list in `checks.md`).
**Check:** scheduled jobs — "never reports" and "wrong key" are their own states.

## 2026-09-25 — Truck tablets were invisible to the silent-GPS sweep (Obsidian)
`TrackingHealthService::clockedInTrackedUsers()` excluded trucks, so a 21-hour blackout raised
nothing. **Rule for Ada:** exclude a device from being *messaged*, never from being *seen*.

## 2026-09-24 — CSRF token missing on crew pages silently killed Android GPS (memory
`feedback_mw_csrf_token_appstack_only.md`)
Fetches got 403, tracking start returned null with no log. Diagnosed from scratch while the
matching note existed. **Check:** apps / tracking — `device_tracking_health.last_point_at` age
for clocked-in users (already watched by `tracking_silent_alert`).

## Migrations shipped but never run (Tech-Debt-Map, several)
e.g. `media_assets` archival columns referenced by code but never migrated (2026-07-19) — the
iOS Receipts list went silently empty. **Check:** deploys — migration files on the server with
no `migrations_log` row.

## 2026-10-12 — Runtime DDL that a least-privilege DB user would silently break (verified)
`recordCronRun()` ran `CREATE TABLE IF NOT EXISTS cron_runs` before every insert, inside a
try/catch that only error_logs. MySQL checks the CREATE privilege before it checks existence
(checked on the local MySQL 8 with a data-only user), so moving the app to a data-only user
would have stopped every cron run being recorded — and every scheduled job would have turned
"never ran" on Ada's page while working. Same shape: per-request CREATE TABLE in RecaptchaVerify,
ALTERs on every quote save, TRUNCATE (needs DROP) in three places. Fixed (fda861f2);
`RuntimeDdlTest` keeps new ones out. **Rule for Ada:** after a DB-privilege change, a sudden
"never ran" on many jobs at once is the privilege, not the jobs.

## 2026-10-12 — An audit delete that could never have worked (verified)
`ContactService::bulkDeleteCompanies()` deleted `activity_log WHERE entity_id = ? AND
entity_type = 'company'` — columns activity_log does not have (SHOW CREATE TABLE on production),
so that path threw. The other three company/quote deletes really did erase history. All four now
keep the rows (company_id / quote_id cleared) and write a '… deleted' row naming the old id.

## Pattern behind all of them
Every one ran "successfully" or showed a reassuring badge. The fix was always a **second
source**: the mailbox vs the poller, the crontab vs the dashboard, the decrypt vs the badge.
Ada's checks are pairs, not single readings.

## Public files that should never be (found 2026-10-12)
- `/crm/email-logs/email-YYYY-MM-DD.log` (EmailLogger, 150 daily files since 2026-02-10: recipient,
  subject, headers, first 500 chars of text) answered HTTP 200 to anyone — no `.htaccess` in the folder.
- `/CLAUDE.md`, `/.git-ftp.log`, `/.DS_Store`, `/loginAuth/loginAuth_ARCHITECTURE.md`, other `*.md`
  in public_html: all 200.
Fixed: `public/crm/email-logs/.htaccess` (deny all) and a FilesMatch block in `public/.htaccess`
(md, log, sql, bak, orig, swp, .DS_Store, .gitignore, .git-ftp.log). Whether anyone fetched them
is unknown — cPanel → Metrics → Raw Access needs Tim's login.
**Check to build (security):** a weekly request from the server to a fixed list of paths that must
answer 403/404 (the files above, `/app_config/config.php`, `/.git/HEAD`, `crm/api/once-*.php`
leftovers); red on any 200.

## 2026-10-12 — Fable review (security)
Rules now enforced in code:
- Every `public/crm/api/run-migration-*.php` calls `migrationRunnerGuard()`: admin/database.manage
  only; GET shows a confirm button; the run needs POST + CSRF. Removed (applied on prod):
  run-migration-ba-pairs, -quiz7, -quote-tracking — the lead deletes them from the server.
  Four prod-only runners (1030, 1035, 1068, 1330) were pulled into the repo and guarded.
- JWT: role and is_active come from `users` per request (`jwtCurrentUser()`); no fallback signing key
  (a missing/short BLUEMOON_JWT_SECRET fails closed; Ada's Security group shows its length only).
- Login throttle: pair 5, per IP 20, per email 15 in 10 minutes (`loginThrottled()`).
- 3+ 'warning' runs in a row is red/failing in `AdaRules::cronCheck()`.
- `public/vendor/` denies all HTTP; `documents/download.php` needs billing.view;
  `opcache-reset.php` is POST + CSRF (the URL shows a button).
- `dev-context.php` reads `X-Context-Token` only (query fallback removed 2026-10-12, group A); the hook
  sends the header.
Not changed: `session_config.php` — production already sends SameSite=Lax to every client (the
repo's Capacitor SameSite=None branch was never deployed).

## 2026-10-12 — security remediation, group A (ops, backups)
- **FTP TLS was never verified.** `ftp.mowology.ca` presents a `*.canadianwebhosting.com` cert, which
  is why every script had verification switched off. All FTP now goes through `scripts/lib/ftp.sh`
  (`mw_lftp`), which connects to the server's own name `cwhtor13.canadianwebhosting.com` and
  verifies. If the host moves servers, every script fails loudly with "Certificate verification" —
  re-derive the name (`dig +short -x $(dig +short ftp.mowology.ca)`), never switch verification off.
  `DeployScriptTlsTest` enforces it.
- **IMAP** (`ImapReader`) validates the certificate with no retry; mail.mowology.ca validates from
  production (probe 2026-10-12). A cert failure now shows as a poller that logs
  "[ImapReader] imap_open failed … Certificate failure" — check that before blaming credentials.
- **Backups:** `scripts/prod-backup.sh` streams the dump (nothing on the server),
  `scripts/prod-backup-files.sh` mirrors uploads/_media/storage docs (download only),
  `scripts/prod-restore.sh --drill` proves a dump restores (row counts per table). Nightly 02:30 via
  launchd `ca.mowology.backup` (`scripts/install-backup-agent.sh`); log `~/Backups/mowology/backup.log`.
  **Check to build:** newest dump in ~/Backups/mowology older than 36 h → red (needs a Mac-side
  heartbeat, the server cannot see the Mac).
- **Apple push:** pending rows 30+ min overdue are red; a 'quiet' push no longer vouches for push_drain.
- login-debug.log only with `LOGIN_DEBUG`; the old file on production should be deleted.

## 2026-10-13 — Security fixes that silently stop being true (why the nightly posture check exists)
Every 2026-10-12 remediation was verified once, by hand, and nothing re-checked it: an .htaccess
overwritten by a deploy re-exposes /crm/tmp; the audit copy (`audit_export`) was built but on
2026-10-13 **no activity_log.jsonl existed on production** — the cron line was never added, and only
cron_runs would have said so; the Mac backup agent writes only to a Mac log the server cannot see; a
live-edited file (the "prod ahead of repo" trap) is invisible until the next deploy's drift stop.
**Caught by:** cron `security_posture` (checks.md (7)). Its own traps:
- The server fetching its own URLs proves what the public sees only because it goes out by name over
  HTTPS (verified 2026-10-13: 200 with a full body); a local run needs MW_POSTURE_BASE_URL.
- Drift reads app/Storage/deploy-manifest.json, which only `scripts/deploy-checked.sh` (and so
  `scripts/deploy-merged.py`) writes. A file uploaded any other way (lftp by hand, cPanel editor,
  `/deploy`) reads as "changed on the server" — that is the point; redeploy it through the checked path.
- The production PHP error log is NOT ~/error_log alone: ini_get('error_log') is
  /home/mowology/logs/mowology_ca.php.error.log, and ~/error_log was 93 MB (CLI crons) on 2026-10-13.
  Counting reads the last 16 MB of each and says "at least" when the 24 h window runs past it.
- `SYSTEM` is a reserved word in MySQL 8 (the local site), so the table's key column is check_key.
- Payment-mail: Yardi lines that auto-record are stored with sender_verified NULL and status
  'recorded' (only proven mail auto-records) — count them as verified, or a quiet week of good
  Yardi mail reads as "all held".
