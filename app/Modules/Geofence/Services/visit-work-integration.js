/**
 * Geofence integration snippet for visit-work.php
 *
 * ── How to integrate ──────────────────────────────────────────────────────────
 *
 * In visit-work.php, after the PHP guard block and before </body>:
 *
 *  1. Load the three scripts (after Leaflet if you want the live map):
 *
 *     <script src="/crm/js/geofence/sync-queue.js"></script>
 *     <script src="/crm/js/geofence/location-sampler.js"></script>
 *     <script src="/crm/js/geofence/geofence-manager.js"></script>
 *
 *  2. Add this snippet (or include this file via a PHP echo):
 *
 *     <div id="geo-zone-badge" style="display:none;" class="mw-geo-badge"></div>
 *     <div id="geo-in-zone-timer" style="display:none;" class="mw-geo-timer"></div>
 *     <script>
 *       window.GEO_VISIT_ID  = <?= (int)$visitId ?>;
 *       window.GEO_PLAN_ID   = <?= (int)$visit['plan_id'] ?>;
 *       window.GEO_CSRF      = <?= json_encode($csrfToken) ?>;
 *     </script>
 *     <script src="/crm/js/geofence/visit-work-integration.js"></script>
 *
 *  3. Hook into the existing clock-in / clock-out JS:
 *
 *     // After successful timer start:
 *     if (window.GeoIntegration) window.GeoIntegration.onTimerStart();
 *
 *     // After successful timer stop:
 *     if (window.GeoIntegration) window.GeoIntegration.onTimerStop();
 *
 * ── What this does ────────────────────────────────────────────────────────────
 *
 *  - Calls GET resolve to check if geofence is enabled for the visit
 *  - If disabled: exits immediately — no GPS, no UI changes
 *  - If enabled:
 *    - Shows "Enhanced Tracking Active" badge
 *    - Initialises GeofenceSyncQueue + LocationSampler
 *    - Updates in-zone / out-zone UI indicator
 *    - Maintains a secondary "in-zone time" counter
 *    - On timer stop: calls compute_session to finalize summary
 */

'use strict';

