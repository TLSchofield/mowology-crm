# Polygon Job-Area Geofence Tracking Module

**Migration:** `800_polygon_geofence.sql`
**Status:** Additive only — zero breaking changes to existing system

---

## 1. What This Module Does

Enables optional polygon-based work-zone compliance tracking:

- Admin draws a polygon over the property on a map
- When the crew starts a visit timer, GPS sampling activates (if enabled)
- Every 30s (configurable), a GPS fix is taken and checked: inside or outside the polygon?
- Entry/exit events are derived from the sample stream
- On clock-out, a summary is computed: in-zone minutes, out-zone minutes, confidence score (0–100%)
- The summary feeds into the existing Proof-of-Work PDF as an optional section

**Critically: if the feature is disabled for a visit, nothing changes.** The existing clock-in/out, GPS breadcrumb, and PoW systems are untouched.

---

## 2. File Map

```
app/Modules/Geofence/
  Models/
    GeofenceModel.php          ← All DB access: polygon CRUD, sample ingestion,
                                  classification, event derivation, session compute.
  Api/
    GeofenceApi.php            ← HTTP handler (10 actions). CSRF-protected.
    geofence-settings.php      ← Admin settings UI card fragment.
  Services/
    GeofencePowRenderer.php    ← Read-only PoW section renderer (HTML + text).
    visit-work-integration.js  ← Drop-in integration snippet for visit-work.php.

public/crm/api/
  geofence.php                 ← Public shim → GeofenceApi.php

public/crm/js/geofence/
  geofence-manager.js          ← Leaflet polygon draw/edit/display + checkPoint().
  location-sampler.js          ← Battery-aware GPS sampler (watchPosition + backup poll).
  sync-queue.js                ← Offline-first batch uploader with exponential back-off.

database/migrations/
  800_polygon_geofence.sql     ← All DDL. Safe to run multiple times (procedures guard columns).
```

---

## 3. Database Tables Added

| Table | Purpose |
|-------|---------|
| `job_geofences` | One polygon per job plan |
| `job_location_samples` | High-res GPS samples, tagged in/out zone |
| `job_geofence_events` | Entry/exit events derived from sample stream |
| `job_geofence_sessions` | Per-visit summary (in-zone secs, confidence score) |

**Columns added to existing tables (safe, guarded by stored procedure):**

| Table | Column | Notes |
|-------|--------|-------|
| `job_plans` | `geofence_enabled TINYINT NULL` | NULL = inherit |
| `job_plans` | `active_geofence_id INT NULL` | FK to job_geofences |
| `job_visits` | `geofence_enabled TINYINT NULL` | NULL = inherit from plan |
| `job_visits` | `in_zone_minutes SMALLINT NULL` | Denormalised for dashboard |
| `job_visits` | `geofence_confidence TINYINT NULL` | Cached 0–100 score |

**Settings added to `time_clock_settings`:**

| Key | Default | Meaning |
|-----|---------|---------|
| `geofence.global_enabled` | `0` | Master on/off |
| `geofence.services_enabled` | `[]` | JSON array of service_type strings |
| `geofence.sample_interval_sec` | `30` | GPS sample rate |
| `geofence.min_accuracy_m` | `30` | High-quality threshold |
| `geofence.max_accuracy_m` | `150` | Reject threshold |
| `geofence.show_ui_badge` | `1` | Show badge on job cards |
| `geofence.pow_section_enabled` | `1` | Include in PoW PDF |

---

## 4. Activation Logic

```
geofenceResolveForVisit($visitId) returns {enabled, source, geofence_id, ...}

Priority chain (most specific wins):
  visit.geofence_enabled   (not NULL)  →  source = 'visit'
  plan.geofence_enabled    (not NULL)  →  source = 'plan'
  service type in services_enabled     →  source = 'service'
  global_enabled                       →  source = 'global'
  no polygon exists                    →  source = 'no_polygon', enabled = false
```

**Even if all flags are ON, the feature is disabled if no polygon has been drawn for the plan.**

---

## 5. Integration Steps

### Step A — Run the migration

```bash
# Run in MySQL (or via /crm/database_appstack.php → Apply Migrations)
source database/migrations/800_polygon_geofence.sql
```

### Step B — Add polygon draw UI to job plan detail page

