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
    var Network = Plugins.Network;
    var MwTracking = Plugins.MwTracking; // Custom plugin

    // ── Location Processor (JS-side noise filter) ──────────
    // Supplements the native accuracy gating with JS-side smoothing
    var locationProcessor = {
        ACCURACY_THRESHOLD: 50,  // meters — reject fixes worse than this
        SPEED_MAX_MPS: 42,       // ~150 km/h — reject teleports
        MIN_DISTANCE: 5,         // meters — skip tiny jitters when still
        lastAccepted: null,
        stationaryCount: 0,

        process: function(pos) {
            // Accuracy gate
            if (pos.accuracy > this.ACCURACY_THRESHOLD) {
                return false;
            }

            if (this.lastAccepted) {
                var dist = haversineDistance(
                    this.lastAccepted.lat, this.lastAccepted.lng,
                    pos.lat, pos.lng
                );
                var timeDelta = (pos.timestamp - this.lastAccepted.timestamp) / 1000;

                // Speed sanity check
                if (timeDelta > 0 && dist / timeDelta > this.SPEED_MAX_MPS) {
                    return false; // GPS teleport
                }

                // Jitter filter when stationary
                if (dist < this.MIN_DISTANCE) {
                    this.stationaryCount++;
                    // Accept every 10th point as presence signal
                    if (this.stationaryCount % 10 !== 0) {
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

    window.MwNative = {
        isNative: true,
        _bgWatchId: null,
        _currentActivity: 'UNKNOWN',

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
            startBackgroundTracking: function(callback, options) {
                options = options || {};

                if (!BackgroundGeolocation) {
                    console.warn('[MwNative] BackgroundGeolocation plugin not available');
                    callback(null, { code: 'PLUGIN_MISSING', message: 'Background GPS plugin not installed' });
                    return;
                }

                var distanceFilter = options.distanceFilter || 10;

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
                if (this.watchId !== null && BackgroundGeolocation) {
                    BackgroundGeolocation.removeWatcher({ id: this.watchId });
                    console.log('[MwNative] Background GPS stopped, watcher ID:', this.watchId);
                    this.watchId = null;
                    window.MwNative._bgWatchId = null;
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

    // ── Auto-initialize ─────────────────────────────────────
    window.MwNative.network.init();
    window.MwNative.notifications.init();

    // Listen for activity changes and adjust the BG plugin's distance filter
    if (MwTracking && MwTracking.addListener) {
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

        /**
         * Start Proof-of-Work GPS emission for a specific visit.
         * Piggy-backs on the existing background tracking watcher.
         * @param {number} visitId
         */
        startVisitTracking: function(visitId) {
            if (this._active) return;
            this._visitId = visitId;
            this._active  = true;
            console.log('[MwNative.pow] Visit tracking started for visit', visitId);

            // If background tracking is already running (from clock-in session),
            // hook into the existing stream via the activityChanged/location events.
            // Otherwise start a fresh low-distanceFilter watcher for walk-tracking.
            if (window.MwNative._bgWatchId === null) {
                window.MwNative.geo.startBackgroundTracking(function(pos, err) {
                    if (err || !pos) return;
                    window.MwNative.pow._emit(pos);
                }, { distanceFilter: 5 }); // 5m for walk-level granularity
            } else {
                // Existing watcher active — listen via MwTracking native events
                if (MwTracking && MwTracking.addListener) {
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

})();
