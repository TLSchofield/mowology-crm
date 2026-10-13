---
name: otto-operations
description: Use BEFORE touching anything operations-related in the Mowology CRM / Ittai — Otto (the Operations head) and his card/suggestions, the schedule and calendar stops, crews and crew assignment, visits and job timers, the time clock, timesheets, breaks, GPS/tracking (crew phones, truck tablet, Trackimo), trip/dump/supply runs, trucks and equipment, weather moves, bylaw hours, bulk material deliveries, the supplier directory and supplier account forms, company documents (business licence, insurance), or snow/salt routes. Also when Tim asks "where is the crew…", "why didn't the phone track…", "who forgot to clock out…", "fill this supplier form…", or reports a wrong Otto suggestion. Holds how Otto is allowed to operate, the single source of truth for each job, iPhone vs web/Android parity, and the traps already hit.
covers:
  - app/Modules/Operations/*
  - app/Modules/Tracking/*
  - app/Modules/Comms/Services/SupplierInboxService.php
  - app/Services/Spreadsheet/*
  - public/crm/suppliers/*
  - app/Modules/Team/Services/TimeclockFunctions.php
  - app/Modules/Team/Services/TrackingIngestService.php
  - app/Modules/Team/Services/TrackingHealthService.php
  - app/Modules/Team/Api/team-mobile.php
  - app/Modules/Team/Api/time-clock.php
  - app/Modules/Jobs/Services/Plan/*
  - app/Modules/Schedule/Api/*
  - app/Modules/Contracts/Services/SnowContractService.php
  - app/Modules/Jobs/Cron/auto_rollover.php
  - app/Modules/Schedule/Api/missed-review.php
  - public/crm/js/otto-missed.js
  - public/crm/includes/otto-card.php
  - public/crm/js/otto-card.js
  - public/crm/ops/*
  - public/crm/timeclock/*
  - public/crm/jobs/schedule.php
  - ios/MowologyCRM/MowologyCRM/Features/Team/OttoSuggestionViews.swift
  - ios/MowologyCRM/MowologyCRM/Features/Team/OttoMissedReviewViews.swift
  - ios/MowologyCRM/MowologyCRM/Features/Schedule/*
  - ios/MowologyCRM/MowologyCRM/Features/TimeClock/*
metadata:
  version: 1.0.0
  created: 2026-10-11
  owner: Tim Schofield (Mowology)
---

# Otto — operations for Mowology (and Ittai)

Otto is the CRM's Operations head. This skill is what a careful dispatcher would know before
touching the schedule, the crews' time or the trucks: **how Otto is allowed to act**, **where each
job lives in code**, **which surface shows what**, and **what already went wrong**. Read the
reference that matches the task:

| Task | Read |
|---|---|
| What Otto may suggest, apply, send, learn; supplier forms; deliveries | `references/operating-rules.md` |
| Crew phones, truck GPS, time clock, breaks, trips, tracking policy | `references/crew-and-gps.md` |
| Traps already hit (read before ANY change to schedule, clock or GPS) | `references/traps.md` |
| iPhone vs Android vs web — keeping Otto the same everywhere | `references/surfaces.md` |

**Location rules** (taxes, payroll, CASL/SMS, bylaws, licences — by country/province/city) live in the shared
`regions` skill (`.claude/skills/regions/`), not here. Read the file for the place the job is in.

## Before and after every change (Obsidian + Graphify)

**Before** writing or changing anything operations-related:
1. Read the Obsidian notes for the area —
   `~/Library/Mobile Documents/iCloud~md~obsidian/Documents/30-PROJECTS/Active/Mowology-CRM/Architecture/`
   `grep -n -i "<area>" Known-Failure-Patterns.md` first (gps, tracking, clock, csrf, schedule,
   truck, break, supplier, weather), then the Decision-Log entry (GPS server boundary 2026-09-19,
   trip overhead 2026-10-07, crew-app shell 2026-10-08, supplier forms 2026-10-10).
2. Graph what you are about to touch: `python3 ~/.claude/tools/graphify.py touches <Class>`.
   Checked 2026-10-11: `OpsDeskService` reaches 17 files (Charlie's brief/inbox/urgent, team card,
   web card, iPhone `team-mobile.php`); `TripSegmentService` feeds `BreakService`,
   `StopEvidenceService`, `TripCostService` and Charlie's fact answerer — a change to stop
   detection changes breaks, trip costs and Charlie's answers at once.
3. Crew pages are fragile (one bad script froze every Android tap on 2026-10-08). Check whether
   the page is AppStack or standalone (`grep -c appstack_head <page>`) before using a page global.
4. Production files are often ahead of the repo (schedule, team-mobile, crew JS). `cmp` against
   the live copy before any deploy; on production data, `scripts/prod-run.sh` preview first.

**After** the change:
1. A mistake found or a rule learned → `references/traps.md` (this skill) and
   Known-Failure-Patterns.md (Obsidian) the same day; a "why it's built this way" → Decision-Log.
2. An Otto feature changed on one surface → update `references/surfaces.md` (and the other
   surfaces, or record the gap there).
3. Names, paths or rules in this skill changed → update the skill in the same commit.
4. `/obsidian` (bare) at the end of the session writes the rest back.

## The rules that are never broken

1. **Otto suggests; Tim decides.** Nothing changes a timesheet, moves a visit, sends an email or
   retires a machine without Tim's tap (`OttoActionService::decide()`). Nothing Otto does
   messages a crew member.
2. **Never claim a fact about crews, phones or trucks you did not check this session.** "The
   phone is broken" was twice a login/landing-page difference (CSRF). Cite the query (per-day fix
   counts, `accuracy` column, `device_type`). "Unverified" is acceptable.
3. **GPS: the server owns the work-hours boundary.** Fixes are judged by
   `TrackingIngestService::ingest()`; clients obey the returned policy. Silence is the failure
   mode — a poller or sweep that always finds nothing is broken until proven quiet.
4. **Breaks are paid at Mowology** (`breaks_paid` = 1); break records never change pay. BC: a
   30-minute meal break before 5 hours. GPS break flags stay OFF on Otto's card
   (`break_flags_enabled` = 0) until the crew app has a break button.
5. **Supplier forms: Otto fills from the company profile, sends only on Tim's yes, and never fills
   bank details, credit references, guarantees, signatures, SIN or personal ID.** Print-and-sign
   forms go to Tim as "needs you". Supplier and subscription mail uses office@mowology.ca.
6. **SMS to crews or clients follows the project SMS rules** (native mail gateway, ≤160 chars, no
   URLs, office phone `(778) 846-9273`).

## One place for each job (improve it there, and every screen and app gets it)

| Job | The one place | Used by |
|---|---|---|
| Otto's suggestions (record, expire, sort) | `OpsDeskService::current()` in `app/Modules/Operations/Services/OpsDeskService.php` | web card, iPhone Team tab, Charlie |
| Otto's lines in Charlie's 7 am brief | `OpsDeskService::brief()` | Charlie brief/inbox/urgent, team card |
| Applying a suggestion (every button) | `OttoActionService::decide()` | `app/Modules/Operations/Api/otto.php`, `app/Modules/Team/Api/team-mobile.php` |
| Suggestion kinds | `OpsDeskService::KINDS` — must list every kind `current()` emits (stats count by it) | card numbers, brief |
| Buttons for a new kind | server-sent `propose.buttons` [{label, choice, main}] | `public/crm/js/otto-card.js`, iOS `OttoSuggestionViews.swift` |
| Pure thresholds (quiet phone, gaps, weather lean, clock-out guess) | `OttoRules` (`OttoRules::suggestClockOut()`, `OttoRules::sortItems()`) | desk, actions |
| Weather moves | the noon guard `app/Modules/Jobs/Cron/weather_schedule_guard.php`; Otto's "move" reuses `public/crm/modules/scheduling/rescheduler.php`; learning `OttoActionService::learnWeather()` | card, Weather Actions page |
| Bylaw hours, West End | `MunicipalRuleService::items()` + `DispatchRules::breach()` | `public/crm/ops/municipal-rules.php` |
| Equipment, truck range, packs, maintenance | `EquipmentService::suggestionItems()`, `DispatchRules::dayCapKm()` | `public/crm/ops/equipment.php` |
| Crew training gaps | `TrainingService::items()` | card |
| Truck day → stops, drives, labels | `TripSegmentService::detectStops()` / `TripSegmentService::label()` | breaks, trip cost, evidence |
| Dump / supply run cost | `TripCostService::process()` (cron `app/Modules/Operations/Cron/trip_runs_daily.php`) | `app/Modules/Operations/Api/trips.php`, Penny |
| Places learned from receipts on the trail | `StopEvidenceService::learnPlaces()` | nightly cron, trips API |
| GPS breaks + BC flag | `BreakService::run()` / `BreakService::complianceGap()` | trip cron, brief (flag off) |
| Bulk material delivery vs trailer | `MaterialDeliveryService::forLines()` / `MaterialDeliveryService::briefItems()` | brief |
| Supplier directory (geocode, map, vendors) | `SupplierDirectoryService::placeAll()` | Otto's map, Penny's vendors |
| Supplier mail reading (read-only IMAP) | `SupplierInboxService::poll()` inside `app/Modules/Sales/Cron/sales_inbox_poll.php` | forms, licence, chase |
| Filling + sending a supplier form | `SupplierFormService::capture()` / `SupplierFormService::send()` with `PdfFormFiller::fill()` | Otto card, iPhone |
| Company answers for forms (and owner-only blocklist) | `CompanyProfileService::answer()` | forms |
| Business licence, insurance, WCB letter | `CompanyDocumentService::current()` / `CompanyDocumentService::briefItems()` | forms, brief (30-day reminder) |
| Snow/salt routes set up on signing | `SnowContractService::setupFromSignedQuote()`; no-crew routes `SnowContractService::ottoBriefItems()` | quote signing, brief |
| Phone fix ingest + policy | `TrackingIngestService::ingest()` / `TrackingIngestService::policy()` | every GPS path (incl. the web widget's `crew-location.php` since 2026-10-12); `newest` skips mock fixes |
| Client route proof (fixes ON the property only, no mocks) | `RouteProofService::forVisits()` in `app/Modules/Tracking/Services/RouteProofService.php` | `public/customer/portal.php`, `public/customer/visit-report.php` |
| Is this mail really from the supplier? | `SupplierInboxService::fromIs()` / `SupplierDirectoryService::senderMatches()` | inbox poll, `SupplierFormService::send()` |
| Quiet phones (incl. truck tablets) | `TrackingHealthService::sweep()` | silent-alert cron, desk "silent" |
| Truck pings (Trackimo) | `TruckLocationService::savePing()` via `app/Modules/Tracking/Cron/trackimo_poll.php` | trails |
| Clock, timers, proximity auto-start | `app/Modules/Team/Services/TimeclockFunctions.php` (`clockIn()`, `startJobTimer()`, `stopJobTimer()`, `checkProximityAutoStart()`) | web, Android, iPhone APIs |
| Who may stop / complete / skip a visit | `userMayActOnVisit()` in `app/Modules/Team/Services/TimeclockFunctions.php` | `app/Modules/Schedule/Api/timer.php`, `app/Modules/Jobs/Api/job-timer.php` |
| Any change to recorded time (approved week, overlap, weeks to recalc, DST minutes) | `TimeEntryGuard::refusal()` / `TimeEntryGuard::overlapping()` / `TimeEntryGuard::recalcWeeks()` + pure `TimeclockRules` | time-entry-edit, admin_add_entry, job-time-entry-edit, Otto clock_out/timer |
| Pay over a period (BC daily 8/12 h + weekly 40 h, per week) | `OvertimeCalculator::calculatePeriod()` | `public/crm/timeclock/payroll-summary.php` |
| Visit status changes (guarded) | `VisitLifecycleService::updateVisitStatus()` / `VisitLifecycleService::canTransition()` | every completion/skip path |
| End-of-day missed-visit review (open at last clock-out or 18:00, Otto's pick per visit, apply, lesson) | `MissedVisitReviewService::tick()` (rides Charlie's 15-min cron) / `MissedVisitReviewService::decide()`; pure pick `MissedVisitRules::pick()` | dashboard card section `public/crm/includes/otto-missed-panel.php`, desk `public/crm/ops/missed-visits.php`, iPhone `OttoMissedReviewSheet` via `/api/schedule/missed-review` |
| What the nightly rollover does to a past-due visit (roll / skip / hold a contract visit open) | `MissedVisitRules::rolloverAction()` in `app/Modules/Jobs/Cron/auto_rollover.php` | rollover cron |
| Stops for a day | `getCalendarStops()` in `app/Modules/Jobs/Services/Plan/CalendarStops.php` — TWO JSON shapes: `app/Modules/Jobs/Api/calendar-stops.php` (web/Android) and `app/Modules/Schedule/Api/day.php` (iPhone) | schedule |

**Not yet central (known):** iPhone renders per-kind buttons for older kinds itself
(`OttoSuggestionViews.swift`, mirroring the per-kind controls in `otto-card.js`); only server-sent buttons are
shared. A change to an older kind's buttons means both files.

## Keeping this skill true

- `tests/Unit/Docs/HeadSkillsTest.php` fails the test suite when a file, class or function this
  skill names no longer exists, when the Obsidian + Graphify section is missing, or when
  `covers:` is missing. Fix the skill in the same commit as the code.
- When a mistake is found in the schedule, clock, GPS or Otto's behaviour, add it to
  `references/traps.md` the same day (what happened, the signature, the rule).
- Owner facts (breaks paid, delivery price, trailer size, BC break rule) carry the date Tim gave
  them; re-ask when a setting in `ops_settings` disagrees.
