# iPhone, Android and web — keeping Otto the same everywhere

## How the three relate
- **Web** (desktop + phone browser) — AppStack pages: Otto's card
  `public/crm/includes/otto-card.php` + `public/crm/js/otto-card.js`, schedule
  `public/crm/jobs/schedule.php`, `public/crm/ops/*`, `public/crm/timeclock/*`.
- **Android** (crew app, Capacitor) — shows the same web pages; a web change IS an Android change
  after deploy. Start URL is `public/crm/timeclock/my-schedule.php` (fresh login →
  `public/crm/app-launch.php` → `public/crm/homebase.php`; drivers → `public/crm/driver-portal.php`).
  Home Base and app-launch are standalone (no `MW_CSRF_TOKEN`). Native pieces (GPS engine,
  camera permission) live in `mowology-android`; APK version sync rules apply.
- **iPhone** (SwiftUI, `ios/MowologyCRM`) — native screens on JWT endpoints:
  `app/Modules/Schedule/Api/*` (day, clock, timer, location, tracking, trip-report) and
  `app/Modules/Team/Api/team-mobile.php` (Otto's items + `otto_decide`).

## The rules
1. **Logic lives in one server service** (SKILL.md "One place for each job"). Endpoints and pages
   only call it.
2. **Every Otto feature has a row below.** Change one surface → change the others in the same
   piece of work, or write the gap here.
3. **iPhone needs a build** (bump MARKETING_VERSION, push `feature/language` → TestFlight);
   web/Android need a deploy (lftp, OPcache reset). Server first, then the app that relies on it.
4. **Anything loaded on every crew page or shown at app root can stop the field workflow**
   (2026-10-08). New crew-page features ship behind an `ops_settings` flag, off by default.

## Parity table (checked 2026-10-11)

| Feature | iPhone | Web / Android | Server (one place) | Gap |
|---|---|---|---|---|
| Otto's suggestions + buttons | Team tab `OttoSuggestionViews.swift` (per-kind for older kinds; `serverButtons` for new) | `otto-card.js` (per-kind controls) + `propose.buttons` | `OpsDeskService`, `OttoActionService` | older kinds' buttons written twice; iPhone sends weather slot / bylaw start time to the web |
| Kinds iPhone handles that this branch does not emit (`unscheduled`, `extra_work`, `visit_date`, `duration`, `pin_off`, borders, `no_pin`) | yes | unverified | not in `OpsDeskService::KINDS` here | likely on another branch / production — check live before relying on it |
| Charlie's brief lines from Otto | Team tab brief | dept-heads deck | `OpsDeskService::brief()` | — |
| Clock in/out | native, offline `ClockQueue.swift` | `time-clock-widget.js` → `time-clock.php` | `TimeclockFunctions.php` | — |
| GPS tracking | `GPSTrackingService.swift` (native) | native engine via `capacitor-bridge.js` | `TrackingIngestService` | consent screen not on both apps → consent switch OFF |
| Break button | none | none | `BreakService` (GPS only) | both — needed before break flags go on |
| Driver pre/post-trip | `DriverTripViews.swift` | web form | `trip-report.php` / web | — |
| Snow route service choice | `VisitCompletionSheet.swift` | invoice sheet asks "What was done here?" | `SnowContractService` | — |
| Special requests on arrival | `SpecialRequestStore.swift` | panel + gate | `SpecialRequestService`, `SpecialRequestGate` | flags OFF |
| Trips / dump runs, equipment, bylaws, supplier directory | none | web only | `TripCostService`, `EquipmentService`, `MunicipalRuleService`, `SupplierDirectoryService` | office screens; iPhone not planned |
| Supplier form send | server-sent buttons | server-sent buttons | `SupplierFormService` | — |

When you change a row, change the date in this file's heading.