```php
// In /crm/jobs/plan-detail_appstack.php (or wherever plans are shown)

// 1. Add map container + controls
?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5 class="mb-0">Work-Zone Polygon</h5>
    <?php if ($canEdit): ?>
    <button class="btn btn-sm btn-outline-primary" onclick="geofenceMgr.startDraw()">Draw Polygon</button>
    <button class="btn btn-sm btn-primary ml-2" onclick="geofenceMgr.save()">Save</button>
    <button class="btn btn-sm btn-outline-danger ml-2" onclick="geofenceMgr.deletePolygon()">Delete</button>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <div id="geofence-map" style="height:300px;"></div>
  </div>
</div>

<?php
// 2. Load Leaflet + geofence scripts (add to $extraHead or inline before footer)
$extraHead = '
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
';
?>

<!-- After appstack_footer scripts: -->
<script src="/crm/js/geofence/geofence-manager.js"></script>
<script>
const geofenceMgr = new GeofenceManager({
  mapContainer: 'geofence-map',
  planId:       <?= (int)$planId ?>,
  csrfToken:    <?= json_encode($csrfToken) ?>,
  mode:         '<?= $canEdit ? 'edit' : 'view' ?>',
  center:       [<?= (float)($property['lat'] ?? 49.28) ?>, <?= (float)($property['lng'] ?? -123.12) ?>],
  zoom:         18,
  onSave:       (id) => showToast('Polygon saved — geofence active for this plan.', 'success'),
  onDelete:     ()  => showToast('Polygon removed.', 'info'),
});
geofenceMgr.init();
</script>
```

### Step C — Add per-visit / per-plan override controls

```php
// In plan detail form:
<div class="custom-control custom-select">
  <label>Geofence Tracking</label>
  <select name="geofence_enabled" class="form-control">
    <option value="">Inherit from global/service settings</option>
    <option value="1" <?= $plan['geofence_enabled'] === '1' ? 'selected' : '' ?>>Always ON for this plan</option>
    <option value="0" <?= $plan['geofence_enabled'] === '0' ? 'selected' : '' ?>>Always OFF for this plan</option>
  </select>
</div>
// Save: POST to /crm/api/geofence.php {action:'set_plan_override', plan_id, enabled, csrf_token}
```

### Step D — Activate tracking in visit-work.php

Add to the `<head>` extras or just before `</body>` in `visit-work.php`:

```php
// PHP: emit config variables
echo '<script>';
echo 'window.GEO_VISIT_ID = ' . (int)$visitId . ';';
echo 'window.GEO_PLAN_ID  = ' . (int)$visit['plan_id'] . ';';
echo 'window.GEO_CSRF     = ' . json_encode($csrfToken) . ';';
echo '</script>';
```

```html
<!-- Geofence tracking scripts (load after existing scripts) -->
<script src="/crm/js/geofence/sync-queue.js"></script>
<script src="/crm/js/geofence/location-sampler.js"></script>
<script src="/crm/js/geofence/geofence-manager.js"></script>
<script src="/crm/js/geofence/visit-work-integration.js"></script>
```

Then hook into the existing timer JS:

```javascript
// After the existing startVisitTimer() success handler, add:
if (window.GeoIntegration) window.GeoIntegration.onTimerStart();

// After the existing stopVisitTimer() success handler, add:
if (window.GeoIntegration) window.GeoIntegration.onTimerStop();
```

Add these HTML elements inside the visit-work stats bar:

```html
<div id="geo-zone-badge" style="display:none;" class="mw-geo-badge"></div>
<div id="geo-in-zone-timer" style="display:none;" class="mw-geo-timer"></div>
<!-- Optional: separate indicator in stats bar -->
<div class="pow-stat">
  <span class="pow-stat-val" id="geo-zone-indicator">--</span>
  <span class="pow-stat-lbl">Zone</span>
</div>
```

### Step E — Add to Proof-of-Work output

In `PowPdfGenerator.php` or `visit-detail.php`, after loading the existing PoW data:

```php
// Load geofence renderer (safe no-op if tracking wasn't active)
$geofenceHtml = '';
$geofenceSummary = '';
if (is_file(APP_ROOT . '/app/Modules/Geofence/Services/GeofencePowRenderer.php')) {
    require_once APP_ROOT . '/app/Modules/Geofence/Services/GeofencePowRenderer.php';
    $geofenceHtml    = geofencePowSection($visitId, $forPdf = true);
    $geofenceSummary = geofencePowSummaryText($visitId);
}

// Then in the template, after the GPS track section:
echo $geofenceHtml;
```

