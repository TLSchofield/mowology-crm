/**
 * Time Clock Topbar Widget
 * Manages the persistent clock-in/out widget in the CRM navbar.
 * Loaded on every CRM page via appstack_footer.php.
 *
 * Also handles continuous GPS tracking when:
 * - User is clocked in
 * - User has location_tracking_enabled = 1
 * Sends position to /crm/api/crew-location.php every 30 seconds.
 */
(function() {
    'use strict';

    var widget = document.getElementById('clockWidget');
    if (!widget) return;

    var timerInterval = null;
    var clockInTime = null;

    // ── Location Tracking State ──
    var trackingEnabled = false;
    var gpsWatchId = null;
    var trackingInterval = null;
    var latestPosition = null; // { lat, lng, accuracy, speed, heading }
    var TRACKING_INTERVAL_MS = 30000; // 30 seconds

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
                // API responded but not successful — still show clock-in button
                renderClockedOut();
                return;
            }
            trackingEnabled = !!data.location_tracking_enabled;

            if (data.clocked_in) {
                clockInTime = new Date(data.clock_in.replace(' ', 'T'));
                renderClockedIn(data.elapsed_seconds, data.active_job);
                if (trackingEnabled) {
                    startTracking();
                } else {
                    // Clocked in but tracking not enabled in DB — still probe GPS for the icon
                    probeGPSStatus();
                }
            } else {
                renderClockedOut();
                // Not clocked in — still probe GPS so icon shows red/green, not grey
                probeGPSStatus();
            }
        })
        .catch(function(err) {
            // API failed — still show clock-in button so crew can always clock in
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
        stopTracking();
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
        if (gpsWatchId !== null) return; // Already tracking
        if (!navigator.geolocation) {
            showToast('Location not supported on this browser', 'error');
            updateTrackingDot('error', 'Not supported');
            return;
        }

        gpsErrorCount = 0;
        gpsErrorToastShown = false;
        updateTrackingDot('unknown', 'Acquiring GPS...');

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

    function sendPosition() {
        if (!latestPosition) {
            console.warn('[MwTracking] No GPS fix yet, skipping ping');
            return;
        }

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
        })
        .catch(function(err) {
            console.warn('[MwTracking] Send failed:', err);
        });
    }

    // ── Actions ──

    function doClockIn() {
        var btn = document.getElementById('btnClockIn');
        if (btn) btn.disabled = true;

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
    }

    function doClockOut() {
        if (!confirm('Clock out now?')) return;

        var btn = document.getElementById('btnClockOut');
        if (btn) btn.disabled = true;

        stopTracking();

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

    // ── Mobile Resilience: Page Visibility API ──
    // Mobile Safari suspends JS when backgrounded or screen-locked.
    // When the tab comes back, restart GPS watch and send a ping immediately.
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible' && trackingEnabled && clockInTime !== null) {
            // Restart GPS tracking if it was running
            stopTracking();
            startTracking();
        }
    });

    // Also handle the 'pageshow' event which fires on iOS Safari when
    // navigating back to a page from the bfcache
    window.addEventListener('pageshow', function(event) {
        if (event.persisted && trackingEnabled && clockInTime !== null) {
            stopTracking();
            startTracking();
        }
    });

    // Expose for use by my-schedule page
    window.MwTimeClock = {
        fetchStatus: fetchStatus,
        isActive: function() { return clockInTime !== null; },
        isTracking: function() { return gpsWatchId !== null; },
        restartTracking: function() {
            if (trackingEnabled && clockInTime !== null) {
                stopTracking();
                startTracking();
            }
        }
    };

})();
