# Mowology CRM — Shared Frontend Component Catalog

Reference catalog of shared, reusable JS/CSS UI components under `public/crm/js/`. This is the frontend equivalent of `/crm/includes/functions.php` for the backend — one place to check before writing new UI interaction code, so components don't get silently reimplemented. **CLAUDE.md requires checking this file before writing any new shared UI JS.** Page-specific feature modules (e.g. `offline-receipts.js`, `media-uploader.js`) are intentionally excluded — this catalog is for generic, drop-in components only.

---

## UI Feedback

### mwToast
- **File:** `public/crm/js/mw-toast.js`
- **Purpose:** Centered, styled toast notification that replaces native `alert()`.
- **Usage:**
  ```js
  mwToast('Saved successfully');                   // default (success)
  mwToast('Something went wrong', 'error');        // error variant
  mwToast('Heads up', 'warning');                  // warning
  mwToast('FYI', 'info');                          // info
  mwToast('Done', 'success', 5000);                // custom duration (ms)
  ```
- **When to reach for it:** Any user-facing success/error/info message. Existing `alert()` calls are auto-routed through this, but call `mwToast()` directly in new code.
- Auto-loaded globally via `appstack_footer.php`.

### MwApiToast
- **File:** `public/crm/js/mw-api-toast.js`
- **Purpose:** Renders a severity-matched toast (with optional inline "Try again" button) from the structured API error shape `{ success: false, error, code, retryable }`.
- **Usage:**
  ```js
  fetch('/crm/api/pow-actions.php', ...)
      .then(function (r) { return r.json(); })
      .then(function (data) {
          if (!data.success) {
              MwApiToast.show(data, function () { retryTheSameCall(); });
              return;
          }
          // success path
      });
  ```
- **When to reach for it:** Handling any JSON API response that follows the structured error contract, especially when a retry action makes sense. Falls back to `mwToast()` (or `alert()`) for legacy handlers that don't return the structured shape.
- **Not auto-loaded** — no page currently includes it. Add `<script src="/crm/js/mw-api-toast.js">` explicitly.

### MwHaptics
- **File:** `public/crm/js/mw-haptics.js`
- **Purpose:** Thin wrapper over Capacitor Haptics (native Android) + `navigator.vibrate` (web fallback); no-ops safely where neither is available.
- **Usage:**
  ```html
  <!-- zero-JS: fires automatically on click -->
  <button data-haptic="tap">Save</button>
  ```
  ```js
  // or call directly
  MwHaptics.success(); // tap() | success() | warning() | error() | selection()
  ```
- **When to reach for it:** Any button press, confirmation, or validation error on mobile/crew-facing pages where physical feedback improves the experience.
- **Not auto-loaded** — currently included explicitly on `driver-log.php`, `driver-log-post.php`, `app-launch.php`, `homebase.php`. Add a `<script src="/crm/js/mw-haptics.js">` tag on new pages that need it.

### MwSyncStatus
- **File:** `public/crm/js/mw-sync-status.js`
- **Purpose:** Self-mounting offline banner + pending-sync-count badge, polling IndexedDB queues (receipts, photo-queue, actions) every 8s.
- **Usage:** No JS call needed — include the script and matching CSS; it self-mounts on load.
  ```html
  <link href="/crm/css/mw-sync-status.css" rel="stylesheet">
  <script src="/crm/js/mw-sync-status.js" defer></script>
  ```
- **When to reach for it:** Any page where crew may go offline and needs a visible "you're offline" / "N items pending sync" indicator.
- **Not auto-loaded** — currently included explicitly on `driver-log.php`, `driver-log-post.php`, `app-launch.php`, `homebase.php`.

### Pull-to-refresh
- **File:** `public/crm/js/mw-pull-refresh.js`
- **Purpose:** Zero-dependency, native-feeling pull-to-refresh gesture on a scrollable container; triggers `window.location.reload()` past an 80px pull threshold.
- **Usage:** No JS call — self-installs against `.mw-mc-container`.
  ```html
  <div class="mw-mc-container">...</div>
  ```
- **When to reach for it:** A mobile list/schedule view that should support a native pull-to-refresh gesture instead of a manual refresh button. Note it's hardcoded to the `.mw-mc-container` selector — check the file before reusing on a container with a different class.
- **Not auto-loaded** and currently has no page include — add `<script src="/crm/js/mw-pull-refresh.js">` explicitly.