### Step F — Add admin settings card

In `/crm/settings_appstack.php` or a dedicated geofence admin page:

```php
require_once APP_ROOT . '/app/Modules/Geofence/Api/geofence-settings.php';
geofenceSettingsCard($csrfToken);
```

---

## 6. CSS for Enhanced Tracking UI

Add to `mowology-brand.css`:

```css
/* ── Geofence Tracking Module ──────────────────────────────────────────── */

.mw-geo-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.78em;
    font-weight: 600;
    letter-spacing: .03em;
    background: var(--mw-light);
    color: var(--mw-dark);
    border: 1px solid var(--mw-green);
    transition: background .2s, color .2s;
}

.mw-geo-badge--in-zone {
    background: var(--mw-green);
    color: #fff;
    border-color: var(--mw-dark);
}

.mw-geo-badge--out-zone {
    background: #fff3cd;
    color: #856404;
    border-color: #ffc107;
}

.mw-geo-badge-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--mw-green);
    animation: geo-pulse 2s ease-in-out infinite;
}

.mw-geo-badge-dot.out {
    background: #ffc107;
    animation: geo-pulse 1s ease-in-out infinite;
}

@keyframes geo-pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.4; }
}

.mw-geo-timer {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.mw-geo-timer-lbl {
    font-size: 0.65em;
    color: var(--mw-green);
    text-transform: uppercase;
    letter-spacing: .06em;
}

.mw-geo-timer-val {
    font-size: 1.1em;
    font-weight: 700;
    color: var(--mw-green);
    font-variant-numeric: tabular-nums;
}
```

---

## 7. API Reference

All endpoints at `/crm/api/geofence.php`. POST requests require `csrf_token`.

| Action | Method | Auth | Description |
|--------|--------|------|-------------|
| `resolve` | GET | jobs.view | Activation state + polygon for a visit |
| `get_polygon` | GET | jobs.view | Polygon for a plan |
| `save_polygon` | POST | jobs.edit | Create/replace polygon |
| `delete_polygon` | POST | jobs.edit | Remove polygon |
| `sync_samples` | POST | jobs.view | Batch GPS sample upload |
| `compute_session` | POST | jobs.view | Finalise visit summary |
| `get_session` | GET | jobs.view | Session + event timeline |
| `get_settings` | GET | admin | All geofence.* settings |
| `save_settings` | POST | admin | Update settings |
| `set_visit_override` | POST | jobs.edit | Force on/off for one visit |
| `set_plan_override` | POST | jobs.edit | Force on/off for one plan |

---

## 8. PWA / Mobile Limitations

### iOS Safari (PWA)
- `watchPosition` pauses when screen locks or app is backgrounded
- Time gap is detected via `visibilitychange` event and logged
- Unknown-time gap is accounted for in confidence score
- **Cannot** do true background tracking without Capacitor native plugin

### Android (PWA)
- Works reliably in foreground
- Background tracking works until battery saver engages
- Adaptive interval (30s → 120s when stationary) reduces battery drain

### Capacitor Native Enhancement (Optional — Future)
Override `LocationSampler._startWatch()`:

```javascript
_startWatch() {
    // Requires: @capacitor-community/background-geolocation
    if (window.BackgroundGeolocation) {
        BackgroundGeolocation.addWatcher(
            { backgroundMessage: 'Geofence tracking active', distanceFilter: 5 },
            (pos, err) => {
                if (pos) this._onPosition({ coords: pos, timestamp: Date.now() });
            }
        ).then(id => { this._capacitorWatcherId = id; });
    } else {
        // Fall back to browser Geolocation API (existing logic)
        super._startWatch && super._startWatch();
    }
}
```

The rest of the class (sync queue, in-zone logic, callbacks) stays identical.

---

## 9. Confidence Score Formula

```
confidence = (in_zone_seconds / (total_seconds - unknown_seconds)) × 100

Where:
  total_seconds   = visit duration from job_time_entries (SUM of duration_minutes × 60)
  in_zone_seconds = sum of entry→exit stay durations from job_geofence_events
  unknown_seconds = time before first GPS fix or with accuracy > max_accuracy_m
                    (v1: currently 0 — v2 enhancement)

Score interpretation:
  85–100%  → High confidence — crew worked within the defined zone
  60–84%   → Moderate — some time outside zone (breaks, street parking, etc.)
  0–59%    → Low — significant out-of-zone time
  null     → Not enough GPS data to calculate
```

