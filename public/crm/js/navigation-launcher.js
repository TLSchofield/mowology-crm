/**
 * Navigation Launcher — Reliable External Navigation for Web + Capacitor
 *
 * Handles building Google Maps URLs/intents and launching turn-by-turn
 * navigation. Prefers lat/lng over address strings. Uses Capacitor
 * App.openUrl() on Android, window.open() on web.
 *
 * Public API:
 *   MwNavLauncher.launchNavigation(stop)           — navigate to a single stop
 *   MwNavLauncher.launchMultiStopNavigation(stops)  — navigate through ordered stops
 *   MwNavLauncher.buildNavigationUrl(stop)          — get URL without launching
 *   MwNavLauncher.buildMultiStopUrl(stops)           — get multi-stop URL
 *
 * @package Mowology CRM
 */
var MwNavLauncher = (function() {
    'use strict';

    // ── Debug logging ──
    var DEBUG = (typeof MW_DEBUG !== 'undefined' && MW_DEBUG) ||
                (typeof localStorage !== 'undefined' && localStorage.getItem('mw_nav_debug') === '1');

    function log() {
        if (!DEBUG) return;
        var args = ['[NavLauncher]'].concat(Array.prototype.slice.call(arguments));
        console.log.apply(console, args);
    }

    function warn() {
        var args = ['[NavLauncher]'].concat(Array.prototype.slice.call(arguments));
        console.warn.apply(console, args);
    }

    // ── Platform Detection ──

    function isCapacitor() {
        return !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform());
    }

    function isAndroid() {
        return isCapacitor() && /android/i.test(navigator.userAgent);
    }

    function isIos() {
        return /iPhone|iPad|iPod/i.test(navigator.userAgent);
    }

    // ── URL Building ──

    /**
     * Build a Google Maps navigation URL for a single stop.
     * Prefers lat/lng; falls back to address string.
     *
     * @param {Object} stop - { lat, lng, address, label }
     * @param {Object} [options] - { mode: 'driving'|'walking'|'bicycling', navigate: true }
     * @returns {string|null} URL or null if no location data
     */
    function buildNavigationUrl(stop, options) {
        if (!stop) return null;
        options = options || {};
        var mode = options.mode || 'driving';
        var navigate = options.navigate !== false; // default true

        var destination = '';

        // Prefer lat/lng — always more reliable than address strings
        if (stop.lat && stop.lng) {
            destination = stop.lat + ',' + stop.lng;
        } else if (stop.address) {
            destination = stop.address;
        } else {
            warn('No location data for stop:', stop);
            return null;
        }

        var url = 'https://www.google.com/maps/dir/?api=1'
            + '&destination=' + encodeURIComponent(destination)
            + '&travelmode=' + mode;

        // dir_action=navigate skips the preview and starts turn-by-turn immediately
        if (navigate) {
            url += '&dir_action=navigate';
        }

        log('Built URL:', url, 'from stop:', { lat: stop.lat, lng: stop.lng, address: stop.address });
        return url;
    }

    /**
     * Build a Google Maps URL for a multi-stop route.
     * Last stop = destination, earlier stops = waypoints.
     *
     * @param {Array} stops - ordered array of { lat, lng, address }
     * @param {Object} [options] - { mode, navigate }
     * @returns {string|null}
     */
    function buildMultiStopUrl(stops, options) {
        if (!stops || stops.length === 0) return null;
        if (stops.length === 1) return buildNavigationUrl(stops[0], options);

        options = options || {};
        var mode = options.mode || 'driving';
        var navigate = options.navigate !== false;

        var dest = stops[stops.length - 1];
        var destination = '';
        if (dest.lat && dest.lng) {
            destination = dest.lat + ',' + dest.lng;
        } else if (dest.address) {
            destination = dest.address;
        } else {
            return null;
        }

        var waypointParts = [];
        for (var i = 0; i < stops.length - 1; i++) {
            var s = stops[i];
            if (s.lat && s.lng) {
                waypointParts.push(s.lat + ',' + s.lng);
            } else if (s.address) {
                waypointParts.push(s.address);
            }
        }

        var url = 'https://www.google.com/maps/dir/?api=1'
            + '&destination=' + encodeURIComponent(destination)
            + '&travelmode=' + mode;

        if (waypointParts.length > 0) {
            url += '&waypoints=' + encodeURIComponent(waypointParts.join('|'));
        }

        if (navigate) {
            url += '&dir_action=navigate';
        }

        log('Built multi-stop URL:', url, 'stops:', stops.length);
        return url;
    }

    /**
     * Build an Android intent URI for Google Maps navigation.
     * Falls back to regular URL if intent format not applicable.
     *
     * @param {Object} stop - { lat, lng, address }
     * @returns {string}
     */
    function buildAndroidIntentUri(stop) {
        if (stop.lat && stop.lng) {
            // google.navigation intent — launches turn-by-turn directly
            return 'google.navigation:q=' + stop.lat + ',' + stop.lng + '&mode=d';
        } else if (stop.address) {
            return 'google.navigation:q=' + encodeURIComponent(stop.address) + '&mode=d';
        }
        return null;
    }

    /**
     * Build an Apple Maps URL for iOS.
     *
     * @param {Object} stop - { lat, lng, address }
     * @returns {string|null}
     */
    function buildAppleMapsUrl(stop) {
        if (stop.lat && stop.lng) {
            return 'http://maps.apple.com/?daddr=' + stop.lat + ',' + stop.lng + '&dirflg=d';
        } else if (stop.address) {
            return 'http://maps.apple.com/?daddr=' + encodeURIComponent(stop.address) + '&dirflg=d';
        }
        return null;
    }

    // ── Launch Helpers ──

    /**
     * Open a URL — uses Capacitor App plugin on native, window.open on web.
     */
    function openUrl(url) {
        if (!url) return false;

        if (isCapacitor()) {
            var Plugins = window.Capacitor.Plugins;
            // Capacitor 3+ uses @capacitor/app or Browser
            if (Plugins.App && Plugins.App.openUrl) {
                Plugins.App.openUrl({ url: url }).catch(function(err) {
                    warn('App.openUrl failed:', err, '— falling back to window.open');
                    window.open(url, '_blank');
                });
                return true;
            }
            if (Plugins.Browser && Plugins.Browser.open) {
                Plugins.Browser.open({ url: url }).catch(function(err) {
                    warn('Browser.open failed:', err, '— falling back to window.open');
                    window.open(url, '_blank');
                });
                return true;
            }
        }

        // Web fallback
        window.open(url, '_blank');
        return true;
    }

    /**
     * Show a toast message (reuse MwPillWorkflow's toast or create our own).
     */
    function showToast(msg) {
        var existing = document.querySelector('.mw-mc-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.className = 'mw-mc-toast';
        toast.textContent = msg;
        toast.style.cssText =
            'position: fixed; top: 80px; left: 50%; transform: translateX(-50%); ' +
            'background: #333; color: #fff; padding: 10px 20px; border-radius: 8px; ' +
            'font-size: 0.82rem; font-weight: 600; z-index: 9999; ' +
            'box-shadow: 0 4px 12px rgba(0,0,0,0.2); pointer-events: none; ' +
            'opacity: 0; transition: opacity 0.3s ease;';

        document.body.appendChild(toast);
        requestAnimationFrame(function() { toast.style.opacity = '1'; });

        setTimeout(function() {
            toast.style.opacity = '0';
            setTimeout(function() {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 300);
        }, 2500);
    }

    // ── Public API ──

    /**
     * Launch turn-by-turn navigation to a single stop.
     * Chooses the best method for the current platform.
     *
     * @param {Object} stop - { lat, lng, address, label }
     * @param {Object} [options] - { mode: 'driving' }
     * @returns {boolean} true if launched successfully
     */
    function launchNavigation(stop, options) {
        if (!stop) {
            warn('launchNavigation called with null stop');
            return false;
        }

        // Validate we have location data
        if (!stop.lat && !stop.lng && !stop.address) {
            showToast('Missing location — tap address to set pin');
            log('BLOCKED: no lat/lng or address for stop:', stop);
            return false;
        }

        log('Launching navigation:', {
            stopId: stop.stopId,
            lat: stop.lat,
            lng: stop.lng,
            address: stop.address
        });

        showToast('Launching navigation...');

        var url;

        if (isAndroid()) {
            // Android Capacitor: use google.navigation intent for best Google Maps integration
            url = buildAndroidIntentUri(stop);
            if (url) {
                log('Android intent URI:', url);
                openUrl(url);
                return true;
            }
        }

        if (isIos()) {
            // iOS: try Apple Maps first (opens natively), fall back to Google Maps
            url = buildAppleMapsUrl(stop);
            if (url) {
                log('Apple Maps URL:', url);
                openUrl(url);
                return true;
            }
        }

        // Web / fallback: Google Maps URL with navigate action
        url = buildNavigationUrl(stop, options);
        if (url) {
            openUrl(url);
            return true;
        }

        showToast('Could not build navigation link');
        return false;
    }

    /**
     * Launch navigation through multiple ordered stops.
     *
     * @param {Array} stops - ordered array of stop objects
     * @param {Object} [options] - { mode }
     * @returns {boolean}
     */
    function launchMultiStopNavigation(stops, options) {
        if (!stops || stops.length === 0) return false;

        if (stops.length === 1) {
            return launchNavigation(stops[0], options);
        }

        log('Launching multi-stop navigation:', stops.length, 'stops');
        showToast('Launching navigation...');

        var url = buildMultiStopUrl(stops, options);
        if (url) {
            openUrl(url);
            return true;
        }

        showToast('Could not build navigation link');
        return false;
    }

    return {
        launchNavigation: launchNavigation,
        launchMultiStopNavigation: launchMultiStopNavigation,
        buildNavigationUrl: buildNavigationUrl,
        buildMultiStopUrl: buildMultiStopUrl,
        buildAndroidIntentUri: buildAndroidIntentUri,
        buildAppleMapsUrl: buildAppleMapsUrl,

        // Exposed for testing
        _isCapacitor: isCapacitor,
        _isAndroid: isAndroid,
        _isIos: isIos
    };
})();
