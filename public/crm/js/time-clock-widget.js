/**
 * Time Clock Topbar Widget
 * Manages the persistent clock-in/out widget in the CRM navbar.
 * Loaded on every CRM page via appstack_footer.php.
 *
 * GPS tracking profiles:
 * - Truck devices: track continuously whenever app is open (no clock-in required)
 * - Personal devices: track only when a job timer is running
 *
 * Sends position to /crm/api/crew-location.php at configurable intervals.
 */
(function() {
    'use strict';

    var widget = document.getElementById('clockWidget');
    if (!widget) return;

    var timerInterval = null;
    var clockInTime = null;

    // ── Device & Tracking State ──
    var deviceType = 'personal'; // 'personal' or 'truck'
    var trackingEnabled = false;
    var gpsWatchId = null;
    var trackingInterval = null;
    var latestPosition = null; // { lat, lng, accuracy, speed, heading }
    var TRACKING_INTERVAL_MS = 30000; // 30 seconds (default, can be changed dynamically)
    var GPS_INTERVAL_STANDARD = 30000; // Configurable from server settings
    var GPS_INTERVAL_HEIGHTENED = 10000; // Configurable from server settings
    var hasActiveJobTimer = false; // Whether a job timer is currently running
    var autoArrivalEnabled = false; // Whether proximity auto-clock-in is active
    var lastAutoStartVisitId = null; // Prevent re-triggering same visit

    // ── Screen Wake Lock ──
    // Keeps the screen on while GPS tracking is active so mobile browsers
    // don't suspend JS and drop location pings.
    var wakeLock = null;

    function requestWakeLock() {
        if (!('wakeLock' in navigator)) return;
        if (wakeLock) return; // Already held
        navigator.wakeLock.request('screen').then(function(lock) {
            wakeLock = lock;
            wakeLock.addEventListener('release', function() {
                wakeLock = null;
            });
        }).catch(function() {
            // Wake lock denied (e.g. page not visible) — silently ignore
        });
    }

    function releaseWakeLock() {
        if (wakeLock) {
            wakeLock.release().catch(function() {});
            wakeLock = null;
        }
    }

    // Note: wake lock is re-requested inside startTracking(), which is called
    // by the visibilitychange handler below when the page becomes visible again.
    // Wake locks are automatically released by the browser when the page hides.

    // ── Initialization ──
    updateTrackingDot('unknown', 'Checking GPS...');
    fetchStatus();

    function fetchStatus() {
        fetch('/crm/api/time-clock.php?action=status', {
            credentials: 'same-origin'
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            if (!data.success) {
                renderClockedOut();
                return;
            }
            trackingEnabled = !!data.location_tracking_enabled;
            deviceType = data.device_type || 'personal';
            hasActiveJobTimer = !!(data.active_job);

            // Store configurable GPS intervals from server
            if (data.gps_interval_standard_ms) {
                GPS_INTERVAL_STANDARD = data.gps_interval_standard_ms;
                TRACKING_INTERVAL_MS = GPS_INTERVAL_STANDARD;
            }
            if (data.gps_interval_heightened_ms) {
                GPS_INTERVAL_HEIGHTENED = data.gps_interval_heightened_ms;
            }

            if (data.clocked_in) {
                clockInTime = new Date(data.clock_in.replace(' ', 'T'));
                renderClockedIn(data.elapsed_seconds, data.active_job);
            } else {
                renderClockedOut();
            }

            // Read auto-arrival setting
            autoArrivalEnabled = !!data.auto_arrival_enabled;

            // Start GPS based on device profile
            if (trackingEnabled) {
                if (deviceType === 'truck') {
                    // Truck: always track when app is open
                    startTracking();
                } else if (hasActiveJobTimer) {
                    // Personal: track only during active job timer
                    startTracking();
                } else {
                    probeGPSStatus();
                }
            } else {
                probeGPSStatus();
            }

            // One-shot proximity check on page load (all CRM pages)
            // For personal devices not currently tracking, this is the key —
            // it fires on every page navigation to detect nearby job sites.
            if (autoArrivalEnabled && !hasActiveJobTimer) {
                runOneShotProximityCheck();
            }

            // Passive permission health check — shows topbar warning if
            // background location or battery optimization is degraded.
            if (window.MwNative && window.MwNative.isNative && data.clocked_in) {
                runPassiveHealthCheck();
            }
        })
        .catch(function(err) {
            console.warn('Time clock API error:', err);
            updateTrackingDot('error', 'Unable to check status');
            renderClockedOut();
        });
    }

    // ── Tracking Dot (GPS status indicator — tap to re-request permission) ──
    // Green = GPS actively sending positions
    // Red = GPS denied, unavailable, or not sending
    // Grey = waiting / not clocked in

    var gpsState = 'unknown'; // 'active', 'error', 'unknown'

    // Bind click handler — tapping icon re-requests GPS permission
    // Works on topbar dot; mobile crosshair handles its own click (see schedule.php)
    var trackingWrapper = document.getElementById('trackingDotWrapper');
    if (trackingWrapper) {
        trackingWrapper.addEventListener('click', onTrackingDotTap);
    }

    // Mobile crosshair: override default click when GPS is off
    var mobileCrosshair = document.getElementById('mobileTrackingDot');
    if (mobileCrosshair) {
        mobileCrosshair.addEventListener('click', function(e) {
            if (gpsState !== 'active') {
                e.preventDefault();
                e.stopPropagation();
                onTrackingDotTap();
            }
            // When GPS is active, let the existing locate-nearest-stop logic run
        });
    }

    function updateTrackingDot(state, detail) {
        gpsState = state;

        // Determine CSS class and title for each state
        var dotClass, titleText;
        if (state === 'active') {
            dotClass = 'mw-tracking-dot mw-tracking-dot-on';
            titleText = 'GPS active' + (detail ? ' — ' + detail : '');
        } else if (state === 'error') {
            dotClass = 'mw-tracking-dot mw-tracking-dot-off';
            titleText = (detail || 'GPS off') + ' — tap to enable';
        } else if (state === 'warn') {
            dotClass = 'mw-tracking-dot mw-tracking-dot-warn';
            titleText = detail || 'GPS needs attention — tap to fix';
        } else {
            dotClass = 'mw-tracking-dot mw-tracking-dot-loading';
            titleText = detail || 'Checking GPS...';
        }

        // Update topbar dot
        var dot = document.getElementById('trackingDot');
        var wrapper = document.getElementById('trackingDotWrapper');
        if (dot) dot.className = dotClass;
        if (wrapper) wrapper.title = titleText;

        // Update mobile crosshair button (if present on schedule page)
        var mBtn = document.getElementById('mobileTrackingDot');
        if (mBtn) {
            mBtn.classList.remove('mw-mc-locate-on', 'mw-mc-locate-off', 'mw-mc-locate-loading');
            if (state === 'active') {
                mBtn.classList.add('mw-mc-locate-on');
            } else if (state === 'error') {
                mBtn.classList.add('mw-mc-locate-off');
            } else {
                mBtn.classList.add('mw-mc-locate-loading');
            }
            mBtn.title = titleText;
        }
    }

    function onTrackingDotTap() {
        if (gpsState === 'active') {
            // Already working, just show accuracy info
            if (latestPosition) {
                showToast('GPS active — accuracy: ' + Math.round(latestPosition.accuracy) + 'm', 'success');
            } else {
                showToast('GPS active', 'success');
            }
            return;
        }

        // Permissions degraded — send to tracking health page
        if (gpsState === 'warn') {
            window.location.href = '/crm/timeclock/tracking-health.php';
            return;
        }

        // GPS is off or errored — try to re-request permission
        if (!navigator.geolocation) {
            showToast('Location not supported on this browser', 'error');
            return;
        }

        updateTrackingDot('unknown', 'Requesting GPS...');
        showToast('Requesting location access...', 'info');

        navigator.geolocation.getCurrentPosition(
            function(pos) {
                updateTrackingDot('active', 'accuracy: ' + Math.round(pos.coords.accuracy) + 'm');
                showToast('GPS enabled — location active', 'success');

                // If clocked in and tracking should be on, restart the watch
                if (trackingEnabled && clockInTime !== null) {
                    stopTracking();
                    startTracking();
                }
            },
            function(err) {
                if (err.code === 1) {
                    updateTrackingDot('error', 'Permission denied');
                    showToast('Location denied — open browser settings to allow location for this site', 'error');
                } else if (err.code === 2) {
                    updateTrackingDot('error', 'GPS unavailable');
                    showToast('GPS unavailable — enable Location Services in your phone settings', 'error');
                } else {
                    updateTrackingDot('error', 'GPS timed out');
                    showToast('GPS timed out — try again or check phone settings', 'error');
                }
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    }

    // One-shot GPS probe — just checks if permission is granted/denied
    // and updates the icon color. Does NOT start continuous tracking.
    function probeGPSStatus() {
        if (!navigator.geolocation) {
            updateTrackingDot('error', 'Not supported');
            return;
        }
        // Use Permissions API if available (instant, no prompt)
        if (navigator.permissions && navigator.permissions.query) {
            navigator.permissions.query({ name: 'geolocation' }).then(function(result) {
                if (result.state === 'granted') {
                    updateTrackingDot('active', 'Permission granted');
                } else if (result.state === 'denied') {
                    updateTrackingDot('error', 'Permission denied');
                } else {
                    // 'prompt' — user hasn't decided yet, show as not-active
                    updateTrackingDot('error', 'Location not enabled');
                }
            }).catch(function() {
                // Permissions API failed — fall back to a quick getCurrentPosition
                quickGPSProbe();
            });
        } else {
            // No Permissions API (older iOS) — do a quick GPS check
            quickGPSProbe();
        }
    }

    function quickGPSProbe() {
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                updateTrackingDot('active', 'accuracy: ' + Math.round(pos.coords.accuracy) + 'm');
            },
            function(err) {
                if (err.code === 1) {
                    updateTrackingDot('error', 'Permission denied');
                } else {
                    updateTrackingDot('error', 'GPS unavailable');
                }
            },
            { enableHighAccuracy: false, timeout: 5000, maximumAge: 300000 }
        );
    }

    // ── Render States ──

    // Inline SVG icons — no dependency on feather-icons loading
    var SVG_PLAY = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polygon points="10 8 16 12 10 16 10 8"></polygon></svg>';
    var SVG_STOP = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect></svg>';
    var SVG_CLOCK = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';
    var SVG_NAV = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"></polygon></svg>';

    function renderClockedOut() {
        stopTimer();
        // Truck devices keep tracking even when not clocked in
        if (deviceType !== 'truck') {
            stopTracking();
        }
        widget.innerHTML =
            '<button class="mw-clock-btn mw-clock-in" id="btnClockIn" title="Clock In">' +
                SVG_PLAY +
                '<span class="mw-clock-label">Clock In</span>' +
            '</button>';
        document.getElementById('btnClockIn').addEventListener('click', doClockIn);
    }

    function renderClockedIn(elapsedSeconds, activeJob) {
        var html =
            '<div class="mw-clock-active">' +
                '<span class="mw-clock-icon-pulse">' + SVG_CLOCK + '</span>' +
                '<span class="mw-clock-timer" id="clockTimer">' + formatSeconds(elapsedSeconds) + '</span>';

        if (activeJob) {
            html += '<span class="mw-clock-job-badge" title="' + escapeHtml(activeJob.job_title || '') + '">' +
                        escapeHtml(activeJob.job_number || '') +
                    '</span>';
        }

        // GPS tracking indicator
        if (trackingEnabled) {
            html += '<span class="mw-tracking-indicator" id="trackingIndicator" title="GPS tracking active">' +
                        SVG_NAV +
                    '</span>';
        }

        html +=     '<button class="mw-clock-btn mw-clock-out" id="btnClockOut" title="Clock Out">' +
                        SVG_STOP +
                        '<span class="mw-clock-label">Out</span>' +
                    '</button>' +
                '</div>';

        widget.innerHTML = html;
        document.getElementById('btnClockOut').addEventListener('click', doClockOut);
        startTimer(elapsedSeconds);
    }

    function renderDisabled() {
        // Never hide the widget — always show clock-in as fallback
        renderClockedOut();
    }

    // ── Timer Logic ──

    function startTimer(startSeconds) {
        stopTimer();
        var seconds = startSeconds;
        var timerEl = document.getElementById('clockTimer');
        timerInterval = setInterval(function() {
            seconds++;
            if (timerEl) timerEl.textContent = formatSeconds(seconds);
        }, 1000);
    }

    function stopTimer() {
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
    }

    // ── Location Tracking ──

    var gpsErrorCount = 0;
    var gpsErrorToastShown = false;

    function startTracking() {
        gpsErrorCount = 0;
        gpsErrorToastShown = false;
        updateTrackingDot('unknown', 'Acquiring GPS...');
        requestWakeLock();

        // ── Native Capacitor: background-capable GPS ──
        // Start the native plugin for background capability, BUT also start
        // browser watchPosition as a reliable secondary source. The native
        // locationProcessor filter can reject fixes (accuracy > 50m, jitter),
        // leaving latestPosition null. The browser watch ensures we always
        // have a position to send.
        if (window.MwNative && window.MwNative.geo) {
            window.MwNative.geo.startBackgroundTracking(function(pos, error) {
                if (error) {
                    gpsErrorCount++;
                    updateTrackingDot('error', error.code || 'GPS error');
                    if (!gpsErrorToastShown) {
                        gpsErrorToastShown = true;
                        showToast('GPS error: ' + (error.message || 'Unknown'), 'error');
                    }
                    return;
                }
                gpsErrorCount = 0;
                latestPosition = pos;
                updateTrackingDot('active', 'accuracy: ' + Math.round(pos.accuracy) + 'm');
            });
            // Fall through to ALSO start browser watchPosition below
        }

        // ── Browser GPS watch (primary for browsers, secondary for native) ──
        if (gpsWatchId !== null) {
            // Already watching — just ensure send interval is running
            if (!trackingInterval) {
                sendPosition();
                trackingInterval = setInterval(sendPosition, TRACKING_INTERVAL_MS);
            }
            return;
        }
        if (!navigator.geolocation) {
            if (!window.MwNative) {
                showToast('Location not supported on this browser', 'error');
                updateTrackingDot('error', 'Not supported');
            }
            // Native-only: still set up the send interval
            if (!trackingInterval) {
                sendPosition();
                trackingInterval = setInterval(sendPosition, TRACKING_INTERVAL_MS);
            }
            return;
        }

        // Start high-accuracy continuous GPS watch
        gpsWatchId = navigator.geolocation.watchPosition(
            function(pos) {
                gpsErrorCount = 0; // Reset on success
                latestPosition = {
                    lat: pos.coords.latitude,
                    lng: pos.coords.longitude,
                    accuracy: pos.coords.accuracy,
                    speed: pos.coords.speed,
                    heading: pos.coords.heading
                };
                // Update topbar dot to green — GPS is actively sending
                updateTrackingDot('active', 'accuracy: ' + Math.round(pos.coords.accuracy) + 'm');
            },
            function(err) {
                gpsErrorCount++;
                // Update topbar dot to red — GPS error
                if (err.code === 1) {
                    updateTrackingDot('error', 'Permission denied');
                } else if (err.code === 2) {
                    updateTrackingDot('error', 'GPS unavailable');
                } else {
                    updateTrackingDot('error', 'GPS timed out');
                }
                // Show a visible toast on first error so mobile users know GPS is failing
                if (!gpsErrorToastShown) {
                    gpsErrorToastShown = true;
                    if (err.code === 1) {
                        showToast('Location permission denied — tap the signal icon to retry', 'error');
                    } else if (err.code === 2) {
                        showToast('GPS unavailable — enable Location in phone settings', 'error');
                    } else {
                        showToast('GPS timed out — trying again...', 'error');
                    }
                }
            },
            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            }
        );

        // Send position to server every 30 seconds
        sendPosition(); // Send immediately on start
        trackingInterval = setInterval(sendPosition, TRACKING_INTERVAL_MS);
    }

    function stopTracking() {
        releaseWakeLock();
        // Stop native background GPS
        if (window.MwNative && window.MwNative.geo) {
            window.MwNative.geo.stopBackgroundTracking();
        }
        // Stop browser GPS watch
        if (gpsWatchId !== null) {
            navigator.geolocation.clearWatch(gpsWatchId);
            gpsWatchId = null;
        }
        if (trackingInterval) {
            clearInterval(trackingInterval);
            trackingInterval = null;
        }
        latestPosition = null;
        // Not clocked in or tracking stopped — show grey
        if (!clockInTime) {
            updateTrackingDot('unknown', 'Not clocked in');
        }
    }

    var GPS_QUEUE_KEY = 'mw-gps-queue';
    var GPS_QUEUE_MAX = 500; // ~4 hours at 30s intervals
    var noFixCount = 0; // Tracks consecutive sendPosition() calls with no GPS fix

    function sendPosition() {
        if (!latestPosition) {
            noFixCount++;
            // After 2 misses (60s) with no position from native/watch, try one-shot fallback
            if (noFixCount >= 2 && navigator.geolocation) {
                console.warn('[MwTracking] No GPS fix after ' + noFixCount + ' intervals — trying one-shot fallback');
                navigator.geolocation.getCurrentPosition(
                    function(pos) {
                        latestPosition = {
                            lat: pos.coords.latitude,
                            lng: pos.coords.longitude,
                            accuracy: pos.coords.accuracy,
                            speed: pos.coords.speed,
                            heading: pos.coords.heading
                        };
                        updateTrackingDot('active', 'accuracy: ' + Math.round(pos.coords.accuracy) + 'm (fallback)');
                        doSendPosition(); // Send the fallback position now
                    },
                    function() {
                        console.warn('[MwTracking] One-shot fallback also failed');
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }
                );
            } else {
                console.warn('[MwTracking] No GPS fix yet, skipping ping');
            }
            return;
        }
        noFixCount = 0;
        doSendPosition();
    }

    function doSendPosition() {
        if (!latestPosition) return;

        // Check if we're offline
        var isOffline = (window.MwNative && !window.MwNative.network.isOnline) ||
                        (!window.MwNative && typeof navigator.onLine !== 'undefined' && !navigator.onLine);

        if (isOffline) {
            queuePosition(latestPosition);
            console.log('[MwTracking] Offline — queued GPS ping');
            return;
        }

        // Flush any previously queued positions first
        flushQueue();

        // Send current position
        fetch('/crm/api/crew-location.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                lat: latestPosition.lat,
                lng: latestPosition.lng,
                accuracy: latestPosition.accuracy,
                speed: latestPosition.speed,
                heading: latestPosition.heading
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && !data.skipped) {
                console.log('[MwTracking] Position sent OK');
            }
            if (data.error === 'Not clocked in' || data.error === 'Tracking not enabled') {
                stopTracking();
            }
            // Handle server-side proximity auto-start
            if (data.auto_started) {
                handleServerAutoStart(data.auto_started);
            }
        })
        .catch(function(err) {
            console.warn('[MwTracking] Send failed, queueing:', err);
            queuePosition(latestPosition);
        });
    }

    function queuePosition(pos) {
        try {
            var queue = JSON.parse(localStorage.getItem(GPS_QUEUE_KEY) || '[]');
            if (queue.length >= GPS_QUEUE_MAX) queue.shift(); // Drop oldest
            queue.push({
                lat: pos.lat,
                lng: pos.lng,
                accuracy: pos.accuracy,
                speed: pos.speed,
                heading: pos.heading,
                queued_at: new Date().toISOString()
            });
            localStorage.setItem(GPS_QUEUE_KEY, JSON.stringify(queue));
        } catch(e) {
            console.warn('[MwTracking] Queue write failed:', e);
        }
    }

    function flushQueue() {
        var queue;
        try {
            queue = JSON.parse(localStorage.getItem(GPS_QUEUE_KEY) || '[]');
        } catch(e) { return; }
        if (queue.length === 0) return;

        // Clear queue immediately (re-queue on failure)
        localStorage.removeItem(GPS_QUEUE_KEY);
        console.log('[MwTracking] Flushing ' + queue.length + ' queued GPS pings');

        queue.forEach(function(pos, i) {
            setTimeout(function() {
                fetch('/crm/api/crew-location.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(pos)
                }).catch(function() {
                    queuePosition(pos); // Re-queue on failure
                });
            }, i * 200); // 200ms between each to avoid rate limiting
        });
    }

    // ── Clock-In Health Gate ─────────────────────────────────────────────────
    // Android only. Calls MwNative.tracking.getHealth() before clock-in fires.
    // Critical issues (GPS off, fg/bg location denied) block clock-in entirely.
    // Warnings (battery optimization on) show but allow "Skip for now".
    // Falls through immediately in browser or on iOS.

    function runHealthGate(onPass, onCancel) {
        if (!window.MwNative || !window.MwNative.isNative) {
            onPass();
            return;
        }

        window.MwNative.tracking.getHealth().then(function(h) {
            var issues = [];

            if (!h.gpsEnabled) {
                issues.push({
                    severity: 'critical',
                    title: 'GPS is turned off',
                    detail: 'Turn on Location Services in your phone settings.',
                    fixLabel: 'Open Location Settings',
                    fix: function() {
                        if (window.MwNative.tracking.openLocationSettings) {
                            window.MwNative.tracking.openLocationSettings();
                        }
                    }
                });
            }

            if (!h.fgLocationGranted) {
                issues.push({
                    severity: 'critical',
                    title: 'Location permission denied',
                    detail: 'Tap Grant Permission and select "While using the app" or "Allow all the time".',
                    fixLabel: 'Grant Permission',
                    fix: function() {
                        window.MwNative.geo.getCurrentPosition().catch(function() {});
                    }
                });
            }

            if (!h.bgLocationGranted) {
                issues.push({
                    severity: 'critical',
                    title: 'Background location not allowed',
                    detail: 'Open App Settings and set Location to "Allow all the time" so GPS tracks while your screen is off.',
                    fixLabel: 'Open App Settings',
                    fix: function() {
                        if (window.MwNative.tracking.openAppSettings) {
                            window.MwNative.tracking.openAppSettings();
                        }
                    }
                });
            }

            if (!h.batteryOptimizationIgnored) {
                issues.push({
                    severity: 'warning',
                    title: 'Battery optimization will interrupt GPS',
                    detail: h.oemBatteryInfo || 'Disable battery optimization for Mowology so the app stays alive in the background.',
                    fixLabel: 'Fix Battery Settings',
                    fix: function() {
                        window.MwNative.tracking.requestBatteryExemption();
                    }
                });
            }

            if (issues.length === 0) {
                onPass();
                return;
            }

            var criticals = issues.filter(function(i) { return i.severity === 'critical'; });
            showHealthGateModal(issues, criticals.length === 0, onPass, onCancel);

        }).catch(function() {
            onPass(); // Health check failed — don't block clock-in
        });
    }

    function showHealthGateModal(issues, canSkip, onPass, onCancel) {
        var existing = document.getElementById('mwHealthGate');
        if (existing) existing.remove();

        var overlay = document.createElement('div');
        overlay.id = 'mwHealthGate';
        overlay.className = 'mw-health-gate-overlay';

        var criticals = issues.filter(function(i) { return i.severity === 'critical'; });
        var subtitle = criticals.length > 0
            ? criticals.length + ' issue' + (criticals.length > 1 ? 's' : '') + ' must be fixed before clocking in'
            : 'Fix this to ensure your location tracks correctly today';

        var cardsHtml = issues.map(function(issue, idx) {
            var color = issue.severity === 'critical' ? '#dc3545' : '#e85d04';
            return '<div class="mw-hg-card" style="border-left-color:' + color + '">' +
                '<div class="mw-hg-card-title">' + escapeHtml(issue.title) + '</div>' +
                '<div class="mw-hg-card-detail">' + escapeHtml(issue.detail) + '</div>' +
                '<button class="btn btn-sm mw-hg-fix-btn" data-idx="' + idx + '" ' +
                    'style="background:' + color + ';color:#fff;margin-top:8px;border:none;">' +
                    escapeHtml(issue.fixLabel) + ' →' +
                '</button>' +
            '</div>';
        }).join('');

        overlay.innerHTML =
            '<div class="mw-health-gate-modal">' +
            '  <div class="mw-hg-header">' +
            '    <div class="mw-hg-icon">&#9888;&#65039;</div>' +
            '    <div>' +
            '      <div class="mw-hg-title">GPS Setup Needed</div>' +
            '      <div class="mw-hg-subtitle">' + escapeHtml(subtitle) + '</div>' +
            '    </div>' +
            '  </div>' +
            '  <div class="mw-hg-cards">' + cardsHtml + '</div>' +
            '  <div class="mw-hg-actions">' +
            '    <button class="btn btn-success btn-block mw-hg-recheck">Re-check &amp; Clock In</button>' +
            (canSkip ? '<button class="btn btn-link btn-sm mw-hg-skip text-muted">Skip for now</button>' : '') +
            '  </div>' +
            '</div>';

        document.body.appendChild(overlay);
        document.body.classList.add('mw-no-scroll');

        overlay.querySelectorAll('.mw-hg-fix-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var idx = parseInt(btn.dataset.idx, 10);
                issues[idx].fix();
            });
        });

        overlay.querySelector('.mw-hg-recheck').addEventListener('click', function() {
            overlay.remove();
            document.body.classList.remove('mw-no-scroll');
            runHealthGate(onPass, onCancel);
        });

        var skipBtn = overlay.querySelector('.mw-hg-skip');
        if (skipBtn) {
            skipBtn.addEventListener('click', function() {
                overlay.remove();
                document.body.classList.remove('mw-no-scroll');
                onPass();
            });
        }
    }

    // Passive health check — called on page load for clocked-in native users.
    // Shows amber topbar dot if permissions are degraded; tapping it goes to
    // tracking-health.php. Does NOT block anything.
    function runPassiveHealthCheck() {
        window.MwNative.tracking.getHealth().then(function(h) {
            var hasCritical = !h.bgLocationGranted || !h.fgLocationGranted || !h.gpsEnabled;
            var hasWarning  = !h.batteryOptimizationIgnored;
            if (hasCritical) {
                updateTrackingDot('warn', 'GPS permissions missing — tap to fix');
            } else if (hasWarning) {
                updateTrackingDot('warn', 'Battery optimization may kill GPS — tap to fix');
            }
        }).catch(function() {});
    }

    // ── Actions ──

    function doClockIn() {
        var btn = document.getElementById('btnClockIn');
        if (btn) btn.disabled = true;

        runHealthGate(
            function() {
                getGPS(function(lat, lng) {
                    fetch('/crm/api/time-clock.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'clock_in', lat: lat, lng: lng })
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            clockInTime = new Date();
                            // Re-fetch status to get tracking flag and render properly
                            fetchStatus();
                            showToast('Clocked in at ' + formatTime(new Date()), 'success');
                        } else {
                            showToast(data.error || 'Clock in failed', 'error');
                            renderClockedOut();
                        }
                    })
                    .catch(function() {
                        showToast('Network error', 'error');
                        if (btn) btn.disabled = false;
                    });
                });
            },
            function() {
                // User closed modal without fixing — re-enable the button
                if (btn) btn.disabled = false;
            }
        );
    }

    function doClockOut() {
        if (!confirm('Clock out now?')) return;

        var btn = document.getElementById('btnClockOut');
        if (btn) btn.disabled = true;

        // Truck devices keep tracking; personal devices stop
        if (deviceType !== 'truck') {
            stopTracking();
        }

        getGPS(function(lat, lng) {
            fetch('/crm/api/time-clock.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'clock_out', lat: lat, lng: lng })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    renderClockedOut();
                    showToast('Clocked out — Total: ' + data.total_formatted, 'success');
                } else {
                    showToast(data.error || 'Clock out failed', 'error');
                    if (btn) btn.disabled = false;
                }
            })
            .catch(function() {
                showToast('Network error', 'error');
                if (btn) btn.disabled = false;
            });
        });
    }

    // ── Helpers ──

    function getGPS(callback) {
        // Native Capacitor: use native Geolocation plugin
        if (window.MwNative && window.MwNative.geo) {
            window.MwNative.geo.getCurrentPosition()
                .then(function(pos) { callback(pos.lat, pos.lng); })
                .catch(function() { callback(null, null); });
            return;
        }
        // Browser fallback
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function(pos) { callback(pos.coords.latitude, pos.coords.longitude); },
                function() { callback(null, null); },
                { timeout: 5000, maximumAge: 60000 }
            );
        } else {
            callback(null, null);
        }
    }

    function formatSeconds(totalSec) {
        if (!totalSec || totalSec < 0) totalSec = 0;
        var h = Math.floor(totalSec / 3600);
        var m = Math.floor((totalSec % 3600) / 60);
        var s = totalSec % 60;
        return pad(h) + ':' + pad(m) + ':' + pad(s);
    }

    function pad(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    function formatTime(d) {
        return pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function activateFeather() {
        if (typeof feather !== 'undefined') feather.replace();
    }

    function showToast(message, type) {
        var toast = document.createElement('div');
        toast.className = 'mw-clock-toast mw-clock-toast-' + (type || 'info');
        toast.textContent = message;
        document.body.appendChild(toast);

        // Trigger reflow then animate in
        toast.offsetHeight;
        toast.classList.add('mw-clock-toast-visible');

        setTimeout(function() {
            toast.classList.remove('mw-clock-toast-visible');
            setTimeout(function() { toast.remove(); }, 300);
        }, 3000);
    }

    // ── Proximity Auto-Start ──

    /**
     * Server detected proximity and auto-started a visit timer.
     * Update widget UI and notify other components (e.g., schedule pill workflow).
     */
    function handleServerAutoStart(info) {
        // Not clocked in — prompt crew to clock in first
        if (info.needs_clock_in) {
            showToast(
                'You\'re near ' + (info.job_title || info.job_number || 'a job') +
                ' (' + info.distance_meters + 'm). Clock in to begin.',
                'warning'
            );
            // Dispatch so schedule page can show a prompt if visible
            document.dispatchEvent(new CustomEvent('mw-proximity-needs-clock-in', {
                detail: info
            }));
            return;
        }

        console.log('[MwTracking] Server auto-started visit ' + info.visit_id +
                    ' (' + info.job_title + ') at ' + info.distance_meters + 'm');

        lastAutoStartVisitId = info.visit_id;
        hasActiveJobTimer = true;

        // Re-fetch full status to render the clocked-in widget with active job badge
        fetchStatus();

        // Show toast
        showToast('Auto-started: ' + (info.job_title || info.job_number || 'Job') +
                  ' (' + info.distance_meters + 'm away)', 'success');

        // Dispatch event for schedule page pill workflow
        document.dispatchEvent(new CustomEvent('mw-proximity-auto-start', {
            detail: info
        }));
    }

    /**
     * One-shot proximity check — runs on every CRM page load.
     * Gets a single GPS fix, then POSTs to crew-location.php with proximity_check flag.
     * The server runs checkProximityAutoStart() and may auto-clock-in + start a timer.
     */
    function runOneShotProximityCheck() {
        if (!navigator.geolocation) return;

        navigator.geolocation.getCurrentPosition(
            function(pos) {
                var lat = pos.coords.latitude;
                var lng = pos.coords.longitude;
                var accuracy = pos.coords.accuracy;

                console.log('[MwTracking] One-shot proximity check: lat=' + lat +
                            ', lng=' + lng + ', accuracy=' + Math.round(accuracy) + 'm');

                fetch('/crm/api/crew-location.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        lat: lat,
                        lng: lng,
                        accuracy: accuracy,
                        proximity_check: true
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.auto_started) {
                        handleServerAutoStart(data.auto_started);
                    }
                })
                .catch(function(err) {
                    console.warn('[MwTracking] One-shot proximity check failed:', err);
                });
            },
            function() {
                // GPS denied or unavailable — silent fail for one-shot
            },
            { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 }
        );
    }

    // ── Mobile Resilience: Page Visibility API ──
    // Mobile Safari suspends JS when backgrounded or screen-locked.
    // When the tab comes back, restart GPS watch and send a ping immediately.
    // In native Capacitor, the background plugin handles this natively.
    // Wake lock is re-acquired by the visibilitychange handler above (near its declaration).
    document.addEventListener('visibilitychange', function() {
        if (window.MwNative) return; // Native plugin handles background GPS
        if (document.visibilityState !== 'visible' || !trackingEnabled) return;
        // Truck: always restart. Personal: only if job timer is active.
        if (deviceType === 'truck' || hasActiveJobTimer) {
            stopTracking();
            startTracking();
        }
    });

    window.addEventListener('pageshow', function(event) {
        if (window.MwNative) return; // Native plugin handles background GPS
        if (!event.persisted || !trackingEnabled) return;
        if (deviceType === 'truck' || hasActiveJobTimer) {
            stopTracking();
            startTracking();
        }
    });

    // ── Offline Queue: auto-flush when back online ──
    if (window.MwNative && window.MwNative.network) {
        window.MwNative.network.onStatusChange(function(isOnline) {
            if (isOnline) flushQueue();
        });
    } else {
        window.addEventListener('online', function() { flushQueue(); });
    }

    // ── Adaptive ping interval (Android/Capacitor) ─────────────────────────
    // Android equivalent of iOS ActivityMonitor.swift (T2-7).
    //
    // capacitor-bridge.js fires 'mw-activity-changed' when MwTracking's activity
    // recognition detects a transition. The bridge dispatches this event and notes
    // "so time-clock-widget can restart tracking" — this is that listener.
    //
    // Why IN_VEHICLE and ON_FOOT both get heightened (10s):
    //   - Driving: position changes fast, map needs frequent server pings to show
    //     crew location accurately. The BG plugin's distanceFilter handles GPS hardware
    //     frequency; this controls how often we POST to crew-location.php.
    //   - Walking/on-site: crew is actively working, supervisors check map often.
    // STILL gets standard (30s) — crew is stationary, save battery and bandwidth.
    //
    // ⚠️  Do not remove the window.MwTimeClock guard. The listener is attached at
    //     module load time but MwTimeClock is defined later in the same IIFE. If
    //     this ever moves outside the IIFE, MwTimeClock won't exist yet.
    var ACTIVITY_INTERVAL_MAP = {
        'IN_VEHICLE': 'heightened',  // 10s — driving between jobs
        'RUNNING':    'heightened',  // 10s — on-site work
        'ON_FOOT':    'heightened',  // 10s — on-site work
        'WALKING':    'heightened',  // 10s — on-site work
        'STILL':      'standard',    // 30s — parked / waiting
        'UNKNOWN':    'standard'     // 30s — conservative default
    };

    document.addEventListener('mw-activity-changed', function(e) {
        if (!window.MwTimeClock) return;
        var activity = e.detail && e.detail.activity;
        var mode = ACTIVITY_INTERVAL_MAP[activity] || 'standard';
        window.MwTimeClock.setTrackingInterval(mode);
    });

    // Expose for use by schedule page and pill workflow
    window.MwTimeClock = {
        fetchStatus: fetchStatus,
        isActive:    function() { return clockInTime !== null; },
        isClockedIn: function() { return clockInTime !== null; },
        clockInNow:  function(callback) {
            MwApi.post('/crm/api/time-clock.php', { action: 'clock_in' })
                .then(function(data) {
                    if (data.success && data.clocked_in) {
                        clockInTime = new Date();
                        renderClockedIn(0, null);
                        if (typeof callback === 'function') callback();
                    }
                })
                .catch(function() {});
        },
        isTracking: function() {
            return gpsWatchId !== null ||
                   (window.MwNative && window.MwNative.geo && window.MwNative.geo.watchId !== null);
        },
        getDeviceType: function() { return deviceType; },
        restartTracking: function() {
            if (trackingEnabled) {
                stopTracking();
                startTracking();
            }
        },
        /**
         * Called by pill workflow when a job timer starts.
         * Ensures GPS tracking is running for ALL device types.
         * Personal devices: this is the primary trigger for tracking.
         * Truck devices: tracking should already be running, but if the
         * native plugin failed on page load, this retries it.
         */
        notifyJobTimerStarted: function() {
            hasActiveJobTimer = true;
            if (trackingEnabled) {
                startTracking();
            }
        },
        /**
         * Called by pill workflow when a job timer stops.
         * For personal devices, this stops GPS tracking.
         */
        notifyJobTimerStopped: function() {
            hasActiveJobTimer = false;
            if (deviceType === 'personal') {
                stopTracking();
            }
        },
        /**
         * Dynamically adjust GPS send interval.
         * @param {string|number} mode - 'heightened', 'standard', or raw ms value
         */
        setTrackingInterval: function(mode) {
            if (mode === 'heightened') {
                TRACKING_INTERVAL_MS = GPS_INTERVAL_HEIGHTENED;
            } else if (mode === 'standard') {
                TRACKING_INTERVAL_MS = GPS_INTERVAL_STANDARD;
            } else if (typeof mode === 'number' && mode > 0) {
                TRACKING_INTERVAL_MS = mode;
            } else {
                TRACKING_INTERVAL_MS = GPS_INTERVAL_STANDARD;
            }
            // Restart interval if currently tracking
            if (trackingInterval) {
                clearInterval(trackingInterval);
                trackingInterval = setInterval(sendPosition, TRACKING_INTERVAL_MS);
            }
        }
    };

})();