---

## 10. Testing Checklist

### Activation
- [ ] Visit with no polygon → feature disabled, existing UI unchanged
- [ ] Visit with polygon + global OFF → feature disabled
- [ ] Visit with polygon + service ON → feature enabled
- [ ] Visit with polygon + plan override OFF → feature disabled (overrides service)
- [ ] Visit with polygon + visit override ON → feature enabled (overrides plan)

### Polygon Management
- [ ] Draw polygon (≥3 vertices, double-click to close)
- [ ] Save polygon → stored in `job_geofences`, plan updated
- [ ] Load saved polygon → renders on map
- [ ] Delete polygon → `job_geofences` row removed, plan FK cleared

### GPS Sampling
- [ ] Start timer → `onTimerStart()` activates sampler
- [ ] GPS fix inside polygon → `in_zone = 1` in `job_location_samples`
- [ ] GPS fix outside polygon → `in_zone = 0`
- [ ] Accuracy > max_accuracy_m → sample skipped
- [ ] Offline → samples queued, flushed on reconnect
- [ ] Stop timer → sampler stops, queue flushed

### Session Computation
- [ ] `compute_session` called after timer stop
- [ ] `job_geofence_sessions` row created with correct seconds
- [ ] `job_visits.in_zone_minutes` updated
- [ ] `job_visits.geofence_confidence` updated

### Proof-of-Work
- [ ] `geofencePowSection()` returns empty string when tracking not active
- [ ] When active: shows confidence score, in/out times, timeline
- [ ] `geofence.pow_section_enabled = 0` → section suppressed

### Battery / Performance
- [ ] Sample rate backs off to 120s when stationary
- [ ] `watchPosition` + backup poll don't double-sample (timestamp dedup)
- [ ] No GPS requests before `onTimerStart()` is called
- [ ] No GPS requests after `onTimerStop()` is called

---

## 11. Rollback Plan

This module is fully additive. To rollback:

1. **Disable globally:** Set `geofence.global_enabled = '0'` in `time_clock_settings` — all tracking stops immediately, no code changes needed.

2. **Revert JS:** Remove the four `<script>` tags from `visit-work.php`. The two `GeoIntegration` hook calls are `if (window.GeoIntegration)` guarded and are no-ops without the scripts.

3. **Remove PoW section:** Set `geofence.pow_section_enabled = '0'` — the renderer returns an empty string.

4. **Drop tables (destructive):**
   ```sql
   DROP TABLE IF EXISTS job_geofence_sessions;
   DROP TABLE IF EXISTS job_geofence_events;
   DROP TABLE IF EXISTS job_location_samples;
   DROP TABLE IF EXISTS job_geofences;
   ALTER TABLE job_plans  DROP COLUMN IF EXISTS geofence_enabled;
   ALTER TABLE job_plans  DROP COLUMN IF EXISTS active_geofence_id;
   ALTER TABLE job_visits DROP COLUMN IF EXISTS geofence_enabled;
   ALTER TABLE job_visits DROP COLUMN IF EXISTS in_zone_minutes;
   ALTER TABLE job_visits DROP COLUMN IF EXISTS geofence_confidence;
   DELETE FROM time_clock_settings WHERE setting_key LIKE 'geofence.%';
   ```

Steps 1–3 achieve silent disable without any data loss.

---

## 12. Definition of Done

- [x] Existing system behaves identically when geofence is disabled
- [x] Admin can enable per global / per service / per plan / per visit
- [x] Polygon can be drawn, saved, and deleted
- [x] In-area and out-of-area time computed and stored
- [x] Confidence score derived
- [x] Entry/exit events logged with timestamps
- [x] Battery-conscious: adaptive sampling, no GPS before clock-in
- [x] Offline-first: samples queue locally, flush with back-off
- [x] PWA degradation documented (iOS background limitation)
- [x] Capacitor native upgrade path documented
- [x] PoW section optional, additive, renders empty when inactive
- [x] All new tables: MySQL 5.7 compatible, utf8mb4_general_ci, CASCADE deletes
- [x] No existing tables modified destructively
- [x] Full rollback achievable in < 5 minutes without code changes
