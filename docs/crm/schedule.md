# Schedule / Plan / Visit Function Library

> Sectional doc for the Job Plan → Visit → Calendar Stop engine.
> Watched by the pre-commit doc-sync hook: changes to
> `app/Modules/Jobs/Services/PlanFunctions.php` or `public/crm/jobs/schedule.php`
> should update this file.

## Domain model

| Table | Meaning |
|-------|---------|
| `job_plans` | The service agreement / contract (recurring or one-off). |
| `plan_line_items` | What services are included in each visit (drives price). |
| `job_visits` | One occurrence of work generated from a plan. |
| `calendar_stops` | One card per property per day per crew on the schedule. |

## Where the code lives

`app/Modules/Jobs/Services/PlanFunctions.php` is a **thin aggregator** (no logic
of its own). It `require_once`'s 11 focused domain files under
`app/Modules/Jobs/Services/Plan/`. Every function is a **global function** (no
namespace) so the ~28 callers and the legacy shim keep working unchanged.

> **Do not add logic to `PlanFunctions.php`.** Add it to the relevant `Plan/*.php`
> file below (or create a new one and `require_once` it from the aggregator).

### Load paths

```
public/crm/includes/plan-functions.php   (legacy shim — DO NOT add code)
        └─ require app/Modules/Jobs/Services/PlanFunctions.php   (aggregator)
                └─ require app/Modules/Jobs/Services/Plan/*.php   (11 domain files)
```

Most callers `require_once CRM_INCLUDES . '/plan-functions.php'`; a few require
`APP_ROOT . '/Modules/Jobs/Services/PlanFunctions.php'` directly. Both resolve to
the same aggregator.

### Domain files (`Services/Plan/`)

| File | Functions |
|------|-----------|
| `PlanHelpers.php` | `planTimeStringToMinutes`, `planMinutesToTimeString`, `isVisitHorizonCurrent`, `generatePlanNumber`, `generateVisitNumber` |
| `PlanCrud.php` | `createJobPlan`, `createPlanFromQuote` |
| `PlanLineItems.php` | `addPlanLineItems`, `getPlanLineItems`, `updatePlanTotalFromItems`, `getNextScheduledVisitDate`, `getQuoteLineItemsWithStatus`, `getPlansForQuote` |
| `VisitGeneration.php` | `generateVisits`, `getActiveHolidays`, `findBumpDate`, `parseDowList`, `calculateRecurrenceDates` — **facade**: these delegate to `VisitGenerationService` (Phase 2). |
| `CalendarStops.php` | `ensureCalendarStop`, `getCalendarStops` |
| `VisitLifecycle.php` | `updateVisitStatus`, `getVisitWithPlan`, `getPlanDetails`, `getPlanVisits`, `pausePlan`, `resumePlan`, `propagatePlanChanges`, `skipVisitDate`, `moveVisit`, `canInvoiceVisit` |
| `PlanDashboard.php` | `getPlanDashboardStats`, `getRecentPlansOnProperty`, `resolveTrackingRequirementsForPlan`, `resolveTrackingRequirements` |
| `PlanProfitability.php` | `getOverheadPercentage`, `getOverheadSettings`, `getMonthlyOverheadTotal`, `getPlanProfitability`, `getStopProfitabilityBatch` |
| `PlanEditing.php` | `cleanupOrphanedVisits`, `updateJobPlan`, `replacePlanLineItems` |
| `PlanCrew.php` | `getPlanCrewAssignments`, `setPlanCrewAssignments`, `getVisitCrewAssignments`, `setVisitCrewAssignments`, `getUnscheduledVisits` |
| `PlanMaterials.php` | `generateFertilizerVisits`, `calculateMaterialsForVisit`, `getPurchaseTasksForSchedule` |

50 public functions total (each keeps its original name + signature).

## Visit generation flow

1. **Cron** `app/Modules/Jobs/Cron/generate_visits.php` calls `generateVisits()`
   to materialise `job_visits` up to a rolling horizon (default 42 days;
   `isVisitHorizonCurrent()` is the cheap read-only "are we caught up?" check at
   14 days).
2. `generateVisits()` → `calculateRecurrenceDates()` expands the plan's
   day-of-week + frequency rules, then `getActiveHolidays()` / `findBumpDate()`
   shift visits off holidays and blackout dates.
3. `ensureCalendarStop()` places each visit onto the schedule as a
   `calendar_stops` card; `getCalendarStops()` is what the schedule page reads.
4. Pre-sold fertilizer bundles use `generateFertilizerVisits()` with explicit
   dates instead of recurrence math.

## Critical: the timer / completion path

The clock-out / job-timer endpoints rely on `updateVisitStatus()` (global facade
in `Plan/VisitLifecycle.php` → `VisitLifecycleService::updateVisitStatus`). If
`plan-functions.php` is **not loaded**,
`updateVisitStatus` is silently undefined and visit completion no-ops — the visit
stays `scheduled` and map pins stay green. Any endpoint that completes a visit
**must** require the plan-functions chain. See `project_timer_status_persistence`.

## Cross-platform completion sync (added 2026-08-12)

