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

---

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

## Layout & Navigation

### Card Layout Manager
- **File:** `public/crm/js/mw-layout-manager.js`
- **Purpose:** Lets admins drag-and-drop and resize dashboard/detail-page cards; persists layout per-page to `localStorage`.
- **Usage:** No JS call needed — it self-boots on `DOMContentLoaded`, detecting `.row > .col-md-*` cards inside `main.content > .container-fluid`.
- **When to reach for it:** Any admin-facing page built from AppStack card components where letting the admin rearrange/resize sections is useful. It's gated on `window.MW_USER_ROLE === 'admin'` — no-op for other roles.
- Auto-loaded globally via `appstack_footer.php` (deferred).

### Global Search (Spotlight)
- **File:** `public/crm/js/global-search.js`
- **Purpose:** ⌘K / Ctrl+K command-palette search across contacts, companies, properties, quotes, jobs, invoices, and team.
- **Usage:** No JS call needed — binds to the markup already rendered by `appstack_topbar.php` (`#mwSpotlight`, `#mwSpotlightInput`, `#mwSpotlightBody`), which every AppStack page gets automatically.
- **When to reach for it:** You don't invoke this — it's already live on every CRM page. Extend `categoryMeta` in the file if you add a new searchable entity type.
- Auto-loaded globally via `appstack_head.php`.

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

**Total: 18 components documented.**
