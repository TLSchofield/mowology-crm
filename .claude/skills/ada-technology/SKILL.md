---
name: ada-technology
description: Use BEFORE answering "is X working?" or touching system health in the Mowology CRM / Ittai — scheduled jobs (crons, cron_runs, the Database Manager registry), outside connections (Stripe, Google Maps / Search Console / Business Profile, Meta, Apple push, IMAP mailboxes, Trackimo), crew app versions, TestFlight builds, crash reports, deploys, OPcache, production-vs-repo drift, migrations shipped but never run, schema drift, secrets in the wrong place, unused logins. Also when building Ada herself (the planned head of Technology) or her health page. Holds what Ada will check, the exact table or file each check reads, how she is allowed to act, and the silent failures that justify her.
covers:
  - app/Modules/Technology/*
  - public/crm/ada_appstack.php
  - public/crm/includes/ada-card.php
  - app/Services/CrmFunctions.php
  - app/Core/CronLock.php
  - app/Core/MigrationRunnerGuard.php
  - app/Modules/Database/*
  - app/Services/Mail/MailboxConfig.php
  - app/Services/Push/*
  - app/Modules/Social/Services/SocialAccountHealth.php
  - app/Modules/Team/Services/TrackingHealthService.php
  - app/Modules/Team/Cron/tracking_silent_alert.php
  - app/Modules/Tracking/Cron/trackimo_poll.php
  - app/Modules/Accounting/Cron/yardi_eft_inbox_poll.php
  - app/Modules/ChiefOfStaff/Services/CharlieBriefService.php
  - app/Modules/ChiefOfStaff/Services/HeadBriefReadyService.php
  - public/crm/database_appstack.php
  - public/crm/api/opcache-reset.php
  - public/crm/api/app-version.php
  - public/crm/downloads/app-version.json
  - scripts/*
metadata:
  version: 1.0.0
  created: 2026-10-11
  status: built (phase 1, 2026-10-11)
  owner: Tim Schofield (Mowology)
---

# Ada — head of Technology (phase 1 BUILT 2026-10-11)

> **Built (2026-10-11, commit 418aeb74):** `AdaRules` (pure, `tests/Unit/Technology/AdaRulesTest.php`)
> and `AdaDeskService` in `app/Modules/Technology/Services/`; her line in Charlie's brief
> (`CharlieBriefService` desks list); her card `public/crm/includes/ada-card.php`; the System Health
> page `public/crm/ada_appstack.php` (admin only, sidebar "System Health"). Checks built: scheduled
> jobs, connections (Meta, Google Business, Search Console, Apple push, Stripe, Trackimo), unused
> logins. Everything else in `references/checks.md` marked PLANNED is still planned — plain words,
> never backticks, until it exists.

Agreed with Tim 2026-10-11: Ada keeps the system working and tells Charlie when it is not.
She runs on **rules, not AI** (reads tables that already exist — costs nothing), reports
**evidence, not alarms**, and **never fixes anything herself**: she diagnoses and hands a clear
task to a developer session, or to Tim when it needs his login. She will have a health page
(green / amber / red per system, each with a "last worked" date) and, for Ittai, health per tenant.

| Task | Read |
|---|---|
| What Ada checks and the exact data source for each check | `references/checks.md` |
| How Ada may act: evidence lines, hand-offs, thresholds | `references/operating-rules.md` |
| Where Ada will appear (Charlie's brief, health page, iPhone) | `references/surfaces.md` |
| The silent failures that justify her (read before building any check) | `references/traps.md` |

**Build order (agreed):** (1) scheduled jobs and (2) outside connections — BUILT; first security slice (unused logins) — BUILT. Next: apps, deploys, migrations never run, data soundness, the rest of security.

**Location rules** (taxes, payroll, CASL/SMS, bylaws, licences — by country/province/city) live in the shared
`regions` skill (`.claude/skills/regions/`), not here. Read the file for the place the job is in.

## Before and after every change (Obsidian + Graphify)

**Before** building or changing a health check, or diagnosing "is X working?":
1. Read the Obsidian notes —
   `~/Library/Mobile Documents/iCloud~md~obsidian/Documents/30-PROJECTS/Active/Mowology-CRM/Architecture/`
   `_Architecture-Index.md`, then `grep -n -i "<system>" Known-Failure-Patterns.md Tech-Debt-Map.md`
   (cron, opcache, drift, migration, token, meta, tracking…). Most silent failures are already
   written down there — the CSRF/GPS outage of 2026-09-24 was diagnosed from scratch while the
   matching entry existed.
2. Graph what you read from: `python3 ~/.claude/tools/graphify.py touches <function>` and
   `… file <path>`. A health check must read the same table the feature writes — confirm the
   writer exists (several crons never write `cron_runs`; see `references/checks.md`).
3. Production is the truth, not the repo: `scripts/prod-file.sh` / `scripts/prod-drift.sh`
   before trusting a repo copy; `scripts/prod-run.sh` (rolled back unless `--commit`) for queries.

**After** the change:
1. A new silent failure found → `references/traps.md` (this skill) and Known-Failure-Patterns.md
   the same day.
2. A check built → move it from PLANNED to BUILT in `references/checks.md` with its file path.
3. Names or paths in this skill changed → update the skill in the same commit.
4. `/obsidian` (bare) at the end of the session writes the rest back.

## The rules that are never broken

1. **Never say a system works, or is broken, without evidence checked this session.** Cite the
   row, run or file. "Unverified" is a valid answer. A dashboard saying "Never run" or
   "Verified" is not evidence — both have lied before (see traps).
2. **Silence is a finding.** A job that always reports zero, a table with no new rows, a badge
   that never changes: treat as possibly broken until a second source agrees it is quiet.
3. **Ada never fixes, deploys, rotates or reconnects.** She diagnoses and hands over: to a
   developer session (code), or to Tim (anything behind his login — cPanel crontab, Meta/Google
   reconnect, migrations runner, OPcache reset, secrets).
4. **Rules, not AI.** Every check is a query or file comparison with a stated threshold. No
   model calls in her checks.
5. **Never print a secret.** Report *where* a secret is and *that* it is misplaced; never its
   value — not in a brief, a log, a skill file or the Obsidian vault (it syncs through iCloud).
6. **One line per problem in Charlie's brief**, with the evidence in it:
   "Yardi reader: 0 emails since it started on Aug 5, while 5 remittances (May–Jul) sit in office@ before its start date and Dorset's go to info@/iCloud, which it never opens — coverage gap, not necessarily broken."

## One place for each job (the existing tools Ada will read — all verified 2026-10-11)

| Job | The one place | Notes |
|---|---|---|
| Record a cron run | `recordCronRun()` in `app/Services/CrmFunctions.php` → table `cron_runs` (cron_key, status, summary, duration_ms, error_message, ran_at) | `ran_at` is Pacific local time, not UTC |
| The list of crons that should exist | `$crons` array in `public/crm/database_appstack.php` (key, expr, app path) | registry entry does NOT schedule cPanel |
| Cron run history API | `app/Modules/Database/Api/cron-log.php` (`latest`, `history`) | admin only |
| Cron overlap lock | `CronLock::acquire()` in `app/Core/CronLock.php` | records nothing |
| Server error log | `writeSystemLog()` → table `system_log`; page `public/crm/system-log_appstack.php` | |
| Migrations run | table `migrations_log`; runner `app/Modules/Database/Api/migrations-manager.php` | files in `database/migrations/` |
| Schema snapshot + drift | `app/Modules/Database/Cron/schema_snapshot.php`, `getSchemaSnapshot()` and `compareSnapshots()` in `app/Modules/Database/Services/SchemaFunctions.php` | daily 4 AM (`schema_snapshot`) |
| Mailbox list + configured or not | `MailboxConfig::status()` in `app/Services/Mail/MailboxConfig.php` | says "configured", not "login works" |
| Social account health | `SocialAccountHealth::sweep()` (hourly per account, via `social_publisher`) → `social_accounts.meta_json['health']`; `SocialConnectionState::load()` | built after the Meta outage |
| Crew phone GPS health + app version | `TrackingHealthService::sweep()`; table `device_tracking_health` (app_version, platform, last_seen_at, last_point_at) | cron `tracking_silent_alert` |
| Apple push | `app/Services/Push/ApnsService.php`, `app/Services/Push/PushDispatcher.php`; tables `push_queue` (status pending/sent/failed/expired), `device_tokens` (last_seen_at) | drain cron `app/Services/Push/Cron/push-drain.php` |
| App version offered to crew | `public/crm/api/app-version.php` reading `public/crm/downloads/app-version.json` | what we offer, not what they run |
| TestFlight / Xcode Cloud | `/appstore-cloud` command (`~/.claude/scripts/appstore-cloud.py`, Mac-side) | |
| iPhone crash reports | `pymobiledevice3 crash` off the paired phone (memory `reference_ios_crash_logs_from_device.md`) | Mac-side, manual |
| OPcache reset | `public/crm/api/opcache-reset.php` (admin only; host runs validate_timestamps off) | |
| Production vs repo | `scripts/prod-drift.sh` (directory), `scripts/prod-file.sh` (files), `scripts/deploy-checked.sh`, `scripts/deploy-merged.py` | Mac-side, FTP |
| Queries on production | `scripts/prod-run.sh` (rolled back unless `--commit`) | Mac-side |
| Audit trail (who changed what) | `activity_log` via `logActivityExtended()` / `logActivity()`; never deleted from (`AuditTrail` cuts links instead; `AuditTrailTest` scans for DELETE/TRUNCATE); before → after lines via `AuditDiff` | append-only copy: `AuditExportService`, cron `audit_export` |
| Schema changes vs everyday DB user | `Database::ddl()` / `Database::useDdlForRequest()` in `app/Core/config.php` (optional `DB_DDL_*` in secrets.php); `migrationRunnerGuard()` switches runners to it | `RuntimeDdlTest` fails on new runtime DDL |
| Charlie's brief (where Ada's line goes) | `CharlieBriefService::collect()` in `app/Modules/ChiefOfStaff/Services/CharlieBriefService.php`; ready copy `HeadBriefReadyService::rebuildCharlie()` | heads contract: `brief(string)` |

**Not yet central (known):** there is no single "connection status" table — each integration
keeps its own (social in `meta_json`, push in `push_queue`, Search Console in `gsc_sync_history`,
mailboxes only in cron summaries, Trackimo only in `vehicle_location_pings`). Ada's first job
for check (2) is to read them where they are, not to build a new store.

## Keeping this skill true

- `tests/Unit/Docs/HeadSkillsTest.php` fails the test suite when a file, class or function this
  skill names in backticks no longer exists, or when the skill loses its Obsidian + Graphify
  section or its `covers:` list. That is why nothing planned is ever in backticks.
- `covers:` lists the EXISTING code Ada watches. At the end of any session that changed it, the
  Stop hook (`~/.claude/hooks/vault-writeback.py`) names this skill and sends you to update it.
  When Ada's own code lands, add its paths to `covers:`.
- When a piece of Ada is built, move it from PLANNED to BUILT here and in `references/checks.md`,
  and only then put its name in backticks.
- When a silent failure is found anywhere in the system, add it to `references/traps.md` the
  same day, with the check that would have caught it.