(function () {
    const VISIT_ID  = window.GEO_VISIT_ID  || 0;
    const PLAN_ID   = window.GEO_PLAN_ID   || 0;
    const CSRF      = window.GEO_CSRF      || '';
    const API_BASE  = '/crm/api/geofence.php';

    if (!VISIT_ID) return;  // safety guard

    let sampler    = null;
    let syncQueue  = null;
    let geoConfig  = null;  // resolve response
    let inZoneSec  = 0;
    let inZoneTimer = null;
    let currentlyInZone = null;

    // ── DOM refs ──────────────────────────────────────────────────────────────
    const $badge       = document.getElementById('geo-zone-badge');
    const $inZoneTimer = document.getElementById('geo-in-zone-timer');

    // ── Step 1: Resolve activation ───────────────────────────────────────────
    function init() {
        fetch(`${API_BASE}?action=resolve&visit_id=${VISIT_ID}`, {
            credentials: 'same-origin',
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.enabled) {
                // Geofence not active for this visit — do nothing
                console.debug('[GeoIntegration] Geofence not enabled for visit', VISIT_ID,
                              'reason:', data.source);
                return;
            }
            geoConfig = data;
            console.debug('[GeoIntegration] Geofence active. Config:', data);
            _setupUI();
        })
        .catch(err => console.warn('[GeoIntegration] Resolve failed:', err));
    }

    // ── Step 2: Setup UI ─────────────────────────────────────────────────────
    function _setupUI() {
        // Enhanced tracking badge
        if ($badge) {
            $badge.style.display = '';
            $badge.innerHTML = `
                <span class="mw-geo-badge-dot"></span>
                Enhanced Tracking Active
            `;
        }
        // In-zone timer display
        if ($inZoneTimer) {
            $inZoneTimer.style.display = '';
            $inZoneTimer.innerHTML = `
                <span class="mw-geo-timer-lbl">In-zone</span>
                <span class="mw-geo-timer-val" id="geo-inzone-val">--:--</span>
            `;
        }
    }

    // ── Step 3: Called by visit-work.php on timer START ──────────────────────
    function onTimerStart() {
        if (!geoConfig || !geoConfig.enabled) return;

        console.debug('[GeoIntegration] Timer started — activating geofence tracking');

        // Build sync queue
        syncQueue = new GeofenceSyncQueue({
            visitId:          VISIT_ID,
            geofenceId:       geoConfig.geofence_id,
            apiBase:          API_BASE,
            csrfToken:        CSRF,
            flushIntervalSec: geoConfig.sample_interval_sec || 30,
            maxBatchSize:     50,
            onFlush:          (res) => console.debug('[GeoIntegration] Flushed', res.inserted, 'samples'),
            onFlushFail:      (err) => console.warn('[GeoIntegration] Flush fail:', err),
        });

        // Build sampler
        sampler = new LocationSampler({
            geofenceId:        geoConfig.geofence_id,
            visitId:           VISIT_ID,
            ring:              geoConfig.polygon ? geoConfig.polygon.ring   : [],
            bbox:              geoConfig.polygon ? geoConfig.polygon.bbox   : null,
            syncQueue,
            minAccuracyM:      geoConfig.min_accuracy_m  || 30,
            maxAccuracyM:      geoConfig.max_accuracy_m  || 150,
            baseIntervalSec:   geoConfig.sample_interval_sec || 30,
            maxIntervalSec:    geoConfig.sample_interval_sec * 4 || 120,
            onInZone:          () => _setZoneState(true),
            onOutZone:         () => _setZoneState(false),
            onSampleCollected: (s) => _onSample(s),
            onError:           (msg) => console.warn('[GeoIntegration] GPS error:', msg),
        });

        syncQueue.start();
        sampler.start();
        _startInZoneCounter();
    }

    // ── Step 4: Called by visit-work.php on timer STOP ───────────────────────
    function onTimerStop() {
        if (!geoConfig || !geoConfig.enabled) return;

        console.debug('[GeoIntegration] Timer stopped — finalising geofence session');

        if (sampler) { sampler.stop(); sampler = null; }
        if (syncQueue) { syncQueue.stop(); syncQueue = null; }
        _stopInZoneCounter();

        // Compute session summary server-side
        fetch(API_BASE, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body:        JSON.stringify({
                action:     'compute_session',
                csrf_token: CSRF,
                visit_id:   VISIT_ID,
            }),
        })
        .then(r => r.json())
        .then(data => {
            console.debug('[GeoIntegration] Session computed:', data);
        })
        .catch(err => console.warn('[GeoIntegration] compute_session failed:', err));
    }

    // ── Zone state UI ─────────────────────────────────────────────────────────
    function _setZoneState(inZone) {
        currentlyInZone = inZone;

        if ($badge) {
            $badge.classList.toggle('mw-geo-badge--in-zone',  inZone);
            $badge.classList.toggle('mw-geo-badge--out-zone', !inZone);
            // Update label
            const dot = $badge.querySelector('.mw-geo-badge-dot');
            if (dot) dot.className = 'mw-geo-badge-dot ' + (inZone ? 'in' : 'out');
            // Update nearby indicator if present in stats bar
            const indicator = document.getElementById('geo-zone-indicator');
            if (indicator) {
                indicator.textContent = inZone ? 'In Zone' : 'Outside Zone';
                indicator.style.color = inZone ? 'var(--mw-green)' : '#dc3545';
            }
        }
    }

    function _onSample(sample) {
        // Update sample counter if it exists in stats bar
        const $gpsCount = document.getElementById('gps-pts-display');
        if ($gpsCount && sampler) {
            $gpsCount.textContent = sampler.sampleCount;
        }
    }

    // ── In-zone counter ───────────────────────────────────────────────────────
    function _startInZoneCounter() {
        inZoneSec = 0;
        inZoneTimer = setInterval(() => {
            if (currentlyInZone) {
                inZoneSec++;
                _updateInZoneDisplay();
            }
        }, 1000);
    }

    function _stopInZoneCounter() {
        if (inZoneTimer) { clearInterval(inZoneTimer); inZoneTimer = null; }
    }

    function _updateInZoneDisplay() {
        const el = document.getElementById('geo-inzone-val');
        if (!el) return;
        const h = Math.floor(inZoneSec / 3600);
        const m = Math.floor((inZoneSec % 3600) / 60);
        const s = inZoneSec % 60;
        el.textContent = (h > 0 ? h + ':' : '')
            + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    // ── Expose public API ─────────────────────────────────────────────────────
    window.GeoIntegration = { init, onTimerStart, onTimerStop };

    // Auto-init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