### MwPullForward ("Booked another day — doing it now?")
- **Files:** `public/crm/js/mw-pull-forward.js`, `public/crm/css/mw-pull-forward.css`; endpoint `/crm/api/visit-pull-forward.php` (`?mode=offer|accept|dryrun`); rules in `app/Modules/Jobs/Services/VisitPullForwardService.php`.
- **Purpose:** On a field device at a client property with no open visit today, offers that property's nearest visit (overdue first, then upcoming, ±7 days) as a bottom sheet: Start (moves it to today, then starts the timer via `job-timer.php`), A different job here, Extra work (`field-job.php` add_visit), Not now (quiet for that property today on this phone).
- **OFF by default** (2026-10-08 incident — the first release cost Android crew every tap). The `<script>` is only emitted when `ops_settings.pull_forward_enabled = '1'` or the user is in `pull_forward_user_ids` (migration 1276); the endpoint checks the same switch.
- **Usage:** No JS call — self-boots on field devices (a check 5 s after page open, then every 3 min while visible). It never calls `navigator.geolocation` (in the Capacitor app that runs Android's permission flow); the server uses the user's own recent `crew_location_history` pings. `MwPullForward.show(offer)` renders an offer without network. Set `window.MW_PULL_FORWARD_NO_BOOT = true` before loading to stop the automatic checks.
- **Safety rules:** nothing in the DOM unless a sheet is visibly open (created open, no animation-frame step), z-index 1090 above every app shell, × close always works, 20 s request watchdog, closed when the page hides.
- **CSRF:** uses `window.MW_CSRF_TOKEN` or `[data-csrf]`, else refreshes from `/crm/api/get-csrf.php` and retries — safe on crew pages that have no AppStack token.
- **Loaded on:** every AppStack page (`appstack_head.php` CSS, `appstack_footer.php` JS — gated) and `homebase.php` (gated). The accept POST is on `offline-queue.js`.

---

### Status scale (no purple, no yellow — Tim 2026-10-13)
One scale for every state in the CRM, all text pairs WCAG AAA (≥7:1). Tokens in `mowology-brand.css`
(`--mw-st-ok|warn|bad|off` with `-bg` and `-line`); warning tokens in `tokens.css` are rust.
- green = in / balanced / done · rust = needs a look · red = missing / wrong · grey = not expected
- Always pair the colour with a word or mark (✓, ≠, ✕), never colour alone.
- AppStack's `secondary` (lavender) and `warning` (yellow) classes are remapped CRM-wide to neutral ink
  and rust, so `btn-outline-secondary`, `badge bg-warning` etc. are safe to keep using. Never add a new
  purple or yellow hex; use the tokens.
- Bootstrap 5 `gap-*` and `bg-opacity-10/25` are defined in brand CSS (AppStack is Bootstrap 4).
- Accounts pages get `body.mw-books` (the ledger look) automatically from `appstack_head.php` when
  `$activePage = 'accounting'`. Statements check month cells use `.mw-sc-m.is-in|is-quiet|is-gap|is-empty|is-none`.

## Form / Input Components

### MwDatePicker
- **File:** `public/crm/js/mw-datepicker.js`
- **Purpose:** Branded date (or week) picker that replaces the native `<input type="date">` UI, driven by `data-*` attributes on a trigger button.
- **Usage:**
  ```html
  <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#due_date">
      <svg class="mw-datepicker-cal-icon">...</svg>
      <span class="mw-datepicker-date" data-mw-dp-label></span>
      <svg class="mw-datepicker-chevron">...</svg>
  </button>
  <input type="date" id="due_date" name="due_date" hidden>
  <script>new MwDatePicker(document.querySelector('.mw-datepicker-trigger'));</script>
  ```
- **When to reach for it:** Any form field or filter needing a date or week-range picker. Use this instead of the raw `<input type="date">` or a new custom picker.
- Auto-loaded globally via `appstack_footer.php`; auto-initializes every `.mw-datepicker-trigger` on `DOMContentLoaded`. Call `window.mwInitDatePickers()` after injecting new triggers dynamically; only call `new MwDatePicker(...)` directly for elements added after that initial pass.

### Searchable select
- **File:** `public/crm/js/mw-searchable-select.js`
- **Purpose:** Progressive enhancement that turns any `<select>` into a filterable/typeahead combobox while keeping the native element (and its form submission) intact.
- **Usage:**
  ```html
  <select class="mw-searchable" data-placeholder="Search…" data-none-label="— none —">
    <option value="">— none —</option>
    <option value="42">Jane Doe (jane@example.com)</option>
  </select>
  ```
- **When to reach for it:** Any `<select>` with enough options that scrolling a native dropdown is painful (clients, contacts, products, properties).
- Auto-loaded globally via `appstack_footer.php`; runs on `DOMContentLoaded`. Call `window.mwEnhanceSearchableSelects()` after injecting new `<select>` elements dynamically.

---

### MwServicePicker ("Add from services")
- **File:** `public/crm/js/mw-service-picker.js` (load per page; not in the global footer)
- **Purpose:** Searchable dropdown of the product/service catalog that hands the picked service to your line-item table. Reuses the quote builder's `.mw-template-*` styles.
- **Usage:**
  ```html
  <div id="invServicePicker"></div>
  <script>
  MwServicePicker.attach(document.getElementById('invServicePicker'), catalog, function (svc) {
      addRow({ title: svc.name, description: svc.description, unit_price: svc.base_price });
  });
  </script>
  ```
  `catalog` comes from `InvoiceLineItems::catalog($db)` (`app/Modules/Invoices/Services/InvoiceLineItems.php`), which also parses the posted `li_*[]` rows (`fromPost()`).
- **Used by:** `invoices/create.php`, `invoices/edit.php`. `quotes/create.php` still has its own older copy inline.

### MwUnbilledWork ("Also unbilled at this address")
- **File:** `public/crm/js/mw-unbilled-work.js` (load per page) · styles `.mw-unbilled-*` in `mowology-brand.css` · data from `/crm/api/unbilled-work.php?mode=list` (server: `UnbilledWorkFinder`, `app/Modules/Invoices/Services/UnbilledWorkFinder.php`).
- **Purpose:** While an invoice is being raised, list other unbilled work at the same property (last 60 days): completed-not-invoiced visits (pre-ticked when nothing looks off), skipped/scheduled visits with a job timer or Otto evidence ("possibly done", never pre-ticked, admin/manager only), and completed visits priced $0 (need a price). Each line carries its service date and the evidence.
- **Usage:**
  ```js
  MwUnbilledWork.fetch({ visit_id: 2500 }).then(function (data) {
      var ctl = MwUnbilledWork.render(hostEl, data, { onToggle: function (item, checked, amount) { … } });
      // or a confirm dialog: MwUnbilledWork.prompt(data).then(function (sel) { /* null = cancelled */ });
  });
  ```
  Post the ticked `{visit_id, amount}` list to the saving endpoint, which must call `UnbilledWorkFinder::claim()` **inside its invoice transaction** (it re-checks under lock and throws `UnbilledWorkConflict`; roll back on it).
- **Used by:** `invoices/create.php` (inline panel above the line items; rows carry `li_visit_id[]`, `li_service_date[]`, `unbilled_visit_ids[]`), `jobs/schedule.php` day view "Complete & Invoice" (`schedule-day-map.js` → `pow-actions.php complete_stop` `extra_visits`). iOS has its own SwiftUI version in the Complete Visit sheet (`schedule/invoice` action `unbilled`).

---

## Layout & Navigation

### Card Layout Manager
- **File:** `public/crm/js/mw-layout-manager.js`
- **Purpose:** Lets admins drag-and-drop and resize dashboard/detail-page cards; persists layout per-page to `localStorage`.
- **Usage:** No JS call needed — it self-boots on `DOMContentLoaded`, detecting `.row > .col-md-*` cards inside `main.content > .container-fluid`.
- **When to reach for it:** Any admin-facing page built from AppStack card components where letting the admin rearrange/resize sections is useful. It's gated on `window.MW_USER_ROLE === 'admin'` — no-op for other roles.
- Auto-loaded globally via `appstack_footer.php` (deferred).

### Keyboard table + drill-down in place (mw-ledger.js)
- **File:** `public/crm/js/mw-ledger.js` (styles: "Ledger workspace" section of `mowology-brand.css`, `.mw-ledger-*`). Added 2026-10-13.
- **Purpose:** dense data tables you drive from the keyboard: `/` focuses `[data-mw-ledger-search]`, `j`/`k` move between rows, `Enter` opens the row's detail under it (fetched, not a page load), `Esc` closes it.
- **Usage:** `<table data-mw-keytable data-mw-detail-url="/crm/ledger/entry.php?id=">` with `<tr class="mw-ledger-row" tabindex="0" data-id="N">`; the detail URL gets `N&part=1` and returns an HTML fragment. Links inside a detail with `data-mw-entry="M"` load entry M into the same panel. Also wires `select[data-mw-autosubmit]`, `[data-mw-confirm="…"]` and `[data-mw-copy="#input"]` (no inline handlers).
- Used by every Ledger page (`public/crm/ledger/*`). Reuse it for any office table that needs scanning and drill-down; it is not crew UI.
- A table with no detail URL whose rows carry `data-mw-row-href` opens that page on `Enter`/click (trial balance → account ledger). A fetched detail answering 401 (or redirected) sends the browser to the login page.

### XLSX writer + report exports (XlsxWriter, LedgerExport tables)
- **Files:** `app/Services/Spreadsheet/XlsxWriter.php` (no Composer: ZipArchive + OOXML, the writing half of `XlsxReader`); `app/Modules/Ledger/Services/LedgerExport.php` (`sendTable()`). Added 2026-10-13.
- **Purpose:** one table shape → XLSX / CSV / PDF with a header block (entity, report, period, lock status, run time), numbers as numbers, real Excel dates, frozen heading row, no merged cells. PDF via mPDF (`public/vendor`). There is no PhpSpreadsheet in this codebase — use this.
- **Usage:** `LedgerExport::sendTable($db, ['title'=>…, 'period'=>…, 'from'=>…, 'to'=>…, 'columns'=>[[label, 'text'|'money'|'int'|'date', width]], 'rows'=>[…]], 'xlsx', 'basename', 'what')` — logs `ledger.export`. Streamed CSV for big files: `LedgerExport::csvStream()` + `csvLine()`.

### Global Search (Spotlight)
- **File:** `public/crm/js/global-search.js`
- **Purpose:** ⌘K / Ctrl+K command-palette search across contacts, companies, properties, quotes, jobs, invoices, and team.
- **Usage:** No JS call needed — binds to the markup already rendered by `appstack_topbar.php` (`#mwSpotlight`, `#mwSpotlightInput`, `#mwSpotlightBody`), which every AppStack page gets automatically.
- **When to reach for it:** You don't invoke this — it's already live on every CRM page. Extend `categoryMeta` in the file if you add a new searchable entity type.
- Auto-loaded globally via `appstack_head.php`.

### Field Search (crew Search tab)
- **Files:** `public/crm/search.php`, `public/crm/js/mw-field-search.js`, `public/crm/api/field-search.php`; service `app/Modules/Jobs/Services/FieldSearchService.php` (shared with the iOS endpoint `/api/schedule/search`).
- **Purpose:** The crew's "find anything": one box over client, company, building, address, postal code, phone and plan/visit number. Every hit is a PROPERTY, ranked today's-stops-first then nearest, with a property page (Navigate / Call / Today's visit tiles, plans with the two-week history grid and "Add visit", upcoming visits). No prices, invoices or crew names.
- **When to reach for it:** Anything crew-facing that needs to look a property up. This is NOT Spotlight (below): Spotlight is the office's ⌘K palette across quotes/invoices, ranked alphabetically. Don't build a third search.

### Mobile bottom bar — five tabs, one order
- **Files:** `public/crm/includes/mobile-nav.php` (every AppStack page), plus the same five inside `public/crm/jobs/schedule.php` (`.mw-mc-bottombar`) and `public/crm/homebase.php` (`.hb-nav`).
- **Purpose:** Schedule · Time Clock · Search · Receipts · Account — identical to the iOS tab bar. Account opens the slide-up menu (everything else: clients, vehicle log, settings, sign out).
- **When to reach for it:** Never add a tab to one bar only. If the iOS tabs change, change all three.

### MwNavLauncher
- **File:** `public/crm/js/navigation-launcher.js`
- **Purpose:** Builds Google Maps navigation URLs/intents and launches turn-by-turn navigation, using Capacitor `App.openUrl()` on Android or `window.open()` on web.
- **Usage:**
  ```js
  MwNavLauncher.launchNavigation(nextStop, navOptions);       // single stop
  MwNavLauncher.launchMultiStopNavigation(stops);              // ordered multi-stop route
  MwNavLauncher.buildNavigationUrl(stop);                      // URL only, no launch
  ```
- **When to reach for it:** Any "navigate to this job/stop" button. Prefer this over hand-rolling a Google Maps URL — it already handles lat/lng vs. address fallback and the Android intent vs. web-open split.
- **Not auto-loaded** — currently included explicitly on `public/crm/jobs/schedule.php`. Add a `<script src="/crm/js/navigation-launcher.js">` tag where needed.

---

## Platform / Data

### MwApi
- **File:** `public/crm/js/mw-api.js`
- **Purpose:** `fetch()` POST wrapper for JSON API endpoints — injects CSRF token + idempotency key, throws on non-2xx, returns parsed JSON.
- **Usage:**
  ```js
  MwApi.setToken(csrfToken);   // once on init, again after token refresh
  MwApi.post('/crm/api/job-timer.php', { action: 'start', visit_id: 42 })
      .then(function (data) {
          if (data.success) { /* ... */ } else { showToast(data.error); }
      })
      .catch(function (err) { console.error(err); showToast('Network error.'); });
  ```
- **When to reach for it:** Any new POST call to a JSON CRM API endpoint that needs CSRF + idempotency handled consistently, instead of hand-writing `fetch()` boilerplate. Not for `FormData` uploads or GET requests — those are out of scope by design.
- **Not auto-loaded** and has no current call sites in the codebase — it's ready-to-use infra. Add `<script src="/crm/js/mw-api.js">` and call `MwApi.setToken(...)` on init.

### Nonce CSP (MwCsp) + inline-handler replacement (MwCspActions)
- **Files:** `app/Core/Csp.php` (PHP), `public/assets/js/mw-csp-actions.js` (JS). Added 2026-10-12 (Fable #22).
- **Purpose:** the public site (`public/includes/head.php` pages, CMS blocks, `/services/*`) and the customer portal (`public/customer/*`) send a per-request nonce CSP with no `'unsafe-inline'`/`'unsafe-eval'` and `script-src-attr 'none'`. `public/.htaccess` skips its lax header for those URLs (`MW_NONCE_CSP`).
- **Usage (PHP):** `MwCsp::send();` before output (head.php and every customer page already do); every inline script is `<script<?= MwCsp::attr() ?>>`. JSON-LD needs nothing.
- **Usage (HTML):** no `onclick=`/`onerror=`/`onsubmit=`. Use `data-mw-call="fnName" data-mw-args='["x","$this"]'` (`$this`, `$event`, `$checked`; `data-mw-on="change|submit"`), `data-mw-toggle="elementId"`, `data-mw-print`, `img data-mw-hide-on-error="N"`, and load `<script src="/assets/js/mw-csp-actions.js"></script>`.
- **Check:** `scripts/csp-check.py http://localhost:8000 / /about /customer/portal.php?token=…` (rendered pages); `tests/Unit/Security/PublicCspTest.php` (source).
- Third-party libraries for these pages are served from `public/assets/vendor/<lib>/` with their LICENSE (Leaflet, Signature Pad) — never unpkg.
- **CRM (AppStack) is NOT on this policy yet** — it still relies on inline scripts and handlers.

### Capacitor Bridge (MwNative)
- **File:** `public/crm/js/capacitor-bridge.js`
- **Purpose:** Self-guarding bridge that exposes `window.MwNative` (background GPS, tracking, local notifications, FCM push registration, network status) only when running inside the native Android Capacitor shell; zero-cost no-op in a browser.
- **Usage:**
  ```js
  if (window.MwNative && window.MwNative.geo) {
      window.MwNative.geo.startBackgroundTracking(function (pos, error) { /* ... */ });
  }
  if (window.MwNative && window.MwNative.notifications) {
      window.MwNative.notifications.notify(title, msg, jobId);
  }
  ```
- **`MwNative.push`** (added 2026-08-12): registers this device for Firebase Cloud Messaging and POSTs the resulting token to `/api/device/token` (same endpoint the iOS app's `DeviceTokenService` uses, `platform: 'android'`) so `PushDispatcher`/`FcmService` can address it server-side. Self-inits at the bottom of the guarded IIFE and re-registers (no re-prompt) on every Capacitor `App` `resume` event. Inert — logs and no-ops — until a real Firebase project + `google-services.json` is provisioned in the Android repo; see `app/Services/Push/FcmService.php`.
- **When to reach for it:** Any feature that needs native GPS tracking, local or push notifications, or native network status — always guard with `window.MwNative && window.MwNative.xxx` since it's undefined on web.
- Auto-loaded globally via `appstack_footer.php`.

### Service Worker registration
- **File:** `public/crm/js/sw-register.js`
- **Purpose:** Registers `/service-worker.php`, cleans up legacy service-worker registrations, and reloads the page when a new SW takes over.
- **Usage:** No JS call needed — self-running IIFE.
- **When to reach for it:** You shouldn't need to touch this directly. Relevant if you're debugging stale-cache issues or adding a new standalone (non-AppStack) page that needs the service worker — include the script tag there.
- Auto-loaded on every AppStack page via `appstack_footer.php`, and included directly on standalone driver pages (`app-launch.php`, `homebase.php`, `driver-log.php`, `driver-log-post.php`).

### Feather icon hydration
- **File:** `public/crm/js/feather-helper.js`
- **Purpose:** Safely calls `feather.replace()`, guarding against "feather is not defined" errors and replacing unknown icon names with a fallback instead of crashing.
- **Usage:**
  ```js
  hydrateFeatherIcons();           // hydrate entire document
  hydrateFeatherIcons(container);  // hydrate a specific DOM subtree (e.g. after an AJAX render)
  isFeatherAvailable();            // check if Feather is loaded
  ```
- **When to reach for it:** Any time you inject new HTML containing `data-feather="..."` icons via JS (modals, AJAX-rendered rows, dynamic cards) — call `hydrateFeatherIcons(container)` afterward instead of calling `feather.replace()` directly.
- Auto-loaded globally via `appstack_footer.php` (runs once automatically on `DOMContentLoaded`).

---

## Offline & Media

### OfflineActions (offline queue)
- **File:** `public/crm/js/offline-queue.js`
- **Purpose:** Intercepts `fetch()` POST calls to critical endpoints (`time-clock.php`, `pow-actions.php`, `job-timer.php`) when offline, stores them in IndexedDB, and auto-replays them on reconnect (Background Sync where supported, `online`/`visibilitychange`/`focus` events otherwise).
- **Usage:** Fully automatic — no manual init required for the three queued endpoints.
  ```js
  window.OfflineActions.syncNow();         // manually trigger replay
  window.OfflineActions.getPendingCount(); // Promise<number>
  window.OfflineActions.updateUI();        // refresh badge + strip
  ```
- **When to reach for it:** A new mutation endpoint that crew may call while offline in the field. Add it to the `QUEUED_ENDPOINTS` list in this file rather than building a separate offline-queueing mechanism.
- Auto-loaded globally via `appstack_head.php` (deferred), so queued actions from any page can sync on the next page load.

### MwTripLog (vehicle log: per-shift driver question + server-first/backup filing)
**File:** `/crm/js/mw-trip-log.js` — loaded on every AppStack page (footer) and on the standalone crew pages (`app-launch.php`, `homebase.php`, `driver-log.php`, `driver-log-post.php`). Needs `window.MW_USER_ID`.

The commercial vehicle trip log is a legal requirement of **whoever drives that shift** — not of users flagged `is_driver`. Use this, never a new fetch to `trip-report.php`:

```js
// After a successful clock-in — asks "Are you driving a company vehicle this shift?" once if needed
MwTripLog.afterClockIn(clockInResponse, MW_USER_ID).then(url => { if (url) location.href = url; });

// File an entry. SERVER FIRST; the phone is only the BACKUP when it can't be reached.
MwTripLog.submit('save_pre_trip' | 'save_post_trip' | 'declare', fields, MW_USER_ID).then(r => {
  // r.status: 'filed' (server has it) | 'queued' (no signal — saved on this phone, files itself
  //           later under the time it was DONE) | 'rejected' (r.message, r.code — fix on the form)
});
```
- Do **not** make this queue-first: the driver must see a real "filed", and server objections (e.g. `code: 'needs_confirmation'` for an odd odometer) must surface while they are still on the form.
- Queue is per-user, ordered, survives sign-out, flushes on load / `online` / tab visible. A server refusal of a queued entry is kept and surfaced via the `mw-trip-log-changed` event — never dropped.
- Server rules live in `app/Modules/Driver/Services/TripReportService.php` (shared with the iOS app): `shiftState()` = `driving | not_driving | unasked`, bounded `performed_at`, idempotent replays.
- Differs on purpose from `OfflineActions`, which is queue-and-forget for clock/timer POSTs.

### Break button (MwTimeClock.startBreak / endBreak, #mwBreakPanel)
**File:** `public/crm/js/time-clock-widget.js` (loaded on every AppStack page as `time-clock-widget.min.js` — regenerate the .min and bump its `?v=` in `appstack_footer.php`). Server: `BreakService` via `time-clock.php` `break_start` / `break_end`.
- The topbar clock widget shows **Break** / **End break** for people (status `breaks.allowed`; never the truck login).
- Put `<div class="mw-break-panel" id="mwBreakPanel" hidden></div>` on a crew page to get the big Start break / On break since 12:30 / End break card (My Timesheet has one).
- `MwTimeClock.startBreak()`, `MwTimeClock.endBreak()`, `MwTimeClock.isOnBreak()`.
- Pauses location sharing while on break; "Still on break?" via Capacitor LocalNotifications actions in the app, an in-page prompt in a browser. Taps carry `client_time`, so `OfflineActions` replays file at the tap time.
- iPhone equivalent: `BreakController` (`ios/.../Features/TimeClock/TimeClockViewModel.swift`).

### triggerLabelCamera (product / machine label photo)
- **File:** `public/crm/js/label-capture.js` — loaded by `expenses_appstack.php` (the **Label** chip in the mobile capture row).
- **Purpose:** photo of a product bag or a machine nameplate → `/crm/api/label-products.php` (`mode=capture`). Same upload + OCR as a receipt, **never an expense**; Penny (product) or Otto (machine) proposes it on the dashboard. Shrinks to 2000 px, attaches location, answers with `mwToast`.
- **Usage:** `<button onclick="triggerLabelCamera()">Label</button>` on any AppStack page that has the CSRF meta / `MW_CSRF_TOKEN`. iOS uses `/api/expenses/label-upload` (JWT).
- The proposal cards are `penny-products.js` (`#mw-pp`) and `otto-care.js` (`#mw-otto-care`, via `includes/otto-care.php`).

### MwPhotoQueue (photo queue)
- **File:** `public/crm/js/photo-queue.js`
- **Purpose:** Durable photo storage + upload queue engine — saves image bytes to Capacitor Filesystem (native) or IndexedDB (browser/PWA), tracks upload status in IndexedDB, and runs a retrying background uploader.
- **Usage:**
  ```js
  MwPhotoQueue.saveAndEnqueue(file, meta).then(function (queueId) { /* ... */ });
  MwPhotoQueue.processQueue();
  MwPhotoQueue.getPendingCount().then(function (n) { /* ... */ });
  MwPhotoQueue.on('mwPhotoUploaded', function (e) { /* e.detail: { queueId, mediaId, uuid, thumbUrl } */ });
  ```
- **When to reach for it:** Any in-app photo capture flow that needs to survive being offline or backgrounded — don't hand-roll a new IndexedDB/Filesystem upload queue.
- Auto-loaded globally via `appstack_footer.php` (must load before any page-specific media-upload script).

### BatchCamera
- **File:** `public/crm/js/batch-camera.js`
- **Purpose:** In-app multi-shot camera overlay (Capacitor + PWA) that keeps the viewfinder open between shots so crew can snap several photos without the OS camera app closing each time.
- **Usage:**
  ```js
  var cam = new BatchCamera({
    maxPhotos : 10,
    onCapture : function (file, objectUrl) { /* fires on each snap */ },
    onDone    : function (count) { /* fires on Done tap */ },
    onCancel  : function (count) { /* fires on Cancel tap */ }
  });
  cam.open();
  ```
- **When to reach for it:** Any feature needing to capture multiple photos in one session (job visit photos, receipt batches) instead of repeatedly invoking the native single-shot file picker.
- **Not auto-loaded** — currently included explicitly on `public/crm/jobs/schedule.php`. Add `<script src="/crm/js/batch-camera.js">` where needed.
- **iOS counterpart:** `ios/MowologyCRM/MowologyCRM/Features/Camera/BatchCameraView.swift` (native AVFoundation; lens chips, flash, flip, pinch zoom, per-shot Before/During/After/More tag, `MarkupEditorView` flattens markup into the JPEG with EXIF kept). Used by `JobPhotoSection`; video mode exists but is gated by `BatchCameraFeatures.visitVideo` (off — server visit media is image-only). Pure logic checked by `bash ios/LogicTests/run.sh`.

### MwSpecialRequest (client special request on a visit — crew card + "read it first" gate)
- **File:** `public/crm/js/mw-special-request.js` — printed by `jobs/schedule.php` ONLY when `ops_settings.special_requests_enabled` applies to the user (`special_requests_user_ids` narrows it). Styles `.mw-sr-*` in `mowology-brand.css`.
- **Purpose:** shows a client's special request (Yui/Otto, the client's words, part-of-the-work vs "extra — decide on site") inline in the job card with Done / Not done / Extra work done, and gates Start / camera / gallery until this crew member taps "Got it".
- **Usage (synchronous, at the top of any Start / photo handler for a visit):**
  ```js
  if (window.MwSpecialRequest && window.MwSpecialRequest.blocks(visitId)) return; // screen shown instead
  // a 409 {code:'special_request_unacknowledged', special_requests:[…]} from the server:
  window.MwSpecialRequest.fromServer(visitId, data.special_requests);
  ```
- **Rules:** the full-screen element exists only while shown (created on the tap, removed on dismiss); no capture listeners, no geolocation; never chain the camera from "Got it" — the crew tap again. Server twin: `SpecialRequestGate::enforce()` (job-timer, `/api/schedule/timer`, pow-actions start/photo, media-upload, `/api/schedule/job-photo`, auto-arrival). Office panel: `includes/special-requests-panel.php` + `special-requests-panel.js` on Yui's and Otto's cards. iOS: `Features/Schedule/SpecialRequestCard.swift`.

### MwCameraPermission
- **File:** `public/crm/js/mw-camera-permission.js`
- **Purpose:** Guards Android Capacitor camera-capture buttons against the WebView's cryptic "Access denied" failure by checking/caching the camera permission state and showing an actionable "Open Settings" dialog.
- **Usage:**
  ```js
  if (window.MwCameraPermission && MwCameraPermission.isNativeAndroid()
      && MwCameraPermission.isDenied()) {
      MwCameraPermission.showDeniedDialog();
  }
  // refresh before wiring up a capture button:
  if (window.MwCameraPermission && MwCameraPermission.isNativeAndroid()) {
      MwCameraPermission.refresh(function (state) {
          if (state === 'denied') MwCameraPermission.showDeniedDialog();
      });
  }
  ```
- **When to reach for it:** Any page with an `<input type="file" capture="environment">` photo-capture button on the Android Capacitor app — check permission state first instead of letting the picker fail silently.
- Auto-loaded globally via `appstack_footer.php`.

---

## Server-Rendered Partials

### Seasonal Outlook Card
- **Renderer:** `public/crm/modules/weather/seasonal-outlook-card.php` — `renderSeasonalOutlookCard(array $outlook, string $variant)`
- **Data:** `app/Modules/Jobs/Services/SeasonalOutlookService.php` — `activeOutlook()` returns the payload or `null` when out of season.
- **CSS:** `mowology-brand.css` section 52 (`.mw-seasonal*`).
- **Purpose:** Renders the Nov–Mar winter outlook — expected frost nights, snow days and snowfall per month, each shown against its 28-winter YVR normal — so scheduling and snow/ice staffing have a season-level number, not just the 7-day forecast.
- **Variants:** `'compact'` (month strip, used on the dashboard) and `'full'` (month table + notes + sources, used on Weather Actions). Both render from the same array, so the two pages cannot drift apart.
- **Usage:**
  ```php
  require_once APP_ROOT . '/Modules/Jobs/Services/SeasonalOutlookService.php';
  require_once dirname(__DIR__) . '/modules/weather/seasonal-outlook-card.php';
  $outlook = (new SeasonalOutlookService(getDB()))->activeOutlook();
  if ($outlook) renderSeasonalOutlookCard($outlook, 'compact');
  ```
- **When to reach for it:** Any page that needs seasonal (multi-month) weather context. For "what is the weather on Thursday" use `WeatherService` instead — the two are deliberately separate so a probabilistic outlook is never read as a deterministic forecast.
- **Metrics:** two frost counts — `frost` (air frost, Tmin ≤ 0 °C, pipes/irrigation) and `ground_frost` (Tmin ≤ 4 °C, a proxy for turf frost, which is what delays mowing). Plus snow days, days ≥ 2 cm, and snowfall. Every projection is paired with its observed normal.
- **Auto-refresh:** the `seasonal_outlook_refresh` cron (daily) rebuilds the numbers via `SeasonalOutlookRefreshService` and adds season-to-date actuals. Two independent staleness states: **Review overdue** (`review_by` passed — a human should re-read the prose) and **Data N days old** (`isDataStale()` — the refresh cron has stopped, shown in red).
- **Updating for next season:** the cron handles the figures. For the prose, replace `SeasonalOutlookService::bundledOutlook()` or edit the `ops_settings` payload directly. No migration needed.
- **Not auto-loaded** — `require_once` both files on the page that renders it.

### Clues list (MwClues)
- **JS:** `public/crm/js/clues-card.js` — renders into `#mw-clues`; **API:** `/crm/api/clues.php` (`?mode=list`, POST `apply` / `dismiss`); **Service:** `app/Modules/Comms/Services/ClueService.php`.
- **CSS:** `mowology-brand.css` (`.mw-clue*`, next to Yui's `.mw-yui-*` rows it reuses).
- **Purpose:** suggestions Penny and Yui spotted in payments and mail (strata plan numbers, job titles and firms in signatures, missing phones/emails, an accountant set up as the quote signer), each with Apply / Not right. Nothing changes a record without that click; buttons only for admins.
- **Usage:** `<div class="mw-clues" id="mw-clues" data-contact-id="<?= (int)$contactId ?>"></div>` + the script. Leave out `data-contact-id` for every open clue (Yui's card does). Renders nothing when there are none, or before migration 1206.

### Split by line (MwExpenseSplit)
- **JS:** `public/crm/js/expense-split.js`; **CSS:** `mowology-brand.css` (`.mw-split*`); **Service:** `app/Modules/Expenses/Services/ExpenseSplitService.php` (migration 1233).
- **Purpose:** one receipt, several destinations — each line gets For (a job, 🏪 shop stock, or no job), a category and (on Fuel) the truck / equipment tag. Penny pre-fills it; the server works the money out (GST by net, PST only on the taxable lines) and shows the job cost per destination.
- **Usage:** `var state = MwExpenseSplit.render(el, data, { categories: [...], autoOn: true|false, state: previous, locked: bool })` with `data` from `/crm/api/expenses.php?action=split&id=N` (or Penny's queue item `.split`); send `MwExpenseSplit.value(state)` (null = send nothing) as `split` on `expenses.php` `update`, or `overrides.split` on Penny's `decide`. Both save through `ExpenseGate`.
- **Used by:** Penny's receipt card (`bookkeeper-card.js`, loads the script itself) and the expense edit modal (`expenses_appstack.php`, `#expSplitPanel`).

### Penny's read on a receipt (MwPennyRead)
- **JS:** `public/crm/js/penny-read.js`; **CSS:** `mowology-brand.css` (`.mw-penny-read*`); **Service:** `app/Modules/Expenses/Services/PennyReadService.php`.
- **Purpose:** Penny's pending suggestion for one receipt, only where it differs from the receipt — each field with her reason and confidence, her line items next to the receipt's, her notes — with "Use Penny's version" (applies everything through `BookkeeperDeskService::decide`; approvers also approve) and "Keep mine" (approvers). Renders nothing when she agrees or has no open suggestion.
- **Usage:** `<div id="expPennyRead" hidden></div>` + `MwPennyRead.load(el, expenseId, { csrf: CSRF, onDone: fn(res) })`; data from `/crm/api/expenses.php?action=penny_read&id=N`, POST `penny_apply` / `penny_keep`. The iPhone receipt detail draws the same data natively (`bookkeeper-mobile` `mode=suggestion`).
- **Used by:** the expense modal (`expenses_appstack.php`, loads the script on first use) — the receipt screen on web and in the Android app.

---

**Total: 22 components documented.**

## Penny's approve window — `MwPennyApprove` (2026-10-14)

`/crm/js/penny-approve.js` + `.mw-pa-*` in `mowology-brand.css`. `await MwPennyApprove.confirm(expenseId)`
→ `true` when the owner presses Approve. A large window with Penny's face and brain (head-brain.js,
`HeadBrain.mount()`), one line per field she read (right, or what you set instead), how many of the
vendor's other waiting receipts she'll redo, and her record there. Data: `expenses.php?action=approve_preview`
(BookkeeperDeskService::approvalPreview). The caller still does the approval; falls back to `confirm()`.
Use it for any approve-a-receipt button instead of a bare `confirm()`.


## iOS shared appearance — `ItAppStyle` (2026-10-18)

`ios/MowologyCRM/MowologyCRM/Core/Design/Ittai/ItAppStyle.swift` adapts the existing field screens to the frozen Ittai tokens. Apply `.itAppTheme(enabled:field:)` above the tabs; consume `@Environment(\.itAppStyle)` for semantic colours, Dynamic Type Inter fonts, corners and shadow suppression. `.itAppScreen()` styles native bars; `.itAppForm()` styles form backgrounds. Keep media/camera overlays readable on their actual imagery.

`ItDesignSettingsRows` shares the existing opt-in and field-mode preferences between crew Account and admin AI Office. The root modifier keeps the view subtree stable when toggled so active shifts, receipt edits and navigation state survive. Status colours are display-only: retain the server status and its visible label, and keep locally saved records distinct from server-confirmed records.

## iOS diagnostics — `AppDiagnostics`, `ErrorDetails`, `DiagnosticsView` (2026-10-18)

`Core/Diagnostics/` contains the shared persisted logger and exact error formatter.
Use `AppDiagnostics.shared.record(category:message:)` for local failures and
`ErrorDetails.describe(error)` for error text. Retain actual HTTP status/server
messages and decoding paths; do not infer “offline” from an arbitrary failure.
Never pass request headers/bodies, photos or coordinates. The logger redacts
secrets, rotates bounded files and exposes disk failures. `DiagnosticsView` guards
view/export by the current admin role. `AppCrashReports` imports Apple-supplied
MetricKit crashes/hangs; availability depends on iOS delivery. See `ios/README.md`
for storage, limits, access and test commands.
