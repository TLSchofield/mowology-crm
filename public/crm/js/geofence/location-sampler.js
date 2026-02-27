/**
 * LocationSampler — Battery-aware GPS sampler for geofenced visits.
 *
 * ── Design constraints ────────────────────────────────────────────────────────
 *
 * 1. NEVER starts unless geofence is explicitly enabled for the visit.
 * 2. Auto-stops when stopSampling() is called (clock-out hook).
 * 3. Adaptive sampling: interval increases when stationary, decreases when moving.
 * 4. Graceful degradation on PWA: uses the browser Geolocation API with
 *    watchPosition + backup interval polling. No background service workers.
 * 5. Battery-conscious: bounding-box pre-check skips most in/out computations.
 * 6. Offline-first: collected samples are passed to GeofenceSyncQueue, which
 *    batches and retries on reconnect.
 * 7. UI integration: fires onInZone / onOutZone / onSampleCollected callbacks;
 *    does NOT directly modify DOM — caller decides what to show.
 *
 * ── PWA / Capacitor note ──────────────────────────────────────────────────────
 * PWA background tracking limitations:
 *   - iOS: Geolocation only works in foreground. When screen locks or user
 *     switches apps, tracking pauses. We log the gap and mark those seconds as
 *     "unknown" in the session summary.
 *   - Android (PWA): similar restrictions in strict battery-saver mode.
 *   - Capacitor native: can use Background Geolocation plugin for true
 *     background tracking. To enable, add the plugin and override _startWatch()
 *     to use Capacitor's Background Geolocation API. The rest of this class
 *     (batching, sync, in-zone logic) stays identical.
 *
 * ── Usage ─────────────────────────────────────────────────────────────────────
 *
 *   const sampler = new LocationSampler({
 *     geofenceId:        42,
 *     visitId:           7,
 *     ring:              [[lat,lng], ...],  // polygon ring from resolve endpoint
 *     bbox:              { lat_min, lat_max, lng_min, lng_max },
 *     syncQueue:         new GeofenceSyncQueue(...),
 *     geofenceManager:   gm,           // optional — for live map dot
 *     minAccuracyM:      30,
 *     maxAccuracyM:      150,
 *     baseIntervalSec:   30,
 *     maxIntervalSec:    120,
 *     onInZone:          () => updateBadge(true),
 *     onOutZone:         () => updateBadge(false),
 *     onSampleCollected: (s) => updateGpsCount(s),
 *     onError:           (err) => console.warn(err),
 *   });
 *
 *   sampler.start();
 *   // ... on clock-out:
 *   sampler.stop();
 */

'use strict';

class LocationSampler {

    // ──────────────────────────────────────────────────────────────────────────
    // Constructor
    // ──────────────────────────────────────────────────────────────────────────

