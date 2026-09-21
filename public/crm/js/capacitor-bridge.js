/**
 * Mowology Capacitor Bridge v2
 * ──────────────────────────────
 * Self-guarding module: no-op in browsers, initializes window.MwNative
 * when running inside the Capacitor native Android shell.
 *
 * Provides:
 *   MwNative.geo          — background GPS + one-shot position
 *   MwNative.tracking     — session management, health, compliance, geofencing
 *   MwNative.notifications — local push notifications
 *   MwNative.push          — FCM registration (remote push notifications)
 *   MwNative.network      — online/offline detection
 *
 * Uses two Capacitor plugins:
 *   1. @capacitor-community/background-geolocation — foreground service + GPS watcher
 *   2. MwTracking (custom) — health diagnostics, Room DB, WorkManager sync, activity recognition
 *
 * Loaded on every CRM page via appstack_footer.php.
 * In a browser, the guard exits immediately (zero cost).
 */
(function() {
    'use strict';

    // Only run inside Capacitor native app
    if (!window.Capacitor || !window.Capacitor.isNativePlatform()) return;

    var Plugins = window.Capacitor.Plugins;
    var BackgroundGeolocation = Plugins.BackgroundGeolocation;
    var Geolocation = Plugins.Geolocation;
    var LocalNotifications = Plugins.LocalNotifications;
    var PushNotifications = Plugins.PushNotifications;
    var Network = Plugins.Network;
    var MwTracking = Plugins.MwTracking; // Custom plugin
    var App = Plugins.App; // For hardware back button + app state

    // ── Location Processor (JS-side noise filter) ──────────
    // Supplements the native activity-based distance filter with JS-side
    // quality gating. Two tiers:
    //   Hard reject  — accuracy > 80 m (indoor/tunnel noise, cold GPS)
    //   Soft reject  — accuracy > 50 m AND speed < 0.5 m/s (stationary bad fix)
    //   Teleport     — apparent speed > 60 m/s vs previous accepted fix
    var locationProcessor = {
        ACCURACY_HARD:  80,   // meters — always reject above this
        ACCURACY_SOFT:  50,   // meters — reject if speed is below SPEED_FLOOR
        SPEED_FLOOR:   0.5,   // m/s — below this the device is effectively still
        SPEED_TELEPORT: 60,   // m/s (~216 km/h) — physically impossible on foot/bike
        MIN_DISTANCE:    5,   // meters — suppress micro-jitter when still
        lastAccepted: null,
        stationaryCount: 0,
        totalReceived: 0,

        process: function(pos) {
            this.totalReceived++;

            // Always accept the first 3 fixes so the UI populates quickly on cold start.
            if (this.totalReceived <= 3) {
                this.lastAccepted = pos;
                return true;
            }

            // Hard accuracy reject
            if (pos.accuracy > this.ACCURACY_HARD) {
                return false;
            }

            // Soft accuracy reject: bad fix while stationary
            var speed = pos.speed || 0;
            if (pos.accuracy > this.ACCURACY_SOFT && speed < this.SPEED_FLOOR) {
                return false;
            }

            if (this.lastAccepted) {
                var dist = haversineDistance(
                    this.lastAccepted.lat, this.lastAccepted.lng,
                    pos.lat, pos.lng
                );
                var timeDelta = (pos.timestamp - this.lastAccepted.timestamp) / 1000;

                // Teleport detection (GPS multi-path artifact)
                if (timeDelta > 0 && dist / timeDelta > this.SPEED_TELEPORT) {
                    return false;
                }

                // Jitter filter when stationary — accept every 3rd sub-threshold fix
                if (dist < this.MIN_DISTANCE) {
                    this.stationaryCount++;
                    if (this.stationaryCount % 3 !== 0) {
                        return false;
                    }
                } else {
                    this.stationaryCount = 0;
                }
            }

            this.lastAccepted = pos;
            return true;
        }
    };

    // ── Route Buffer ────────────────────────────────────────
    // Stores one accepted fix per minute (max 600 = 10-hour shift).
    // Dispatcher can call MwNative.route.points(visitId) to get the
    // full polyline for route replay.
    var routeBuffer = {
        points:        [],
        lastPointMs:   0,
        lastPingMs:    0,
        MIN_INTERVAL:  60 * 1000,  // 1 point per minute
        MAX_POINTS:    600,        // 10-hour shift ceiling

        add: function(pos, visitId) {
            var now = Date.now();
            this.lastPingMs = now;
            if (now - this.lastPointMs < this.MIN_INTERVAL) return;
            this.lastPointMs = now;
            this.points.push({
                lat:      pos.lat,
                lng:      pos.lng,
                accuracy: pos.accuracy,
                heading:  pos.heading  || 0,
                speed:    pos.speed    || 0,
                ts:       now,
                visitId:  visitId || null
            });
            if (this.points.length > this.MAX_POINTS) this.points.shift();
        },

        getPoints: function(visitId) {
            if (visitId == null) return this.points.slice();
            return this.points.filter(function(p) { return p.visitId === visitId; });
        },

        reset: function(visitId) {
            if (visitId == null) {
                this.points = [];
            } else {
                this.points = this.points.filter(function(p) { return p.visitId !== visitId; });
            }
        },

        minutesSinceLastPing: function() {
            return this.lastPingMs ? (Date.now() - this.lastPingMs) / 60000 : Infinity;
        }
    };

    // ── Arrival + Dwell Tracker ─────────────────────────────
    // Tracks dispatcher-trust metrics for one visit at a time.
    // Reset via arrivalTracker.configure(lat, lng) on job start.
    var arrivalTracker = {
        siteCoord:     null,  // { lat, lng }
        arrivalTime:   null,  // ms — first fix within 30 m
        jobStartTime:  null,  // ms — when clock-in was confirmed
        jobEndTime:    null,  // ms — when clock-out was confirmed
        worstAccuracy: 0,     // metres
        ARRIVAL_RADIUS: 30,   // metres

        configure: function(lat, lng) {
            this.siteCoord     = { lat: lat, lng: lng };
            this.arrivalTime   = null;
            this.jobStartTime  = null;
            this.jobEndTime    = null;
            this.worstAccuracy = 0;
        },

        observe: function(pos) {
            if (!this.siteCoord) return;
            if (pos.accuracy > this.worstAccuracy) this.worstAccuracy = pos.accuracy;
            if (!this.arrivalTime) {
                var dist = haversineDistance(
                    pos.lat, pos.lng,
                    this.siteCoord.lat, this.siteCoord.lng
                );
                if (dist <= this.ARRIVAL_RADIUS) this.arrivalTime = Date.now();
            }
        },

        jobStarted:   function() { this.jobStartTime = Date.now(); },
        jobCompleted: function() { this.jobEndTime   = Date.now(); },

        metrics: function() {
            var badge = this.worstAccuracy <= 25 ? 'High Accuracy'
                      : this.worstAccuracy <= 50 ? 'Normal'
                      : 'Verify';
            var arrivalMin = (this.arrivalTime && this.jobStartTime)
                ? Math.round((this.jobStartTime - this.arrivalTime) / 60000) : null;
            var dwellMin = (this.jobStartTime && this.jobEndTime)
                ? Math.round((this.jobEndTime - this.jobStartTime) / 60000) : null;
            return {
                accuracy_badge:          badge,
                arrival_confidence_min:  arrivalMin,
                dwell_minutes:           dwellMin,
                worst_fix_accuracy_m:    Math.round(this.worstAccuracy)
            };
        }
    };

    function haversineDistance(lat1, lng1, lat2, lng2) {
        var R = 6371000; // Earth radius in meters
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLng = (lng2 - lng1) * Math.PI / 180;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    // ── Adaptive Distance Filter ───────────────────────────
    // Adjusts the BG plugin's distance filter based on detected activity
    var activityDistanceFilter = {
        'IN_VEHICLE': 20,  // More frequent when driving
        'RUNNING': 10,
        'ON_FOOT': 10,
        'WALKING': 10,
        'STILL': 50,       // Very infrequent when still
        'UNKNOWN': 15
    };

    // Read installed app version from the native JavascriptInterface injected
    // by MainActivity. Falls back to null if the interface is not available
    // (older APK build without the interface). login.php uses this to decide
    // which banner state to show (up-to-date / update-available).
    var _nativeVersion = (window.MwNativeAndroid && typeof window.MwNativeAndroid.getVersion === 'function')
        ? window.MwNativeAndroid.getVersion()
        : null;

    window.MwNative = {
        isNative: true,
        appVersion: _nativeVersion,
        _bgWatchId: null,
        _currentActivity: 'UNKNOWN',

        /**
         * Open this app's OS settings page (so the user can toggle a denied
         * permission, e.g. Camera). Uses the Android app-details intent via the
         * App plugin; falls back to the generic Settings screen. Best-effort.
         */
        openAppSettings: function() {
            if (!App || typeof App.openUrl !== 'function') {
                console.warn('[MwNative] App.openUrl unavailable — cannot open settings');
                return;
            }
            var pkg = (window.Capacitor.getPlatform && window.Capacitor.getPlatform() === 'android')
                ? 'ca.mowology.crew' : null;   // must match applicationId in android/app/build.gradle
            var url = pkg
                ? 'intent://' + pkg + '#Intent;scheme=package;action=android.settings.APPLICATION_DETAILS_SETTINGS;end'
                : 'app-settings:';
            App.openUrl({ url: url }).catch(function() {
                App.openUrl({ url: 'intent://#Intent;action=android.settings.SETTINGS;end' })
                    .catch(function(err) { console.warn('[MwNative] openAppSettings failed:', err); });
            });
        },

        // ── Background GPS ──────────────────────────────────
        geo: {
            watchId: null,

            /**
             * Start background-capable GPS tracking.
             * Creates an Android foreground service with persistent notification.
             * Also starts the MwTracking resilience service.
             * Callback receives (position, error) — position is null on error.
             *
             * position shape: { lat, lng, accuracy, speed, heading, altitude, timestamp }
             */
            /**
             * Entry point used by every page. On a v2 APK the NATIVE engine owns capture and
             * upload, so the page adds no GPS watcher at all — it just makes sure the engine
             * has a fresh token and is running. Older APKs fall through to the legacy
             * JS-driven watcher.
             */
            startBackgroundTracking: function(callback, options) {
                var self = this;
                window.MwNative.engine.detect().then(function(version) {
                    if (version >= 2) {
                        self._removeAllWatchers();          // anything a pre-update page left running
                        window.MwNative.engine.start();
                    } else {
                        self._legacyStart(callback, options);
                    }
                });
            },

            _legacyStart: function(callback, options) {
                options = options || {};

                if (!BackgroundGeolocation) {
                    console.warn('[MwNative] BackgroundGeolocation plugin not available');
                    callback(null, { code: 'PLUGIN_MISSING', message: 'Background GPS plugin not installed' });
                    return;
                }

                // D6 — Adaptive distance filter at startup.
                // Query the current activity from MwTracking before we
                // pin a filter. This prevents the "10 m filter while
                // still" scenario that wakes the GPS chip every few
                // seconds in a parked truck. Falls back to the caller's
                // requested filter, then to 15 m if we know nothing.
                if (!options.distanceFilter && MwTracking && MwTracking.getHealth) {
                    MwTracking.getHealth().then(function (h) {
                        var act = (h && h.currentActivity) || 'UNKNOWN';
                        var filter = activityDistanceFilter[act] || 15;
                        window.MwNative._currentActivity = act;
                        console.log('[MwNative] Initial activity:', act, '→ distanceFilter', filter);
                        window.MwNative.geo._reallyStart(callback, filter);
                    }).catch(function () {
                        window.MwNative.geo._reallyStart(callback, 15);
                    });
                    return;
                }

                var distanceFilter = options.distanceFilter || 10;
                this._reallyStart(callback, distanceFilter);
            },

            /**
             * Watcher registry, persisted across page loads.
             *
             * This is a multi-page app: every navigation throws away the JS context, and
             * with it the watcher id. The native watcher keeps running at 1 Hz with nobody
             * listening, the next page adds another, and stopBackgroundTracking() could only
             * ever remove the CURRENT page's — so clock-out left GPS and the foreground
             * notification running. Ids live in localStorage so any page can remove them all.
             */
            _WATCHER_KEY: 'mw_bg_watcher_ids',
            _readWatcherIds: function() {
                try { return JSON.parse(localStorage.getItem(this._WATCHER_KEY) || '[]') || []; }
                catch (e) { return []; }
            },
            _writeWatcherIds: function(ids) {
                try { localStorage.setItem(this._WATCHER_KEY, JSON.stringify(ids)); } catch (e) { /* private mode */ }
            },
            _removeAllWatchers: function() {
                var ids = this._readWatcherIds();
                if (this.watchId !== null && ids.indexOf(this.watchId) === -1) ids.push(this.watchId);
                ids.forEach(function(id) {
                    try {
                        var r = BackgroundGeolocation.removeWatcher({ id: id });
                        if (r && r.catch) r.catch(function() { /* already gone */ });
                    } catch (e) { /* already gone */ }
                });
                this._writeWatcherIds([]);
                this.watchId = null;
                window.MwNative._bgWatchId = null;
                return ids.length;
            },

            /**
             * Internal — hand off to BackgroundGeolocation.addWatcher
             * with the chosen distance filter. Split out from the
             * public startBackgroundTracking so the async
             * getHealth() path can call back in cleanly.
             */
            _reallyStart: function(callback, distanceFilter) {
                var self = this;
                // Drop any watcher a previous page left running before adding ours.
                this._removeAllWatchers();
                BackgroundGeolocation.addWatcher({
                    backgroundTitle: 'Mowology GPS Tracking',
                    backgroundMessage: 'Tracking your location for crew management',
                    requestPermissions: true,
                    stale: false,
                    distanceFilter: distanceFilter
                }, function(location, error) {
                    if (error) {
                        if (error.code === 'NOT_AUTHORIZED') {
                            if (window.confirm('Background location is required for GPS tracking while the screen is off. Open settings?')) {
                                BackgroundGeolocation.openSettings();
                            }
                        }
                        callback(null, error);
                        return;
                    }

                    var pos = {
                        lat: location.latitude,
                        lng: location.longitude,
                        accuracy: location.accuracy,
                        speed: location.speed,
                        heading: location.bearing,
                        altitude: location.altitude,
                        timestamp: location.time || Date.now()
                    };

                    // Run through JS-side noise filter
                    if (!locationProcessor.process(pos)) {
                        return; // Filtered out
                    }

                    // Route replay — 1 point/min, tied to active visit if known
                    routeBuffer.add(pos, window.MwNative.pow._visitId || null);

                    // Arrival + dwell tracking
                    arrivalTracker.observe(pos);

                    // Store in native Room DB via MwTracking plugin
                    if (MwTracking) {
                        MwTracking.storePoint({
                            lat: pos.lat,
                            lng: pos.lng,
                            accuracy: pos.accuracy,
                            speed: pos.speed || 0,
                            heading: pos.heading || 0,
                            altitude: pos.altitude || 0,
                            provider: 'fused',
                            timestamp: pos.timestamp
                        }).catch(function(e) {
                            console.warn('[MwNative] Failed to store point:', e);
                        });
                    }

                    callback(pos, null);

                }).then(function(id) {
                    window.MwNative.geo.watchId = id;
                    window.MwNative._bgWatchId = id;
                    self._writeWatcherIds([id]);
                    console.log('[MwNative] Background GPS started, watcher ID:', id);
                }).catch(function(err) {
                    console.error('[MwNative] Failed to start background GPS:', err);
                    callback(null, { code: 'START_FAILED', message: String(err) });
                });
            },

            /**
             * Stop background GPS tracking and remove the foreground service notification.
             */
            stopBackgroundTracking: function() {
                if (!BackgroundGeolocation) return;
                var removed = this._removeAllWatchers();
                console.log('[MwNative] Background GPS stopped — removed ' + removed + ' watcher(s)');
                // Every clock-out path calls this, but only two pages also stopped the native
                // session — leaving its notification, wake lock and tracking_active=true behind
                // (so BootReceiver revived "tracking" after every reboot). Stop it here, once.
                if (MwTracking && typeof MwTracking.stopSession === 'function') {
                    try {
                        var r = MwTracking.stopSession();
                        if (r && r.catch) r.catch(function() {});
                    } catch (e) { /* plugin unavailable */ }
                }
            },

            /**
             * One-shot position capture (for clock-in/out GPS).
             * Returns a Promise resolving to { lat, lng, accuracy }.
             */
            getCurrentPosition: function() {
                if (!Geolocation) {
                    return Promise.reject(new Error('Geolocation plugin not available'));
                }
                return Geolocation.getCurrentPosition({
                    enableHighAccuracy: true,
                    timeout: 10000
                }).then(function(pos) {
                    return {
                        lat: pos.coords.latitude,
                        lng: pos.coords.longitude,
                        accuracy: pos.coords.accuracy
                    };
                });
            }
        },

        // ── MwTracking (custom plugin) ──────────────────────
        /**
         * Native tracking engine (APK 1.3.0+, MwTrackingService v2).
         *
         * The page's only jobs: get the native layer a token (it cannot read the httponly
         * session cookie), show the location disclosure before any permission prompt, and ask
         * for permissions in the order Android requires. Everything else — capture, queueing,
         * upload, geofences, obeying the server's stop — runs natively with no page loaded.
         */
        engine: {
            version: 0,
            _detecting: null,
            _starting: false,

            detect: function() {
                var self = this;
                if (this._detecting) return this._detecting;
                this._detecting = (MwTracking && typeof MwTracking.engineInfo === 'function')
                    ? MwTracking.engineInfo().then(function(i) {
                          self.version = (i && i.engineVersion) || 1;
                          return self.version;
                      }).catch(function() { self.version = 1; return 1; })
                    : Promise.resolve(MwTracking ? 1 : 0);
                return this._detecting;
            },

            _token: function() {
                return fetch('/api/team/tracking-token', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.MW_CSRF_TOKEN || '' },
                    body: '{}'
                }).then(function(r) { return r.ok ? r.json() : null; });
            },

            _api: function(token, method, query, body) {
                return fetch('/api/schedule/tracking' + (query || ''), {
                    method: method,
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                    body: body ? JSON.stringify(body) : undefined
                }).then(function(r) { return r.ok ? r.json() : null; });
            },

            /** Resolves true when it is OK to ask for permissions and start. */
            _ensureDisclosed: function(token) {
                var self = this;
                return this._api(token, 'GET', '?mode=consent').then(function(data) {
                    if (!data || !data.disclosure || !data.consent || data.consent.current) return true;
                    return self._showDisclosure(data.disclosure, !!data.consent.required).then(function(agreed) {
                        // null = this page can't show the disclosure; try again on the next one.
                        // The disclosure ALWAYS precedes the OS permission prompts.
                        if (agreed === null) return false;
                        if (!agreed) return false;
                        return self._api(token, 'POST', '', {
                            action: 'consent', version: data.disclosure.version,
                            device: { platform: 'android' }
                        }).then(function() { return true; });
                    });
                }).catch(function() { return true; });   // never block a shift on this fetch
            },

            /**
             * Built from the shared .mw-modal component (mowology-brand.css). On the few
             * standalone pages that don't load the brand stylesheet, wait for one that does
             * rather than show an unstyled wall of text — resolves null = "ask later".
             */
            _showDisclosure: function(d, required) {
                return new Promise(function(resolve) {
                    var probe = document.createElement('div');
                    probe.className = 'mw-modal-overlay';
                    document.body.appendChild(probe);
                    var styled = getComputedStyle(probe).position === 'fixed';
                    document.body.removeChild(probe);
                    if (!styled) { resolve(null); return; }

                    var esc = function(t) { var e = document.createElement('div'); e.textContent = t == null ? '' : String(t); return e.innerHTML; };
                    var overlay = document.createElement('div');
                    overlay.className = 'mw-modal-overlay show';
                    overlay.innerHTML =
                        '<div class="mw-modal mw-track-consent" role="dialog" aria-modal="true">' +
                          '<div class="mw-modal-header"><h3 class="mw-modal-title">' + esc(d.title) + '</h3></div>' +
                          '<div class="mw-track-consent-body">' +
                            '<p class="mw-track-consent-summary">' + esc(d.summary) + '</p>' +
                            (d.sections || []).map(function(sec) {
                                return '<h4 class="mw-track-consent-heading">' + esc(sec.heading) + '</h4><p>' + esc(sec.body) + '</p>';
                            }).join('') +
                          '</div>' +
                          '<div class="mw-track-consent-actions">' +
                            '<button type="button" class="btn btn-primary btn-block" data-act="agree">' + esc(d.agree_label || 'I agree') + '</button>' +
                            '<button type="button" class="btn btn-link btn-block" data-act="later">' + (required ? 'Not now — tracking stays off' : 'Not now') + '</button>' +
                          '</div>' +
                        '</div>';
                    overlay.addEventListener('click', function(ev) {
                        var act = ev.target && ev.target.getAttribute && ev.target.getAttribute('data-act');
                        if (!act) return;
                        document.body.removeChild(overlay);
                        resolve(act === 'agree');
                    });
                    document.body.appendChild(overlay);
                });
            },

            /**
             * Tracking only survives a pocket if ALL of these hold: location "Allow all the
             * time", and the app exempt from battery optimisation. The system prompts are easy
             * to tap past — on the first morning of 1.3.1 one phone ran on "While using" and both
             * skipped the battery prompt — so a clocked-in phone that is not fully set up gets a
             * full-screen checklist that stays until it is. It re-checks itself every 1.5 s and
             * whenever the app comes back from Settings, and closes on its own.
             *
             * It must never trap anyone: some phones cannot grant these (work profiles, OEM
             * quirks, a misreporting API). After 45 s a "continue anyway" link appears (once per
             * app session). The office still sees the gap: every upload reports the permission
             * and battery state to device_tracking_health.
             */
            _setupOk: function(p) {
                return !!(p && p.location && p.background && p.batteryOptimizationIgnored);
            },

            _setupGate: function() {
                if (document.getElementById('mw-setup-gate')) return;
                if (!MwTracking || typeof MwTracking.checkTrackingPermissions !== 'function') return;
                try { if (sessionStorage.getItem('mw_setup_gate_skipped')) return; } catch (e) {}

                var self = this;
                if (!document.getElementById('mw-setup-gate-css')) {
                    var css = document.createElement('style');
                    css.id = 'mw-setup-gate-css';
                    css.textContent =
                        '#mw-setup-gate{position:fixed;inset:0;z-index:2147483646;overflow-y:auto;box-sizing:border-box;' +
                            'padding:calc(env(safe-area-inset-top,0px) + 28px) 20px calc(env(safe-area-inset-bottom,0px) + 28px);' +
                            'background:var(--mw-forest);color:var(--mw-ink-0);font-family:system-ui,-apple-system,sans-serif;}' +
                        '#mw-setup-gate h2{margin:0 0 6px;font-size:1.45rem;font-weight:700;color:var(--mw-lime);}' +
                        '#mw-setup-gate p{margin:0 0 18px;font-size:.92rem;line-height:1.5;color:var(--mw-light);}' +
                        '.mw-sg-row{display:flex;align-items:flex-start;gap:12px;margin-bottom:12px;padding:14px;border-radius:14px;' +
                            'background:var(--mw-dark);}' +
                        '.mw-sg-dot{flex-shrink:0;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;' +
                            'font-weight:700;font-size:.9rem;background:var(--mw-orange);color:var(--mw-ink-0);}' +
                        '.mw-sg-row--ok .mw-sg-dot{background:var(--mw-lime);color:var(--mw-forest);}' +
                        '.mw-sg-main{flex:1;min-width:0;}' +
                        '.mw-sg-title{font-weight:700;font-size:1rem;}' +
                        '.mw-sg-how{margin-top:3px;font-size:.82rem;line-height:1.45;color:var(--mw-light);}' +
                        '.mw-sg-btn{display:block;width:100%;margin-top:10px;padding:13px;border:0;border-radius:10px;font-size:1rem;font-weight:700;' +
                            'background:var(--mw-lime);color:var(--mw-forest);}' +
                        '.mw-sg-row--ok .mw-sg-btn,.mw-sg-row--ok .mw-sg-how{display:none;}' +
                        '.mw-sg-skip{display:block;margin:22px auto 0;padding:10px;border:0;background:transparent;font-size:.82rem;' +
                            'text-decoration:underline;color:var(--mw-light);}';
                    document.head.appendChild(css);
                }

                var el = document.createElement('div');
                el.id = 'mw-setup-gate';
                el.setAttribute('role', 'dialog');
                el.setAttribute('aria-modal', 'true');
                el.innerHTML =
                    '<h2>Finish setting up tracking</h2>' +
                    '<p>Two phone settings decide whether location tracking keeps working with the phone in your pocket. ' +
                        'It only runs while you are clocked in. This screen closes by itself once both are done.</p>' +
                    '<div class="mw-sg-row" data-row="location"><span class="mw-sg-dot">1</span><div class="mw-sg-main">' +
                        '<div class="mw-sg-title">Location: Allow all the time</div>' +
                        '<div class="mw-sg-how">Tap the button, then choose <b>Permissions &rarr; Location &rarr; Allow all the time</b>. ' +
                            '&ldquo;While using the app&rdquo; stops working when the screen goes off.</div>' +
                        '<button type="button" class="mw-sg-btn" data-fix="location">Open location setting</button>' +
                    '</div></div>' +
                    '<div class="mw-sg-row" data-row="battery"><span class="mw-sg-dot">2</span><div class="mw-sg-main">' +
                        '<div class="mw-sg-title">Battery: Unrestricted</div>' +
                        '<div class="mw-sg-how">Tap the button and choose <b>Allow</b>. If nothing pops up: Settings &rarr; Apps &rarr; ' +
                            'Mowology Crew &rarr; Battery &rarr; <b>Unrestricted</b>. Without it the phone shuts the app down to save power.</div>' +
                        '<button type="button" class="mw-sg-btn" data-fix="battery">Allow battery use</button>' +
                    '</div></div>' +
                    '<div class="mw-sg-row" data-row="notifications"><span class="mw-sg-dot">3</span><div class="mw-sg-main">' +
                        '<div class="mw-sg-title">Notifications: On (recommended)</div>' +
                        '<div class="mw-sg-how">So the app can tell you when a job timer starts or stops by itself.</div>' +
                        '<button type="button" class="mw-sg-btn" data-fix="notifications">Turn on notifications</button>' +
                    '</div></div>' +
                    '<button type="button" class="mw-sg-skip" data-fix="skip" hidden>I can&rsquo;t change these on this phone &mdash; continue anyway</button>';

                var last = null, timer = null, closed = false;

                function paint(p) {
                    last = p || {};
                    var ok = {
                        location:      !!(last.location && last.background),
                        battery:       !!last.batteryOptimizationIgnored,
                        notifications: last.notifications !== false
                    };
                    Object.keys(ok).forEach(function(k) {
                        var row = el.querySelector('[data-row="' + k + '"]');
                        if (!row) return;
                        var dot = row.querySelector('.mw-sg-dot');
                        if (!dot.getAttribute('data-n')) dot.setAttribute('data-n', dot.textContent);
                        row.className = 'mw-sg-row' + (ok[k] ? ' mw-sg-row--ok' : '');
                        dot.textContent = ok[k] ? '✓' : dot.getAttribute('data-n');
                    });
                    if (self._setupOk(last)) close(true);
                }
                function check() {
                    if (closed) return;
                    MwTracking.checkTrackingPermissions().then(paint, function() {});
                }
                function close(done) {
                    if (closed) return;
                    closed = true;
                    clearInterval(timer);
                    document.removeEventListener('visibilitychange', check);
                    if (el.parentNode) el.parentNode.removeChild(el);
                    document.body.style.overflow = '';
                    if (done) {
                        // Everything is granted now — make sure the engine is actually running with it.
                        self._starting = false;
                        self.start();
                    }
                }

                el.addEventListener('click', function(ev) {
                    var fix = ev.target && ev.target.getAttribute && ev.target.getAttribute('data-fix');
                    if (!fix) return;
                    if (fix === 'battery') {
                        MwTracking.requestBatteryExemption().then(function() { setTimeout(check, 800); }, function() {});
                    } else if (fix === 'skip') {
                        try { sessionStorage.setItem('mw_setup_gate_skipped', '1'); } catch (e) {}
                        try {
                            MwTracking.addComplianceEvent({
                                eventType: 'tracking_setup_skipped',
                                reason: JSON.stringify(last || {})
                            });
                        } catch (e) {}
                        close(false);
                    } else {
                        // location / notifications: the permission flow asks for whatever is still
                        // missing; on Android 11+ "all the time" can only be chosen in Settings, so
                        // fall back to opening the app's settings page when the prompt changes nothing.
                        var before = JSON.stringify(last || {});
                        MwTracking.requestTrackingPermissions().then(function(p) {
                            paint(p);
                            if (JSON.stringify(p || {}) === before && BackgroundGeolocation && BackgroundGeolocation.openSettings) {
                                BackgroundGeolocation.openSettings();
                            }
                        }, function() {
                            if (BackgroundGeolocation && BackgroundGeolocation.openSettings) BackgroundGeolocation.openSettings();
                        });
                    }
                });

                document.body.appendChild(el);
                document.body.style.overflow = 'hidden';
                document.addEventListener('visibilitychange', check);   // back from Settings
                timer = setInterval(check, 1500);
                setTimeout(function() {
                    var skip = el.querySelector('[data-fix="skip"]');
                    if (skip && !closed) skip.hidden = false;
                }, 45000);
                check();
            },

            start: function() {
                var self = this;
                if (this._starting) return;
                this._starting = true;
                this._token().then(function(t) {
                    if (!t || !t.success || !t.token) return null;      // not logged in / tracking off for this user
                    return self._ensureDisclosed(t.token).then(function(ok) {
                        if (!ok) return null;
                        return MwTracking.requestTrackingPermissions().then(function(perms) {
                            // Anything less than the full set gets the blocking setup screen. Start
                            // the session first when we can, so tracking runs while they fix the rest.
                            if (!self._setupOk(perms)) self._setupGate();
                            if (!perms || !perms.location) {
                                console.warn('[MwNative] location permission refused — native tracking not started');
                                return null;
                            }
                            return MwTracking.startSession({ token: t.token, userId: t.user_id });
                        });
                    });
                }).catch(function(e) {
                    console.warn('[MwNative] engine start failed:', e);
                }).then(function() { self._starting = false; });
            }
        },

        tracking: {
            /**
             * Start a tracking session. Call when user clocks in.
             * This starts the resilience service (START_STICKY, wake lock, boot receiver)
             * and activity recognition.
             */
            startSession: function(userId, sessionId) {
                if (!MwTracking) {
                    console.warn('[MwNative] MwTracking plugin not available');
                    return Promise.resolve({ started: false });
                }
                // v2 APK: a session needs a token, which only the engine adapter can fetch.
                if (window.MwNative.engine.version >= 2) {
                    window.MwNative.engine.start();
                    return Promise.resolve({ started: true, engineVersion: 2 });
                }
                return MwTracking.startSession({
                    userId: userId,
                    sessionId: sessionId || String(Date.now())
                });
            },

            /**
             * Stop the tracking session. Call when user clocks out.
             */
            stopSession: function() {
                if (!MwTracking) return Promise.resolve({ stopped: false });
                return MwTracking.stopSession();
            },

            /**
             * Get health diagnostics data.
             * Returns: { isTrackingActive, lastFixTime, lastFixAccuracy,
             *            currentActivity, pointsUnsyncedCount, batteryOptimizationIgnored,
             *            gpsEnabled, oemBatteryInfo, ... }
             */
            getHealth: function() {
                if (!MwTracking) return Promise.resolve({});
                return MwTracking.getHealth();
            },

            /**
             * Request battery optimization exemption (shows system dialog).
             */
            requestBatteryExemption: function() {
                if (!MwTracking) return Promise.resolve({ requested: false });
                return MwTracking.requestBatteryExemption();
            },

            /**
             * Set today's job sites for geofence-like monitoring.
             * @param {Array} sites - [{ visitId, jobId, lat, lng, radiusMeters, title }]
             */
            setJobSites: function(sites) {
                if (!MwTracking) return Promise.resolve({ sitesSet: 0 });
                return MwTracking.setJobSites({ sites: sites });
            },

            /**
             * Add a compliance event.
             * @param {string} eventType - ARRIVAL, DEPARTURE, CLOCK_IN, CLOCK_OUT, JOB_START, JOB_STOP, MANUAL_OVERRIDE
             */
            addComplianceEvent: function(eventType, data) {
                if (!MwTracking) return Promise.resolve({ saved: false });
                return MwTracking.addComplianceEvent(Object.assign({ eventType: eventType }, data || {}));
            },

            /**
             * Get unsynced points from native Room DB.
             */
            getUnsyncedPoints: function(limit) {
                if (!MwTracking) return Promise.resolve({ points: [], count: 0 });
                return MwTracking.getUnsyncedPoints({ limit: limit || 200 });
            },

            /**
             * Mark points as synced in native Room DB.
             */
            markSynced: function(ids) {
                if (!MwTracking) return Promise.resolve({ marked: 0 });
                return MwTracking.markSynced({ ids: ids });
            },

            // ── Event Listeners ─────────────────────────────
            _listeners: {},

            /**
             * Listen for native tracking events.
             * Events: 'locationUpdate', 'geofenceEvent', 'activityChanged', 'trackingWarning'
             */
            on: function(event, callback) {
                if (!this._listeners[event]) {
                    this._listeners[event] = [];
                }
                this._listeners[event].push(callback);

                // Register with the native plugin if available
                if (MwTracking && MwTracking.addListener) {
                    MwTracking.addListener(event, function(data) {
                        callback(data);
                    });
                }
            }
        },

        // ── Local Notifications ─────────────────────────────
        notifications: {
            _initialized: false,

            init: function() {
                if (this._initialized || !LocalNotifications) return;
                this._initialized = true;
                LocalNotifications.requestPermissions().then(function(result) {
                    console.log('[MwNative] Notification permission:', result.display);
                }).catch(function(err) {
                    console.warn('[MwNative] Notification permission error:', err);
                });
            },

            notify: function(title, body, id) {
                if (!LocalNotifications) return;
                this.init();
                LocalNotifications.schedule({
                    notifications: [{
                        title: title,
                        body: body,
                        id: id || Date.now(),
                        schedule: { at: new Date() },
                        smallIcon: 'ic_stat_mowology',
                        iconColor: '#2D8659'
                    }]
                }).catch(function(err) {
                    console.warn('[MwNative] Notification failed:', err);
                });
            }
        },

        // ── Push Notifications (FCM) ─────────────────────────
        // Registers this device for Firebase Cloud Messaging and reports the
        // resulting token to the backend so PushDispatcher can address this
        // device (job-completed, job-assigned, etc — mirrors the iOS
        // DeviceTokenService flow, POSTing to the same /api/device/token
        // endpoint). Inert until a real Firebase project is provisioned —
        // Play Services just never delivers a token in the meantime, so
        // always calling init() is safe.
        push: {
            _initialized: false,

            init: function() {
                if (this._initialized || !PushNotifications) return;
                this._initialized = true;

                PushNotifications.addListener('registration', function(tokenResult) {
                    window.MwNative.push._registerToken(tokenResult.value);
                });
                PushNotifications.addListener('registrationError', function(err) {
                    console.warn('[MwNative] Push registration error:', err);
                });

                PushNotifications.requestPermissions().then(function(result) {
                    if (result.receive === 'granted') {
                        PushNotifications.register();
                    } else {
                        console.log('[MwNative] Push permission not granted:', result.receive);
                    }
                }).catch(function(err) {
                    console.warn('[MwNative] Push permission request failed:', err);
                });
            },

            // Re-registers (without re-prompting for permission) on every
            // foreground transition — retry coverage for a token that failed
            // to arrive earlier, mirrors iOS's DeviceTokenService behavior.
            // Safe/idempotent: the backend upsert just refreshes last_seen_at
            // if the token is unchanged.
            reregister: function() {
                if (!this._initialized || !PushNotifications) return;
                PushNotifications.register();
            },

            _registerToken: function(token) {
                fetch('/api/device/token', {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ device_token: token, platform: 'android' })
                }).then(function(r) { return r.json(); }).then(function(data) {
                    if (!data.success) console.warn('[MwNative] Device token registration rejected:', data.error);
                }).catch(function(err) {
                    console.warn('[MwNative] Device token registration failed:', err);
                });
            }
        },

        // ── Network Status ──────────────────────────────────
        network: {
            isOnline: true,
            _listeners: [],

            init: function() {
                if (!Network) return;
                var self = this;

                Network.getStatus().then(function(status) {
                    self.isOnline = status.connected;
                    console.log('[MwNative] Network status:', status.connected ? 'online' : 'offline');
                }).catch(function() {
                    self.isOnline = true;
                });

                Network.addListener('networkStatusChange', function(status) {
                    self.isOnline = status.connected;
                    console.log('[MwNative] Network changed:', status.connected ? 'online' : 'offline');
                    self._listeners.forEach(function(fn) {
                        try { fn(status.connected); } catch(e) { /* silent */ }
                    });
                });
            },

            onStatusChange: function(callback) {
                this._listeners.push(callback);
            }
        }
    };

    // ── D7 — App lifecycle (pause / resume) ─────────────────
    // REMOVED 2026-09-19: on 'pause' this used to swap the live watcher for a "battery
    // saving" one whose callback only wrote to the native Room store. But nothing ever
    // uploads that store (its sync cannot authenticate), and the swap disconnected the
    // fixes from the widget's uploader — so after the FIRST screen-off the server got the
    // last pre-sleep position re-sent forever with fresh timestamps. The original watcher
    // now simply keeps running in the background; useLegacyBridge keeps its callback alive.

    // ── Hardware Back Button (Android) ──────────────────────
    // Pages can call e.preventDefault() on the 'mw-native-back' event to
    // take over the back action. If no handler claims it, we close any
    // open menu overlay first; otherwise fall through to history.back()
    // or exit the app at the root of the stack.
    if (App && App.addListener) {
        App.addListener('backButton', function (data) {
            var ev = new CustomEvent('mw-native-back', {
                cancelable: true,
                detail: { canGoBack: !!(data && data.canGoBack) }
            });
            var claimed = !document.dispatchEvent(ev); // preventDefault() → false → claimed
            if (claimed) return;

            // Default 1: close any open menu/overlay
            var openMenu = document.querySelector(
                '.mw-mobile-menu-overlay.open, .hb-menu-overlay.open, .dp-overlay.open'
            );
            if (openMenu) {
                openMenu.classList.remove('open');
                document.body.style.overflow = '';
                return;
            }

            // Default 2: normal browser back, or exit at root
            if (data && data.canGoBack) {
                window.history.back();
            } else if (App.exitApp) {
                App.exitApp();
            }
        });
        console.log('[MwNative] Hardware back button handler registered');
    }

    // ── Auto-initialize ─────────────────────────────────────
    window.MwNative.network.init();
    window.MwNative.notifications.init();
    window.MwNative.push.init();

    // Re-check registration on every foreground transition, mirroring the
    // iOS DeviceTokenService's "re-register on every foreground" retry
    // coverage — cheap and idempotent server-side (upsert keyed on token).
    if (App && App.addListener) {
        App.addListener('resume', function () {
            window.MwNative.push.reregister();
        });
    }

    // Listen for activity changes and adjust the BG plugin's distance filter
    if (MwTracking && MwTracking.addListener) {
        // v2 engine → page. The engine acts on its own; these only keep the UI honest.
        MwTracking.addListener('trackingStopped', function(data) {
            console.log('[MwNative] Server ended tracking:', data && data.reason);
            document.dispatchEvent(new CustomEvent('mw-tracking-stopped', { detail: data || {} }));
        });
        MwTracking.addListener('autoStarted', function(data) {
            document.dispatchEvent(new CustomEvent('mw-auto-started', { detail: data || {} }));
        });
        MwTracking.addListener('autoStopped', function(data) {
            document.dispatchEvent(new CustomEvent('mw-auto-stopped', { detail: data || {} }));
        });

        MwTracking.addListener('activityChanged', function(data) {
            window.MwNative._currentActivity = data.activity;
            console.log('[MwNative] Activity changed:', data.activity);

            // Restart watcher with new distance filter if currently tracking
            var newFilter = activityDistanceFilter[data.activity] || 15;
            if (window.MwNative._bgWatchId !== null) {
                // Can't change distance filter on existing watcher —
                // must remove and re-add. Only do this for major transitions.
                var currentFilter = activityDistanceFilter[window.MwNative._currentActivity] || 15;
                if (Math.abs(newFilter - currentFilter) >= 10) {
                    console.log('[MwNative] Restarting watcher with distance filter:', newFilter);
                    // Dispatch event so time-clock-widget can restart tracking
                    document.dispatchEvent(new CustomEvent('mw-activity-changed', {
                        detail: { activity: data.activity, distanceFilter: newFilter }
                    }));
                }
            }
        });

        // Listen for stale location warnings
        MwTracking.addListener('trackingWarning', function(data) {
            console.warn('[MwNative] Tracking warning:', data.type, data.message);
            // Dispatch event for time-clock-widget to handle
            document.dispatchEvent(new CustomEvent('mw-tracking-warning', {
                detail: data
            }));
        });

        // Listen for geofence events
        MwTracking.addListener('geofenceEvent', function(data) {
            console.log('[MwNative] Geofence event:', data.event, 'at', data.title);
            document.dispatchEvent(new CustomEvent('mw-geofence-event', {
                detail: data
            }));
        });
    }

    // Save MOWOSESS cookie to SharedPreferences for WorkManager sync.
    // WorkManager runs outside the WebView and needs the auth cookie so that
    // tracking-sync.php and pow-gps-sync.php can authenticate the request.
    // Note: session is named MOWOSESS (not PHPSESSID) per session_config.php.
    if (MwTracking && MwTracking.storeSessionCookie) {
        var sessionCookieName = 'MOWOSESS';
        var sessionValue = '';
        document.cookie.split(';').forEach(function(c) {
            var trimmed = c.trim();
            if (trimmed.indexOf(sessionCookieName + '=') === 0) {
                sessionValue = trimmed.substring(sessionCookieName.length + 1);
            }
        });
        if (sessionValue) {
            MwTracking.storeSessionCookie({
                name: sessionCookieName,
                value: sessionValue
            }).then(function() {
                console.log('[MwNative] Session cookie saved to SharedPreferences for WorkManager');
            }).catch(function(e) {
                console.warn('[MwNative] Failed to save session cookie:', e);
            });
        } else {
            console.warn('[MwNative] MOWOSESS cookie not found in document.cookie — WorkManager sync will not authenticate');
        }
    }

    console.log('[MwNative] Capacitor bridge v2 initialized (with MwTracking)');

    // Signal to photo-queue.js (and any other modules) that the bridge is ready.
    // photo-queue.js registers MwNative.network.onStatusChange in response to this
    // event when the bridge loads after photo-queue.js has already run.
    document.dispatchEvent(new CustomEvent('mw-capacitor-ready', {
        detail: { MwNative: window.MwNative }
    }));

    // ── Proof of Work — Visit GPS Integration ──────────────────────────────
    // When the visit-work page is active, pump GPS points into the PoW GPS
    // sync buffer. The visit-work page's JS owns the IndexedDB buffer and
    // syncs to /crm/api/pow-gps-sync.php. This bridge fires the
    // 'mw-visit-gps-point' custom event so visit-work.php can receive
    // native GPS without re-implementing the Capacitor plugin calls.
    //
    // Usage (from visit-work.php):
    //   document.addEventListener('mw-visit-gps-point', function(e) {
    //     var pos = e.detail; // { lat, lng, accuracy, speed, heading, timestamp }
    //   });

    window.MwNative.pow = {
        _visitId: null,
        _active:  false,
        _locationListenerAttached: false,

        /**
         * Start Proof-of-Work GPS emission for a specific visit.
         * Piggy-backs on the existing background tracking watcher.
         * @param {number} visitId
         */
        _inactivityTimer: null,

        startVisitTracking: function(visitId) {
            if (this._active) return;
            this._visitId = visitId;
            this._active  = true;
            console.log('[MwNative.pow] Visit tracking started for visit', visitId);

            // Inactivity check — fires every 5 min while visit is active.
            // If no accepted fix for >8 min, nudge the crew via local notification.
            var self = this;
            this._inactivityTimer = setInterval(function() {
                if (!self._active) return;
                if (routeBuffer.minutesSinceLastPing() > 8) {
                    window.MwNative.notifications.notify(
                        'Mowology GPS',
                        'GPS tracking paused. Open the app to resume.',
                        99901
                    );
                }
            }, 5 * 60 * 1000);

            // If background tracking is already running (from clock-in session),
            // hook into the existing stream via the activityChanged/location events.
            // Otherwise start a fresh low-distanceFilter watcher for walk-tracking.
            if (window.MwNative._bgWatchId === null) {
                window.MwNative.geo.startBackgroundTracking(function(pos, err) {
                    if (err || !pos) return;
                    window.MwNative.pow._emit(pos);
                }, { distanceFilter: 5 }); // 5m for walk-level granularity
            } else {
                // Existing watcher active — listen via MwTracking native events.
                // Guard prevents duplicate listeners if startVisitTracking is
                // called more than once in a session (e.g., after a reconnect).
                if (!this._locationListenerAttached && MwTracking && MwTracking.addListener) {
                    this._locationListenerAttached = true;
                    MwTracking.addListener('locationUpdate', function(data) {
                        if (!window.MwNative.pow._active) return;
                        var pos = {
                            lat:       data.latitude  || data.lat,
                            lng:       data.longitude || data.lng,
                            accuracy:  data.accuracy,
                            speed:     data.speed     || 0,
                            heading:   data.bearing   || data.heading || 0,
                            timestamp: data.time      || Date.now()
                        };
                        window.MwNative.pow._emit(pos);
                    });
                }
            }
        },

        /**
         * Stop PoW visit GPS emission.
         */
        stopVisitTracking: function() {
            if (!this._active) return;
            this._active  = false;
            this._visitId = null;
            if (this._inactivityTimer) {
                clearInterval(this._inactivityTimer);
                this._inactivityTimer = null;
            }
            console.log('[MwNative.pow] Visit tracking stopped');
            // Note: do NOT stop the background watcher here — the clock-in
            // session may still need it. The visit-work.php JS handles
            // the final GPS flush to the server.
        },

        /**
         * Emit a GPS position as a custom DOM event.
         * visit-work.php listens for 'mw-visit-gps-point'.
         */
        _emit: function(pos) {
            if (!this._active) return;
            document.dispatchEvent(new CustomEvent('mw-visit-gps-point', {
                detail: {
                    lat:       pos.lat,
                    lng:       pos.lng,
                    accuracy:  pos.accuracy,
                    speed:     pos.speed     || 0,
                    heading:   pos.heading   || 0,
                    altitude:  pos.altitude  || 0,
                    timestamp: pos.timestamp || Date.now(),
                    source:    'native_bg',
                    visit_id:  this._visitId
                }
            }));
        }
    };

    // ── Route + Accountability Public API ──────────────────────────────────
    // Called by schedule-pill-workflow.js and clock-in/out handlers.
    //
    //   MwNative.route.setJobSite(lat, lng)   — configure arrival tracker
    //   MwNative.route.jobStarted()            — record job-start timestamp
    //   MwNative.route.jobCompleted()          — record job-end timestamp
    //   MwNative.route.points(visitId)         — get route-replay polyline array
    //   MwNative.route.metrics()               — get { accuracy_badge, arrival_confidence_min, dwell_minutes }
    //   MwNative.route.reset(visitId)          — clear route buffer for a visit
    window.MwNative.route = {
        setJobSite:   function(lat, lng)  { arrivalTracker.configure(lat, lng); },
        jobStarted:   function()          { arrivalTracker.jobStarted();   },
        jobCompleted: function()          { arrivalTracker.jobCompleted(); },
        points:       function(visitId)   { return routeBuffer.getPoints(visitId); },
        metrics:      function()          { return arrivalTracker.metrics(); },
        reset:        function(visitId)   { routeBuffer.reset(visitId); }
    };

    // Auto-detect visit page and start tracking
    (function() {
        var match = window.location.pathname.match(/visit-work\.php/);
        if (!match) return;
        var params   = new URLSearchParams(window.location.search);
        var visitId  = parseInt(params.get('id') || '0', 10);
        var statusEl = document.getElementById('pow-status');
        if (visitId && statusEl && statusEl.value === 'in_progress') {
            // Small delay to let page JS initialize first
            setTimeout(function() {
                window.MwNative.pow.startVisitTracking(visitId);
                console.log('[MwNative.pow] Auto-started visit tracking, visit', visitId);
            }, 800);
        }
    })();

    // ── Force Update Check ──────────────────────────────────────────────────
    // Compare the installed build against the server's minimum required version.
    // If the installed version is too old (or force_update=true on the server),
    // inject a full-screen overlay the crew CANNOT dismiss — they must download
    // the new APK to continue using the app.
    (function() {
        var installed = _nativeVersion; // string like "1.1.0" from MwNativeAndroid.getVersion()

        fetch('/crm/api/app-version.php', { method: 'GET', cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var android = data.android || data; // handle both nested and legacy flat format
                var minVersion  = android.min_version || android.version || null;
                var forceUpdate = android.force_update === true;

                if (forceUpdate) {
                    _showUpdateOverlay(android);
                    return;
                }

                // If we can't read the installed version, allow through — avoids
                // blocking on older APKs that predate the getVersion() interface.
                if (!minVersion || !installed) return;

                if (_semverLessThan(installed, minVersion)) {
                    _showUpdateOverlay(android);
                }
            })
            .catch(function() {
                // Network error — never block the app, crew may be offline
            });
    })();

    function _semverLessThan(a, b) {
        var ap = (a || '0').split('.').map(Number);
        var bp = (b || '0').split('.').map(Number);
        for (var i = 0; i < 3; i++) {
            var av = ap[i] || 0, bv = bp[i] || 0;
            if (av < bv) return true;
            if (av > bv) return false;
        }
        return false;
    }

    function _showUpdateOverlay(data) {
        if (document.getElementById('mw-force-update')) return;

        var apkUrl  = data.apk_url || '/crm/downloads/mowology-crew.apk';
        var version = data.version ? ' v' + data.version : '';
        var notes   = data.release_notes
            ? '<p style="margin:0 0 1.5rem;font-size:.83rem;color:#93c9b8;max-width:300px;line-height:1.55;">'
              + data.release_notes.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</p>'
            : '<div style="margin-bottom:1.5rem;"></div>';

        var el = document.createElement('div');
        el.id  = 'mw-force-update';
        el.setAttribute('style',
            'position:fixed;inset:0;background:#0D3B2E;z-index:2147483647;' +
            'display:flex;flex-direction:column;align-items:center;justify-content:center;' +
            'padding:2rem 1.5rem;box-sizing:border-box;text-align:center;' +
            'color:#fff;font-family:system-ui,-apple-system,sans-serif;');

        el.innerHTML =
            '<img src="/assets/favicon/android-chrome-192x192.png" ' +
                'style="width:80px;height:80px;border-radius:20px;margin-bottom:1.25rem;" alt="">' +
            '<h2 style="margin:0 0 .4rem;font-size:1.5rem;color:#7FD858;font-weight:700;">Update Required</h2>' +
            '<p style="margin:0 0 .9rem;font-size:1rem;color:#c8e8de;">Mowology Crew' + version + ' is now available.</p>' +
            notes +
            '<a href="' + apkUrl + '" ' +
                'style="display:inline-block;background:#7FD858;color:#0D3B2E;font-weight:700;' +
                'padding:.85rem 2.5rem;border-radius:10px;font-size:1rem;text-decoration:none;' +
                '-webkit-tap-highlight-color:transparent;min-width:200px;">' +
                'Download Update' +
            '</a>' +
            // Android downloads the file but does NOT install it by itself. Without these steps
            // people tap Download, land back on this screen and assume it failed (2026-09-21).
            '<ol style="margin:1.4rem 0 0;padding:0 0 0 1.2rem;max-width:300px;text-align:left;' +
                'font-size:.85rem;line-height:1.6;color:#c8e8de;">' +
                '<li>Tap <b>Download Update</b>.</li>' +
                '<li>When it finishes, pull down the notification bar and tap the downloaded file ' +
                    '(also in Files &rarr; Downloads).</li>' +
                '<li>If asked, choose <b>Settings &rarr; Allow from this source</b>, go back and tap <b>Install</b>.</li>' +
                '<li>Open the app again and sign in.</li>' +
            '</ol>' +
            '<p style="margin:1.1rem 0 0;font-size:.75rem;color:#5a8870;">You must update to continue using this app.</p>';

        document.body.style.overflow = 'hidden';
        document.body.appendChild(el);
        console.log('[MwNative] Force update overlay shown — installed:', installed, 'required:', data.min_version || data.version);
    }

})();
