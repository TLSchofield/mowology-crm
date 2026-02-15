/**
 * Mowology Capacitor Bridge
 * ──────────────────────────
 * Self-guarding module: no-op in browsers, initializes window.MwNative
 * when running inside the Capacitor native Android shell.
 *
 * Provides:
 *   MwNative.geo          — background GPS + one-shot position
 *   MwNative.notifications — local push notifications
 *   MwNative.network      — online/offline detection
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

    window.MwNative = {
        isNative: true,

        // ── Background GPS ──────────────────────────────────
        geo: {
            watchId: null,

            /**
             * Start background-capable GPS tracking.
             * Creates an Android foreground service with persistent notification.
             * Callback receives (position, error) — position is null on error.
             *
             * position shape: { lat, lng, accuracy, speed, heading, timestamp }
             */
            startBackgroundTracking: function(callback) {
                if (!BackgroundGeolocation) {
                    console.warn('[MwNative] BackgroundGeolocation plugin not available');
                    callback(null, { code: 'PLUGIN_MISSING', message: 'Background GPS plugin not installed' });
                    return;
                }

                BackgroundGeolocation.addWatcher({
                    backgroundTitle: 'Mowology GPS Tracking',
                    backgroundMessage: 'Tracking your location for crew management',
                    requestPermissions: true,
                    stale: false,
                    distanceFilter: 10 // meters — only fire when moved 10m+
                }, function(location, error) {
                    if (error) {
                        if (error.code === 'NOT_AUTHORIZED') {
                            // Prompt user to open Android settings for background location
                            if (window.confirm('Background location is required for GPS tracking while the screen is off. Open settings?')) {
                                BackgroundGeolocation.openSettings();
                            }
                        }
                        callback(null, error);
                        return;
                    }
                    callback({
                        lat: location.latitude,
                        lng: location.longitude,
                        accuracy: location.accuracy,
                        speed: location.speed,
                        heading: location.bearing,
                        timestamp: location.time
                    }, null);
                }).then(function(id) {
                    window.MwNative.geo.watchId = id;
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

        // ── Local Notifications ─────────────────────────────
        notifications: {
            _initialized: false,

            /**
             * Request notification permissions (call once on app start).
             */
            init: function() {
                if (this._initialized || !LocalNotifications) return;
                this._initialized = true;
                LocalNotifications.requestPermissions().then(function(result) {
                    console.log('[MwNative] Notification permission:', result.display);
                }).catch(function(err) {
                    console.warn('[MwNative] Notification permission error:', err);
                });
            },

            /**
             * Fire a local notification immediately.
             * @param {string} title - Notification title
             * @param {string} body  - Notification body text
             * @param {number} id    - Unique notification ID (prevents duplicates)
             */
            notify: function(title, body, id) {
                if (!LocalNotifications) return;
                this.init(); // Ensure permissions requested
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

            /**
             * Initialize network monitoring. Auto-called on bridge load.
             */
            init: function() {
                if (!Network) return;
                var self = this;

                Network.getStatus().then(function(status) {
                    self.isOnline = status.connected;
                    console.log('[MwNative] Network status:', status.connected ? 'online' : 'offline');
                }).catch(function() {
                    self.isOnline = true; // Assume online if check fails
                });

                Network.addListener('networkStatusChange', function(status) {
                    var wasOnline = self.isOnline;
                    self.isOnline = status.connected;
                    console.log('[MwNative] Network changed:', status.connected ? 'online' : 'offline');

                    self._listeners.forEach(function(fn) {
                        try { fn(status.connected); } catch(e) { /* silent */ }
                    });
                });
            },

            /**
             * Register a callback for network status changes.
             * @param {function} callback - receives boolean (true = online)
             */
            onStatusChange: function(callback) {
                this._listeners.push(callback);
            }
        }
    };

    // Auto-initialize network monitoring
    window.MwNative.network.init();

    // Auto-initialize notifications (request permission early)
    window.MwNative.notifications.init();

    console.log('[MwNative] Capacitor bridge initialized');

})();
