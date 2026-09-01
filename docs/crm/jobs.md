# Jobs Module

> Sectional doc for `app/Modules/Jobs/`. Watched by the pre-commit doc-sync hook:
> changes under `app/Modules/Jobs/` should update this file.
> The plan/visit/calendar **function library** has its own doc — see
> [schedule.md](schedule.md).

## Responsibilities

The Jobs module owns the work-execution side of the CRM: turning quotes into
recurring service plans, generating the visits those plans imply, placing them on
the crew schedule, tracking crew assignment and clustering, and running the timer
/ completion lifecycle.

## Layout

```
app/Modules/Jobs/
├── Services/
│   ├── PlanFunctions.php        ← aggregator (see schedule.md) — DO NOT add logic here
│   ├── Plan/                    ← 11 domain files, 50 global functions (see schedule.md)
│   ├── ClusterService.php       ← geographic clustering of stops
│   ├── ClusterDetectionService.php
│   ├── VisitCompletionService.php
│   ├── SeasonalOutlookService.php ← Nov–Mar freeze/snow outlook (read side)
│   └── SeasonalOutlookRefreshService.php ← rebuilds it from NOAA + ECCC (write side)
├── Api/                         ← thin JSON controllers (one endpoint per file)
└── Cron/                        ← scheduled batch jobs
```

### Services

