/**
 * Time Clock Topbar Widget
 * Manages the persistent clock-in/out widget in the CRM navbar.
 * Loaded on every CRM page via appstack_footer.php.
 */
(function() {
    'use strict';

    var widget = document.getElementById('clockWidget');
    if (!widget) return;

    var timerInterval = null;
    var clockInTime = null;

    // ── Initialization ──
    fetchStatus();

    function fetchStatus() {
        fetch('/crm/api/time-clock.php?action=status', {
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                renderDisabled();
                return;
            }
            if (data.clocked_in) {
                clockInTime = new Date(data.clock_in.replace(' ', 'T'));
                renderClockedIn(data.elapsed_seconds, data.active_job);
            } else {
                renderClockedOut();
            }
        })
        .catch(function() {
            renderDisabled();
        });
    }

    // ── Render States ──

    function renderClockedOut() {
        stopTimer();
        widget.innerHTML =
            '<button class="mw-clock-btn mw-clock-in" id="btnClockIn" title="Clock In">' +
                '<i data-feather="play-circle"></i>' +
                '<span class="mw-clock-label">Clock In</span>' +
            '</button>';
        activateFeather();
        document.getElementById('btnClockIn').addEventListener('click', doClockIn);
    }

    function renderClockedIn(elapsedSeconds, activeJob) {
        var html =
            '<div class="mw-clock-active">' +
                '<i data-feather="clock" class="mw-clock-icon-pulse"></i>' +
                '<span class="mw-clock-timer" id="clockTimer">' + formatSeconds(elapsedSeconds) + '</span>';

        if (activeJob) {
            html += '<span class="mw-clock-job-badge" title="' + escapeHtml(activeJob.job_title || '') + '">' +
                        '<i data-feather="briefcase"></i> ' + escapeHtml(activeJob.job_number || '') +
                    '</span>';
        }

        html +=     '<button class="mw-clock-btn mw-clock-out" id="btnClockOut" title="Clock Out">' +
                        '<i data-feather="square"></i>' +
                        '<span class="mw-clock-label">Out</span>' +
                    '</button>' +
                '</div>';

        widget.innerHTML = html;
        activateFeather();
        document.getElementById('btnClockOut').addEventListener('click', doClockOut);
        startTimer(elapsedSeconds);
    }

    function renderDisabled() {
        widget.innerHTML = '';
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
                    renderClockedIn(0, null);
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

    // Expose for use by my-schedule page
    window.MwTimeClock = {
        fetchStatus: fetchStatus,
        isActive: function() { return clockInTime !== null; }
    };

})();