    constructor(opts = {}) {
        this._opts = Object.assign({
            geofenceId:        null,
            visitId:           null,
            ring:              [],
            bbox:              null,
            syncQueue:         null,       // GeofenceSyncQueue instance
            geofenceManager:   null,       // GeofenceManager instance (optional)
            minAccuracyM:      30,         // fixes better than this = high quality
            maxAccuracyM:      150,        // fixes worse than this = rejected
            baseIntervalSec:   30,         // sample rate (active movement)
            maxIntervalSec:    120,        // sample rate (stationary)
            stationaryThresholdM: 3,       // movement < Nm = stationary
            onInZone:          null,
            onOutZone:         null,
            onSampleCollected: null,
            onError:           null,
        }, opts);

        this._running      = false;
        this._watchId      = null;         // navigator.geolocation.watchPosition id
        this._intervalId   = null;         // backup polling interval
        this._lastLat      = null;
        this._lastLng      = null;
        this._lastTs       = null;
        this._lastInZone   = null;
        this._stationary   = false;
        this._sampleCount  = 0;
        this._currentInterval = this._opts.baseIntervalSec;

        // Visibility change handler (detect foreground/background transitions)
        this._onVisibilityChange = () => this._handleVisibilityChange();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Start / Stop
    // ──────────────────────────────────────────────────────────────────────────

    start() {
        if (this._running) return;
        if (!navigator.geolocation) {
            this._error('Geolocation API not available on this device.');
            return;
        }
        if (!this._opts.visitId || !this._opts.geofenceId) {
            this._error('visitId and geofenceId are required to start sampling.');
            return;
        }
        if (!this._opts.syncQueue) {
            this._error('syncQueue is required.');
            return;
        }

        this._running = true;
        this._startWatch();
        document.addEventListener('visibilitychange', this._onVisibilityChange);
        console.debug('[LocationSampler] Started. Visit:', this._opts.visitId,
                      'Geofence:', this._opts.geofenceId);
    }

    stop() {
        if (!this._running) return;
        this._running = false;
        this._stopWatch();
        document.removeEventListener('visibilitychange', this._onVisibilityChange);
        // Force-flush remaining samples
        if (this._opts.syncQueue) {
            this._opts.syncQueue.flush();
        }
        console.debug('[LocationSampler] Stopped. Total samples:', this._sampleCount);
    }

    get isRunning()    { return this._running; }
    get sampleCount()  { return this._sampleCount; }
    get lastInZone()   { return this._lastInZone; }

    // ──────────────────────────────────────────────────────────────────────────
    // Geolocation watch
    // ──────────────────────────────────────────────────────────────────────────

    _startWatch() {
        const geoOpts = {
            enableHighAccuracy: true,
            maximumAge:         10000,       // cache up to 10s
            timeout:            20000,
        };

        // Primary: watchPosition fires on every position update
        this._watchId = navigator.geolocation.watchPosition(
            (pos) => this._onPosition(pos),
            (err) => this._onGeoError(err),
            geoOpts
        );

        // Backup: force a fresh poll at the current interval
        // (needed when the device is stationary and watchPosition doesn't fire)
        this._scheduleBackupPoll();
    }

    _stopWatch() {
        if (this._watchId !== null) {
            navigator.geolocation.clearWatch(this._watchId);
            this._watchId = null;
        }
        if (this._intervalId !== null) {
            clearTimeout(this._intervalId);
            this._intervalId = null;
        }
    }

    _scheduleBackupPoll() {
        if (!this._running) return;
        this._intervalId = setTimeout(() => {
            navigator.geolocation.getCurrentPosition(
                (pos) => this._onPosition(pos),
                (err) => this._onGeoError(err),
                { enableHighAccuracy: true, maximumAge: 5000, timeout: 10000 }
            );
            this._scheduleBackupPoll();
        }, this._currentInterval * 1000);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Position handler
    // ──────────────────────────────────────────────────────────────────────────

    _onPosition(pos) {
        if (!this._running) return;

        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        const acc = pos.coords.accuracy;      // metres, horizontal
        const ts  = pos.timestamp;            // ms UTC epoch

        // Accuracy gate
        if (acc > this._opts.maxAccuracyM) {
            console.debug('[LocationSampler] Fix too inaccurate, skipping.', acc, 'm');
            return;
        }

        // De-duplicate: skip if same position within base interval
        if (this._lastTs && (ts - this._lastTs) < (this._opts.baseIntervalSec * 1000 * 0.8)) {
            // Too soon — watchPosition fired from cached position
            return;
        }

        // Compute in-zone
        const inZone = this._checkInZone(lat, lng);

        // Adaptive interval: stationary → slow down sampling
        const moved = this._hasMoved(lat, lng);
        this._updateAdaptiveInterval(moved);

        // Build sample object
        const sample = {
            lat,
            lng,
            accuracy_m:  acc,
            speed_mps:   pos.coords.speed !== null ? pos.coords.speed : null,
            heading:     pos.coords.heading !== null ? pos.coords.heading : null,
            altitude_m:  pos.coords.altitude !== null ? pos.coords.altitude : null,
            battery_pct: null,  // populated below if Battery API available
            source:      'gps',
            timestamp:   ts,
            in_zone:     inZone,   // client-side pre-classification (server will verify)
        };

        // Try Battery API (not available on all browsers)
        this._getBatteryPct().then(pct => {
            if (pct !== null) sample.battery_pct = pct;
        });

        // Buffer to sync queue
        this._opts.syncQueue.enqueue(sample);
        this._sampleCount++;

        // Update map marker
        if (this._opts.geofenceManager) {
            this._opts.geofenceManager.updateCrewPosition(lat, lng, inZone);
        }

        // Fire callbacks on zone transition
        if (inZone !== this._lastInZone) {
            this._lastInZone = inZone;
            if (inZone) {
                if (typeof this._opts.onInZone === 'function') this._opts.onInZone();
            } else {
                if (typeof this._opts.onOutZone === 'function') this._opts.onOutZone();
            }
        }

        if (typeof this._opts.onSampleCollected === 'function') {
            this._opts.onSampleCollected(sample);
        }

        this._lastLat = lat;
        this._lastLng = lng;
        this._lastTs  = ts;
    }

    _onGeoError(err) {
        // PERMISSION_DENIED = 1, POSITION_UNAVAILABLE = 2, TIMEOUT = 3
        const msg = ['', 'GPS permission denied.', 'GPS signal unavailable.', 'GPS timeout.'][err.code] || 'GPS error.';
        if (err.code === 1) {
            // Fatal — stop trying
            this.stop();
        }
        this._error(msg + ' (code ' + err.code + ')');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Geometry helpers
    // ──────────────────────────────────────────────────────────────────────────

    _checkInZone(lat, lng) {
        const ring = this._opts.ring;
        if (!ring || ring.length < 3) return false;

        // Bounding-box fast rejection
        const bb = this._opts.bbox;
        if (bb) {
            if (lat < bb.lat_min || lat > bb.lat_max) return false;
            if (lng < bb.lng_min || lng > bb.lng_max) return false;
        }

        return this._pointInPolygon(lat, lng, ring);
    }

    _pointInPolygon(lat, lng, polygon) {
        let inside = false;
        const n = polygon.length;
        let j = n - 1;
        for (let i = 0; i < n; i++) {
            const xi = polygon[i][0], yi = polygon[i][1];
            const xj = polygon[j][0], yj = polygon[j][1];
            const intersect = ((yi > lng) !== (yj > lng))
                && (lat < (xj - xi) * (lng - yi) / (yj - yi) + xi);
            if (intersect) inside = !inside;
            j = i;
        }
        return inside;
    }

    // Haversine: returns distance in metres between last and current position
    _hasMoved(lat, lng) {
        if (this._lastLat === null) return true;
        const R    = 6371000;
        const dLat = (lat - this._lastLat) * Math.PI / 180;
        const dLng = (lng - this._lastLng) * Math.PI / 180;
        const a    = Math.sin(dLat / 2) ** 2
                   + Math.cos(this._lastLat * Math.PI / 180)
                   * Math.cos(lat * Math.PI / 180)
                   * Math.sin(dLng / 2) ** 2;
        const dist = R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return dist >= this._opts.stationaryThresholdM;
    }

    _updateAdaptiveInterval(moved) {
        if (moved) {
            // Moving: reset to base interval
            this._currentInterval = this._opts.baseIntervalSec;
            this._stationary = false;
        } else {
            // Stationary: back off up to maxIntervalSec
            this._stationary = true;
            this._currentInterval = Math.min(
                this._currentInterval * 1.5,
                this._opts.maxIntervalSec
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Battery API (best-effort, not critical)
    // ──────────────────────────────────────────────────────────────────────────

    _getBatteryPct() {
        if (!navigator.getBattery) return Promise.resolve(null);
        return navigator.getBattery()
            .then(bat => Math.round(bat.level * 100))
            .catch(() => null);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Background / foreground handling (PWA visibility API)
    // ──────────────────────────────────────────────────────────────────────────

    _handleVisibilityChange() {
        if (!this._running) return;
        if (document.hidden) {
            // App went to background
            // watchPosition may continue on Android but will likely pause on iOS.
            // We note the time so the session can account for gaps.
            this._backgroundStartTs = Date.now();
            console.debug('[LocationSampler] App went to background at', this._backgroundStartTs);
        } else {
            // App returned to foreground
            if (this._backgroundStartTs) {
                const gapSec = Math.round((Date.now() - this._backgroundStartTs) / 1000);
                console.debug('[LocationSampler] Returned from background after', gapSec, 's gap');
                this._backgroundStartTs = null;
                // Force an immediate position fix
                navigator.geolocation.getCurrentPosition(
                    (pos) => this._onPosition(pos),
                    () => {},
                    { enableHighAccuracy: true, maximumAge: 0, timeout: 10000 }
                );
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Error
    // ──────────────────────────────────────────────────────────────────────────

    _error(msg) {
        console.warn('[LocationSampler]', msg);
        if (typeof this._opts.onError === 'function') this._opts.onError(msg);
    }
}

// Export
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { LocationSampler };
} else {
    window.LocationSampler = LocationSampler;
}
