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

## Parity table (checked 2026-10-13, break button added)

| Feature | iPhone | Web / Android | Server (one place) | Gap |
|---|---|---|---|---|
| Otto's suggestions + buttons | Team tab `OttoSuggestionViews.swift` (per-kind for older kinds; `serverButtons` for new) | `otto-card.js` (per-kind controls) + `propose.buttons` | `OpsDeskService`, `OttoActionService` | older kinds' buttons written twice; iPhone sends weather slot / bylaw start time to the web |
| Kinds iPhone handles that this branch does not emit (`unscheduled`, `extra_work`, `visit_date`, `duration`, `pin_off`, borders, `no_pin`) | yes | unverified | not in `OpsDeskService::KINDS` here | likely on another branch / production — check live before relying on it |
| Charlie's brief lines from Otto | Team tab brief | dept-heads deck | `OpsDeskService::brief()` | — |
| Clock in/out | native, offline `ClockQueue.swift` | `time-clock-widget.js` → `time-clock.php` | `TimeclockFunctions.php` | — |
| GPS tracking | `GPSTrackingService.swift` (native) | native engine via `capacitor-bridge.js` | `TrackingIngestService` | consent screen not on both apps → consent switch OFF |
| Break button (Start break / End break, "Still on break?") | `BreakController` in `TimeClockViewModel.swift` + break card in `TimeClockView.swift`; queued in `ClockQueue`; local notification with End break / Still on break actions (`NotificationRouter`) | `time-clock-widget.js` (topbar Break button + `#mwBreakPanel` on My Timesheet); queued by `offline-queue.js` with `client_time`; Capacitor LocalNotifications actions (in-page prompt in a browser) | `BreakService::start()` / `BreakService::end()` via `time-clock.php` and `clock.php`; GPS paused by `TrackingIngestService::policy()` (`on_break`) | Home Base (`homebase.php`, standalone, prod ahead of repo) has no break button; the Android notification action only lands on a page that loads the widget |
| Driver pre/post-trip | `DriverTripViews.swift` | web form | `trip-report.php` / web | — |
| Snow route service choice | `VisitCompletionSheet.swift` | invoice sheet asks "What was done here?" | `SnowContractService` | — |
| Special requests on arrival | `SpecialRequestStore.swift` | panel + gate | `SpecialRequestService`, `SpecialRequestGate` | flags OFF |
| Trips / dump runs, equipment register, bylaws, supplier directory | none | web only | `TripCostService`, `EquipmentService`, `MunicipalRuleService`, `SupplierDirectoryService` | office screens; iPhone not planned |
| Add equipment (snap the plate → confirm form) | `EquipmentAddView.swift` from Otto's card on the Team tab (camera / library, prefilled form, receipt pick) | `equipment.php` "Add equipment" card + `ops-equipment.js` (Android: same page, file input with camera) | `EquipmentService::readPlate()` / `EquipmentService::addFromPlate()` | iPhone has no list import, machine page, parts or manual reading — those are web (`equipment-item.php`) |
| End-of-day missed-visit review | `OttoMissedReviewViews.swift` (Otto's card entry + sheet; opens from the push `open: otto_review`) | `otto-missed.js` in Otto's dashboard card + `/crm/ops/missed-visits.php` (Android opens the push's `data.url`) | `MissedVisitReviewService`, `MissedVisitRules` | iPhone date = SwiftUI graphical picker, web = MwDatePicker |
| Supplier form send | server-sent buttons | server-sent buttons | `SupplierFormService` | — |

When you change a row, change the date in this file's heading.
