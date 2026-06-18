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
│   └── VisitCompletionService.php
├── Api/                         ← thin JSON controllers (one endpoint per file)
└── Cron/                        ← scheduled batch jobs
```

### Services

| Service | Purpose |
|---------|---------|
| `PlanFunctions.php` + `Plan/*.php` | Plan → visit → calendar-stop engine. Procedural global functions. **See [schedule.md](schedule.md).** |
| `ClusterService.php` / `ClusterDetectionService.php` | Group nearby stops into geographic clusters for route building. |
| `VisitCompletionService.php` | Completion-side logic for finished visits. |

> Per CLAUDE.md rule 10, new business logic belongs in a service class, not in
> page/API files. The Plan engine predates that rule and stays procedural for
> now; the 2026-06-18 split into `Plan/*.php` was the first step toward
> extractable, testable services (facade-over-globals is the deferred Phase 2).

### API endpoints (`Api/`)

Each file is a thin controller that requires the plan-functions chain (or the
relevant service) and returns JSON. Current endpoints:

`api-jobs`, `assign-crew`, `calendar-stops`, `clusters`, `job-creation`,
`job-timer`, `optimize-route`, `placement-scores`, `profit-risk-factors`,
`reschedule-job`, `reschedule-job-simple`, `reschedule-stop`, `schedule-visit`,
`set-route-pin`, `weather-actions`.

`job-timer.php` is on the **revenue-critical completion path** — it must load
`plan-functions.php` so `updateVisitStatus()` is defined (see schedule.md).

### Cron (`Cron/`)

| Cron | Purpose |
|------|---------|
| `generate_visits.php` | Materialise `job_visits` ahead of the rolling horizon (calls `generateVisits()`). |
| `auto_rollover.php` | Roll incomplete/overdue visits forward. |
| `weather_schedule_guard.php` | Adjust the schedule around weather. |

Crons run under `/usr/local/bin/php`. Registration/health is tracked in the
registry-driven Cron Jobs tab — see `project_cron_manager_registry`.

## Testing & deployment

- Safety net: `tests/Unit/Jobs/PlanFunctionsLoadTest.php` (function presence +
  arity). Run `vendor/bin/phpunit` before every commit.
- `app/` is not in cPanel auto-deploy. Deploy module changes via `lftp` to
  `/app/Modules/Jobs/...` and hit `/crm/api/opcache-reset.php` afterward.
