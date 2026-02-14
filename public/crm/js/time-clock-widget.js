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
    updateTrackingDot(null); // neutral state until API responds
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
            updateTrackingDot(trackingEnabled);

            if (data.clocked_in) {
                clockInTime = new Date(data.clock_in.replace(' ', 'T'));
                renderClockedIn(data.elapsed_seconds, data.active_job);
                if (trackingEnabled) {
                    startTracking();
                }
            } else {
                renderClockedOut();
            }
        })
        .catch(function(err) {
            // API failed — still show clock-in button so crew can always clock in
            console.warn('Time clock API error:', err);
            updateTrackingDot(false);
            renderClockedOut();
        });
    }

    // ── Tracking Dot (always visible in topbar, clickable to toggle) ──

    var trackingToggleBusy = false;

    // Bind click handler once on init
    var trackingWrapper = document.getElementById('trackingDotWrapper');
    if (trackingWrapper) {
        trackingWrapper.addEventListener('click', toggleTracking);
    }

    function updateTrackingDot(enabled) {
        var dot = document.getElementById('trackingDot');
        var wrapper = document.getElementById('trackingDotWrapper');
        if (!dot || !wrapper) return;

        if (enabled === null) {
            // Loading state
            dot.className = 'mw-tracking-dot mw-tracking-dot-loading';
            wrapper.title = 'Checking tracking status...';
        } else if (enabled) {
            dot.className = 'mw-tracking-dot mw-tracking-dot-on';
            wrapper.title = 'Location tracking: ON — tap to disable';
        } else {
            dot.className = 'mw-tracking-dot mw-tracking-dot-off';
            wrapper.title = 'Location tracking: OFF — tap to enable';
        }
    }

    function toggleTracking() {
        if (trackingToggleBusy) return;
        trackingToggleBusy = true;

        // Optimistic UI: show loading
        updateTrackingDot(null);

        fetch('/crm/api/time-clock.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'toggle_tracking' })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            trackingToggleBusy = false;
            if (data.success) {
                trackingEnabled = !!data.location_tracking_enabled;
                updateTrackingDot(trackingEnabled);
                showToast(data.message, trackingEnabled ? 'success' : 'info');

                // Start or stop GPS tracking based on new state
                if (trackingEnabled && clockInTime !== null) {
                    startTracking();
                } else {
                    stopTracking();
                }
            } else {
                updateTrackingDot(trackingEnabled); // revert to previous state
                showToast(data.error || 'Failed to toggle tracking', 'error');
            }
        })
        .catch(function() {
            trackingToggleBusy = false;
            updateTrackingDot(trackingEnabled); // revert to previous state
            showToast('Network error', 'error');
        });
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
            return;
        }

        gpsErrorCount = 0;
        gpsErrorToastShown = false;

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
                // Update indicator to show we have a fix
                var indicator = document.getElementById('trackingIndicator');
                if (indicator) {
                    indicator.classList.add('mw-tracking-active');
                    indicator.classList.remove('mw-tracking-error');
                    indicator.title = 'GPS active — accuracy: ' + Math.round(pos.coords.accuracy) + 'm';
                }
            },
            function(err) {
                gpsErrorCount++;
                var indicator = document.getElementById('trackingIndicator');
                if (indicator) {
                    indicator.classList.remove('mw-tracking-active');
                    indicator.classList.add('mw-tracking-error');
                    indicator.title = 'GPS error: ' + err.message;
                }
                // Show a visible toast on first error so mobile users know GPS is failing
                if (!gpsErrorToastShown) {
                    gpsErrorToastShown = true;
                    if (err.code === 1) {
                        showToast('Location permission denied — enable in browser settings', 'error');
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
