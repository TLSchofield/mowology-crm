/**
 * Sign-out prompt (logout_secure.php) — shown only when the person is still clocked in.
 * "Clock out and sign out" goes through the normal clock-out API, so the post-trip vehicle
 * check and every other clock-out rule still applies, then stops native tracking and signs out.
 */
(function () {
    'use strict';

    var btn = document.getElementById('mwLogoutClockOut');
    var errBox = document.getElementById('mwLogoutError');
    if (!btn) return;

    function showError(msg, postTrip) {
        errBox.textContent = msg;
        if (postTrip) {
            var a = document.createElement('a');
            a.href = '/crm/driver-log-post.php';
            a.className = 'alert-link d-block mt-2';
            a.textContent = 'Do the post-trip check now';
            errBox.appendChild(a);
        }
        errBox.classList.remove('d-none');
        btn.disabled = false;
        btn.textContent = 'Clock out and sign out';
    }

    // Best-effort position for the clock-out punch; never hold the person up for it.
    function quickFix() {
        return new Promise(function (resolve) {
            if (!navigator.geolocation) return resolve(null);
            var done = false;
            var timer = setTimeout(function () { if (!done) { done = true; resolve(null); } }, 4000);
            navigator.geolocation.getCurrentPosition(function (p) {
                if (done) return;
                done = true; clearTimeout(timer);
                resolve({ lat: p.coords.latitude, lng: p.coords.longitude });
            }, function () {
                if (done) return;
                done = true; clearTimeout(timer);
                resolve(null);
            }, { enableHighAccuracy: false, maximumAge: 120000, timeout: 3500 });
        });
    }

    function stopNativeTracking() {
        try {
            var n = window.MwNative;
            if (n && n.location && typeof n.location.stopBackgroundTracking === 'function') {
                n.location.stopBackgroundTracking();
            }
            if (n && n.tracking && typeof n.tracking.stopSession === 'function') {
                n.tracking.stopSession();
            }
        } catch (e) { /* signing out regardless */ }
    }

    btn.addEventListener('click', function () {
        btn.disabled = true;
        btn.textContent = 'Clocking out…';
        errBox.classList.add('d-none');

        quickFix().then(function (pos) {
            var body = { action: 'clock_out', notes: 'Clocked out at sign-out' };
            if (pos) { body.lat = pos.lat; body.lng = pos.lng; }
            return fetch('/crm/api/time-clock.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.MW_CSRF_TOKEN || '' },
                body: JSON.stringify(body)
            });
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (d) { return { status: r.status, data: d }; });
        }).then(function (res) {
            var d = res.data || {};
            if (d.success) {
                stopNativeTracking();
                window.location.href = '/crm/logout_secure.php?stay=1';
                return;
            }
            showError(d.error || 'Could not clock you out. Check your signal and try again.', !!d.post_trip_required);
        }).catch(function () {
            showError('No connection — you are still clocked in. Try again when you have signal.', false);
        });
    });
})();