Neither the web/Capacitor schedule page nor the iOS app previously re-fetched
data on their own — a completion on one device wasn't visible on another
until a manual reload, and iOS's pull-to-refresh was a separate, since-fixed
bug (an in-memory day cache that never busted itself, see
`project_ios_schedule_cache_staleness` in memory / Known-Failure-Patterns).
Two independent mechanisms now cover this:

- **Polling (live today):** iOS `ScheduleViewModel` silently re-fetches the
  selected day every 20s while foregrounded (`startPolling()`/`stopPolling()`,
  paused on `scenePhase` changes). `schedule-pill-workflow.js` polls the
  existing session-authed `calendar-stops.php` on the same cadence and patches
  pills via the existing `updatePillVisual()`/`checkStopComplete()` — no new
  DOM-rendering logic. Crew polls scoped to their own `?crew=` id (that
  endpoint doesn't default-scope to the caller like the JWT `day.php` does).
- **Push (built, not yet live):** `VisitLifecycleService::notifyCompletion()`
  is called from **both** completion write paths (`updateVisitStatus()` here
  and `pow-actions.php`'s `end_visit`) — one shared method, not two copies —
  and notifies admins/managers plus any other crew on a multi-crew stop via
  `PushDispatcher`. Needs `FcmService` (Android) and `ApnsService` (iOS)
  credentials provisioned in `secrets.php` before anything actually sends.

## Crew service recommendations (added 2026-08-27)

Crew can offer extra work from the job card: photograph what needs doing, tap a
service chip, and the client gets a priced quote. Built on the existing Field
Observations tables (migrations 602/605), not a parallel system.

- **Web/Android:** a "Recommend" button in `schedule-pill-workflow.js`'s working
  drawer opens a chip picker (`openRecommendModal`). Chips come from
  `/api/schedule/recommendation?action=options`; photos reuse the existing
  `uploadObservationPhoto()` path and their `media_id`s are passed to `create`.
- **iOS:** `Features/Schedule/RecommendationSection.swift`, embedded in
  `VisitDetailView` next to `JobPhotoSection`. Offline captures queue in
  `RecommendationQueue` and drain app-wide via `AppPhotoQueueDrainService`.
- **Backend:** `FieldRecommendationService` (all the rules) behind
  `app/Modules/Schedule/Api/recommendation.php`, which uses `requireLoginOrJwt()`
  so one endpoint serves JWT (iOS) and session+CSRF (Android WebView).

Auto-send is deliberately narrow — the office must flag the product
(`products.field_auto_send`), the price must not depend on measurements, and it
must be non-zero. Everything else queues in `/crm/products/recommendations.php`
for review. It fails closed: any doubt routes to a human.

The quote is a **real** `quotes` row created through `QuoteService`, which is why
portal display, signature acceptance, decline and follow-up nagging all work
without new code — and why PM/strata properties route to whoever authorises
spend, exactly as invoices do.

Needs migration 1114. All new-column reads are `SHOW COLUMNS`-guarded, so the
code is inert rather than broken if it ships first.

## Safety net

`tests/Unit/Jobs/PlanFunctionsLoadTest.php` is a characterization test asserting
all 50 functions load with their original arity. Run `vendor/bin/phpunit` before
every commit. This library otherwise has no behavioural test coverage — treat
changes to recurrence math and `updateVisitStatus` with extra care.

## Deployment

`app/` is **not** in cPanel auto-deploy (it only ships `public/`). After editing
any `Plan/*.php` or the aggregator, deploy via `lftp` to
`/app/Modules/Jobs/Services/` and hit `/crm/api/opcache-reset.php` (OPcache
`validate_timestamps` is off on production).

## Phase 2 — service extraction (in progress)

Each `Plan/*.php` domain's logic is being moved into a real service class behind
the unchanged global functions (facade), one domain at a time, so the logic can
be unit-tested. First done:

| Service | Backs | Tests |
|---------|-------|-------|
| `Services/VisitGenerationService.php` | `Plan/VisitGeneration.php` globals | `tests/Unit/Jobs/VisitGenerationServiceTest.php` (pure recurrence math) |
| `Services/PlanProfitabilityService.php` | `Plan/PlanProfitability.php` globals | `tests/Unit/Jobs/PlanProfitabilityServiceTest.php` (pure overhead/profit/margin math) |
| `Services/PlanMaterialsService.php` | `Plan/PlanMaterials.php` globals | `tests/Unit/Jobs/PlanMaterialsServiceTest.php` (application-rate parser, purchase-task distribution) |
| `Services/PlanHelpersService.php` | `Plan/PlanHelpers.php` globals | `tests/Unit/Jobs/PlanHelpersServiceTest.php` (time↔minutes, visit numbering) |
| `Services/VisitLifecycleService.php` | `Plan/VisitLifecycle.php` globals | `tests/Unit/Jobs/VisitLifecycleServiceTest.php` (status/propagation/move SET builders, invoice eligibility) |

The pure methods (`parseDowList`, `findBumpDate`, `calculateRecurrenceDates`) take
explicit dates — no DB, no clock — so they are fully deterministic and unit-tested.
`generateVisits`/`getActiveHolidays` still touch the DB.

---
_Last decomposed 2026-06-18: 3,200-line monolith → aggregator + 11 `Plan/` files._
_Phase 2 began 2026-06-18 with VisitGenerationService._
