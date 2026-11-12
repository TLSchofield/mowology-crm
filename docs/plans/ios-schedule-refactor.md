# iPhone schedule refactor (Tim, 2026-11-11: "refactor calendar carousel and schedule page"; go after calendar-drag merged)

Scope: iPhone only (`ios/MowologyCRM/.../Features/Schedule`). Behaviour identical, no design change. The web `schedule-pill-workflow.js` (4,393 lines) is a separate job after this.

## What the map found
- `ScheduleViewModel` (858 lines) is ONE `ObservableObject` with about 25 `@Published` values: selected date, week, stops, appointments, user location, loading/error/offline flags, scope, job-type and crew filters, crew trails and live positions, quiz gate, timeline input.
- Its only owner is `ScheduleView` (`@StateObject`, ScheduleView.swift:16). Every change to ANY of those values (a GPS fix, a polling tick, `lastFetched`, crew live positions) re-evaluates `ScheduleView.body`, which holds the carousel, list, map and bar clearance. That is the likely cost behind "slow / redraw loops" on this screen. Unproven until measured with `RenderRate`.
- Largest files: ScheduleViewModel 858, ScheduleTimelineViews 723, DayListView 715, ScheduleView 494, DayCarouselView 366. `DayCarouselRules`, `ScheduleTimelineRules` and presentation rules already have logic tests (`DayCarouselTests`, `ScheduleTimelineTests`, `SchedulePresentationTests`).

## Steps (each ends with logic tests + whole-app typecheck + Codex review; one TestFlight build at the end, with Tim's go)
1. Pin today's behaviour: extend the three logic-test files for what the view model decides (scope/filter persistence keys `mw_sched_scope|service|crews`, week navigation, day-rollover, nearest-first ordering, cache keys).
2. Measure first: log `RenderRate` per ScheduleView body during a normal day (needs the freeze build's alarm at 10 per 2 s) so the split targets real cost.
3. Split the view model by rate of change: filters (scope/jobType/crews), live data (crewLive, crewRoutes, userLocation), and day data (stops, appointments, week). Views observe only what they draw.
4. Make `DayCarouselView` a plain view over day data (it is already `Equatable`).
5. Split `DayListView` and `ScheduleTimelineViews` along their rules files.

Not in scope: Add Job, visit detail, photos, the web schedule, any visual change.