| Service | Purpose |
|---------|---------|
| `PlanFunctions.php` + `Plan/*.php` | Plan → visit → calendar-stop engine. Procedural global functions. **See [schedule.md](schedule.md).** |
| `VisitGenerationService.php` | Recurrence/holiday math + visit materialisation. Phase 2 extraction — the `Plan/VisitGeneration.php` globals delegate here; pure recurrence math is unit-tested. |
| `PlanProfitabilityService.php` | Overhead settings + plan/stop profit & margin math. Phase 2 extraction — `Plan/PlanProfitability.php` globals delegate here; pure money math is unit-tested. |
| `PlanMaterialsService.php` | Fertilizer-bundle visits, materials calc, purchase-task schedule. Phase 2 extraction — `Plan/PlanMaterials.php` globals delegate here; the application-rate parser and purchase-task date distribution are unit-tested. |
| `PlanHelpersService.php` | Time↔minutes conversion, visit-horizon check, plan/visit numbering. Phase 2 extraction — `Plan/PlanHelpers.php` globals delegate here; the pure time/numbering helpers are unit-tested. |
| `VisitLifecycleService.php` | Visit status/lifecycle (incl. the revenue-critical `updateVisitStatus`), plan pause/resume, exceptions, invoice eligibility, and completion push notifications (`notifyCompletion()`, shared by both completion write paths — see [schedule.md](schedule.md#cross-platform-completion-sync-added-2026-08-12)). Phase 2 extraction — `Plan/VisitLifecycle.php` globals delegate here; the status/propagation/move SET builders and invoice checklist/photo checks are unit-tested. |
| `ClusterService.php` / `ClusterDetectionService.php` | Group nearby stops into geographic clusters for route building. |
| `VisitCompletionService.php` | Completion-side logic for finished visits. |

> Per CLAUDE.md rule 10, new business logic belongs in a service class, not in
> page/API files. The Plan engine predates that rule and stays procedural; the
> 2026-06-18 split into `Plan/*.php` was step one. **Phase 2** (in progress) moves
> each domain's logic into a real service class behind the unchanged global
> functions (facade) so it can be unit-tested. `VisitGenerationService` is the
> first; remaining `Plan/*.php` domains still hold their logic inline.

### API endpoints (`Api/`)

Each file is a thin controller that requires the plan-functions chain (or the
relevant service) and returns JSON. Current endpoints:

`api-jobs`, `assign-crew`, `calendar-stops`, `clusters`, `job-creation`,
`job-timer`, `optimize-route`, `placement-scores`, `profit-risk-factors`,
`reschedule-job`, `reschedule-job-simple`, `reschedule-stop`, `schedule-visit`,
`set-route-pin`, `weather-actions`.

`job-timer.php` is on the **revenue-critical completion path** — it must load
`plan-functions.php` so `updateVisitStatus()` is defined (see schedule.md).

### SeasonalOutlookService

Answers the season-level question the 7-day forecast cannot: *how many freeze
mornings and snow events should be staffed and quoted for between November and
March.* Returns per-month expected frost nights, snow days, days ≥ 2 cm and
snowfall, each paired with its observed normal so the card renders the anomaly
rather than a bare number.

Baseline is ECCC daily observations for Vancouver Intl A (climate ID 1108447),
28 complete Nov–Mar seasons in 1995/96–2024/25. The projection blends the
strong-El Niño analog winters with climatology — see the class docblock for the
method and its limits.

Kept **separate from `app/Services/Weather/WeatherService.php` on purpose**: that
service is a 7-day deterministic forecast, this is a 5-month probabilistic
outlook. Merging them is how an outlook gets read as a forecast.

- `activeOutlook()` returns the payload or `null` out of season — callers render
  nothing rather than an empty panel.
- Two frost metrics, because they gate different decisions: `frost` (Tmin ≤ 0 °C,
  air frost — pipes and irrigation) and `ground_frost` (Tmin ≤ 4 °C, a proxy for
  turf frost — mowing delays). Ground frost runs ~2.5× the air-frost count, since
  grass frosts on clear calm nights while the thermometer still reads +2 to +4 °C.
  The ≤4 °C proxy is an upper bound: no cloud/wind filter, no grass-minimum
  thermometer at the station.
- Every outlook carries `review_by`. Once it passes, the card flags itself
  **Review overdue** instead of presenting stale numbers as current. That is about
  the *prose*; `isDataStale()` is the separate check for "the refresh cron died".
- Next season is an `ops_settings` edit under `seasonal_outlook_current` (JSON),
  not a deploy. No migration needed — `ops_settings` ships in migration 202.
- Rendered by `public/crm/modules/weather/seasonal-outlook-card.php` in two
  variants (`compact` on the dashboard, `full` on Weather Actions). See
  `COMPONENTS.md`.

### SeasonalOutlookRefreshService

The write side. Pulls NOAA CPC's ONI index and the ECCC bulk daily CSV for
Vancouver Intl A, recomputes the projection, and stores it in `ops_settings`.
Driven by the `seasonal_outlook_refresh` cron (daily, 5 AM).

**Why daily** when ONI updates monthly: the daily value is the *season-to-date
actuals*. Once November starts, each run refreshes how many frost nights and snow
days have actually occurred against what was projected, so a wrong outlook becomes
visible while the season can still be acted on. The 30-winter climatology is cached
(`seasonal_outlook_climatology`) and rebuilt at most yearly — that is the slow path,
~30 HTTP fetches, and it is skipped on virtually every run.

**Analogs are selected from data, never hardcoded.** A fixed list of El Niño winters
silently becomes wrong the moment the regime flips — it would keep projecting a mild
winter straight through a La Niña. Instead the current 3-month ONI season is compared
against *the same season* in each past year, so the comparison is like-for-like and
uses only what would have been known at this point in the calendar.

**It never rewrites the prose.** `headline`, `notes` and `driver_note` encode
judgement ("hold capacity for January" is a staffing call). The refresh updates
figures and leaves the words alone, and deliberately does not clear `review_by`.

Gotchas worth knowing before touching it:
- The ECCC CSV carries a UTF-8 BOM *before* the opening quote of the first field,
  so `fgetcsv` returns that field with literal quote characters attached. The BOM is
  stripped from the raw string before parsing — do not "fix" it on `$header[0]`.
- Blank cells parse to `null`, never `0.0`. A missing observation and a real zero are
  different facts; conflating them invents dry days.
- Station 889 covers to 2013, 51442 from 2013. Winter 2012/13 straddles the switch and
  is dropped by the missing-days filter — expected, not a bug.
- A failed fetch returns `ok => false` and leaves the previous payload intact rather
  than writing a half-built one.

### Cron (`Cron/`)

| Cron | Purpose |
|------|---------|
| `generate_visits.php` | Materialise `job_visits` ahead of the rolling horizon (calls `generateVisits()`). |
| `auto_rollover.php` | Roll incomplete/overdue visits forward. |
| `weather_schedule_guard.php` | Adjust the schedule around weather. |
| `seasonal_outlook_refresh.php` | Daily. Rebuild the winter outlook from NOAA ONI + ECCC station data; refresh season-to-date actuals. |

Crons run under `/usr/local/bin/php`. Registration/health is tracked in the
registry-driven Cron Jobs tab — see `project_cron_manager_registry`.

## Testing & deployment

- Safety net: `tests/Unit/Jobs/PlanFunctionsLoadTest.php` (function presence +
  arity) and `tests/Unit/Jobs/SeasonalOutlookServiceTest.php` (pure helpers,
  no DB). Run `vendor/bin/phpunit` before every commit.
- `app/` is not in cPanel auto-deploy. Deploy module changes via `lftp` to
  `/app/Modules/Jobs/...` and hit `/crm/api/opcache-reset.php` afterward.
