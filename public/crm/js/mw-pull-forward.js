/**
 * MwPullForward — "You're at Oakridge Gardens. Lawn Cut is booked Wed Oct 7. Doing it now?"
 * ──────────────────────────────────────────────────────────────────────────────────────
 * Crew work ahead of schedule. GPS auto-arrival only knows TODAY's visits, so arriving at a
 * property whose visit is on another day did nothing, and the work got timed against visits
 * still dated later in the week. This asks instead of guessing:
 *
 *   [Start — moves it to today]  POST visit-pull-forward.php?mode=accept, then the existing
 *                                timer path POST job-timer.php {action:'start'}. Both are on
 *                                offline-queue.js, so a dropped signal queues them in order.
 *   [A different job here ▸]     the property's other plans' nearest visits (+ plans with
 *                                nothing booked → add today's visit).
 *   [Extra work (new one-off)]   field-job.php add_visit (the existing one-off flow), then start.
 *   [Not now] / [×]              quiet for this property for the rest of the day (this phone).
 *
 * 2026-10-08 INCIDENT — the first release cost Android crew (Capacitor app) every tap. Rules now:
 *   - The page NEVER calls navigator.geolocation. In the Capacitor WebView that request goes
 *     through Android's runtime-permission flow (BridgeWebChromeClient asks for FINE+COARSE
 *     whenever both are not already granted — e.g. a phone on "approximate" location), and it ran
 *     on every page open and every 3 min. The server decides from the fixes the phone already
 *     reports to crew-location.php.
 *   - Nothing is in the DOM unless a sheet is visibly showing: the overlay is created already in
 *     its open state (no class added on a later animation frame, no slide-in transition — a
 *     transparent full-screen layer waiting for a frame is a tap trap), z-index above every app
 *     shell (the schedule's .mw-mc-container is 1050) so it can never sit invisible underneath,
 *     with a visible × close. Any render error removes it. Hidden page → closed. Every request
 *     has a 20 s watchdog so "busy" can never strand it. No capture-phase listeners.
 *   - Only loaded at all when ops_settings pull_forward_enabled / pull_forward_user_ids allows
 *     this user (appstack_footer.php, homebase.php); the endpoint checks the same switch.
 *
 * CSRF: window.MW_CSRF_TOKEN (AppStack) or [data-csrf]; a stale/missing token is refreshed from
 * /crm/api/get-csrf.php and the call retried once — crew pages without MW_CSRF_TOKEN work too.
 * Styles: /crm/css/mw-pull-forward.css. Test hook: MwPullForward.show(offer).
 */
(function () {
    'use strict';
    if (window.MwPullForward) return;

    var API = '/crm/api/visit-pull-forward.php';
    var CHECK_EVERY_MS = 180000;
    var FIRST_CHECK_DELAY_MS = 5000;
    var REQUEST_TIMEOUT_MS = 20000;

    var root = null;
    var current = null;   // the offer on screen
    var busy = false;
    var checking = false;
    var timer = null;

    // ── Helpers ──────────────────────────────────────────────────────────────
    function isFieldDevice() {
        return !!window.MwNative || /Android|iPhone|iPad|iPod|Mobile|Capacitor/i.test(navigator.userAgent || '');
    }
    function isOnline() {
        if (window.MwNative && window.MwNative.network) return window.MwNative.network.isOnline !== false;
        return navigator.onLine !== false;
    }
    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function today() {
        var d = new Date();
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }
    function dismissKey(propertyId) { return 'mw_pf_dismissed_' + today() + '_' + propertyId; }
    function isDismissed(propertyId) {
        try { return !!localStorage.getItem(dismissKey(propertyId)); } catch (e) { return false; }
    }
    function dismiss(propertyId) {
        try { localStorage.setItem(dismissKey(propertyId), '1'); } catch (e) { /* private mode */ }
    }
    /** Reject after ms — so no request can leave the sheet stuck on a spinner. */
    function withTimeout(promise, ms) {
        return new Promise(function (resolve, reject) {
            var t = setTimeout(function () { reject(new Error('timeout')); }, ms);
            promise.then(function (v) { clearTimeout(t); resolve(v); }, function (e) { clearTimeout(t); reject(e); });
        });
    }

    // ── CSRF: page token, else refresh-and-retry ─────────────────────────────
    function csrf() {
        if (window.MW_CSRF_TOKEN) return window.MW_CSRF_TOKEN;
        var el = document.querySelector('[data-csrf]');
        return el ? (el.getAttribute('data-csrf') || '') : '';
    }
    function refreshCsrf() {
        return fetch('/crm/api/get-csrf.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.token) window.MW_CSRF_TOKEN = d.token; return d && d.token; })
            .catch(function () { return null; });
    }
    function isCsrfFailure(data) {
        return !!data && (data.code === 'CSRF_INVALID' || /CSRF/i.test(String(data.error || '')));
    }
    /** POST JSON with the CSRF token; on a CSRF failure refresh the token and retry once. */
    function postJson(url, body, headers, retried) {
        var ready = csrf() ? Promise.resolve() : refreshCsrf();
        var p = ready.then(function () {
            var payload = Object.assign({}, body, { csrf_token: csrf() });
            var h = Object.assign({ 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, headers || {});
            return fetch(url, { method: 'POST', credentials: 'same-origin', headers: h, body: JSON.stringify(payload) });
        }).then(function (r) {
            return r.json().then(function (d) { return { status: r.status, data: d }; },
                                 function () { return { status: r.status, data: null }; });
        }).then(function (res) {
            if (!retried && isCsrfFailure(res.data)) {
                return refreshCsrf().then(function () { return postJson(url, body, headers, true); });
            }
            return res;
        });
        return withTimeout(p, REQUEST_TIMEOUT_MS);
    }

    // ── Checking (no GPS here — the server uses this user's own recent pings) ─
    function check() {
        if (root || busy || checking || !isOnline() || document.visibilityState === 'hidden') return Promise.resolve(null);
        checking = true;
        return postJson(API + '?mode=offer', {})
            .then(function (res) {
                var offer = res.data && res.data.success ? res.data.offer : null;
                if (offer && offer.primary && !isDismissed(offer.property_id)
                    && document.visibilityState !== 'hidden') {
                    show(offer);
                }
                return offer;
            })
            .catch(function () { return null; })
            .then(function (v) { checking = false; return v; });
    }

    // ── Sheet ────────────────────────────────────────────────────────────────
    function onRootClick(e) {
        try {
            var btn = e.target && e.target.closest ? e.target.closest('[data-pf]') : null;
            if (btn) { if (!busy || btn.getAttribute('data-pf') === 'close') onAction(btn.getAttribute('data-pf'), btn); return; }
            if (e.target === root && !busy) notNow();
        } catch (err) {
            teardown();
        }
    }
    function removeOverlays() {
        var stale = document.querySelectorAll('.mw-pf-overlay');
        for (var i = 0; i < stale.length; i++) {
            if (stale[i].parentNode) stale[i].parentNode.removeChild(stale[i]);
        }
    }
    /** Remove every trace of the sheet immediately. */
    function teardown() {
        removeOverlays();
        root = null; current = null; busy = false;
    }
    function close() { teardown(); }

    /** Render into the sheet. Only show() may create it (already open and visible); a late
     *  callback after the crew closed it renders nothing. */
    function body(html, create) {
        try {
            if (!root && !create) return;
            if (!root) {
                removeOverlays(); // never two; keeps `current` (show() just set it)
                var el = document.createElement('div');
                el.className = 'mw-pf-overlay mw-pf-open';
                el.innerHTML = '<div class="mw-pf-sheet" role="dialog" aria-modal="true" aria-labelledby="mwPfTitle">' +
                               '<button type="button" class="mw-pf-close" data-pf="close" aria-label="Close">&times;</button>' +
                               '<div class="mw-pf-body"></div></div>';
                el.addEventListener('click', onRootClick);
                el.querySelector('.mw-pf-body').innerHTML = html;
                document.body.appendChild(el);
                root = el;
            } else {
                root.querySelector('.mw-pf-body').innerHTML = html;
            }
        } catch (err) {
            teardown();
        }
    }

    var PIN = '<svg class="mw-pf-pin" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z"/></svg>';

    function whenParts(v) {
        // when_label: "is booked Wed Oct 7" | "was due Mon Oct 5" — bold the date.
        var m = /^(is booked|was due) (.*)$/.exec(v.when_label || '');
        return m ? esc(m[1]) + ' <strong>' + esc(m[2]) + '</strong>' : esc(v.when_label || '');
    }

    function show(offer) {
        try {
            current = offer;
            var v = offer.primary;
            var what = v.service_type || v.title || 'The visit';
            var site = offer.site_name || offer.address;
            var others = (offer.others || []).length + (offer.plans_without_visit || []).length;
            body(
                '<div class="mw-pf-eyebrow">' + PIN + 'You\'re at ' + esc(site) + '</div>' +
                '<h2 class="mw-pf-title" id="mwPfTitle">' + esc(what) + ' for ' + esc(site) + ' ' + whenParts(v) + '.</h2>' +
                '<p class="mw-pf-question">Doing it now?</p>' +
                (v.overdue ? '<div class="mw-pf-chip mw-pf-chip-late">Overdue</div>' : '<div class="mw-pf-chip">' + esc(v.title || v.plan_number) + '</div>') +
                '<div class="mw-pf-actions">' +
                    '<button type="button" class="mw-pf-btn mw-pf-primary" data-pf="start" data-visit="' + (v.visit_id | 0) + '">' +
                        '<span class="mw-pf-btn-main">Start</span><span class="mw-pf-btn-sub">moves it to today</span></button>' +
                    (others ? '<button type="button" class="mw-pf-btn mw-pf-secondary" data-pf="different">A different job here<span class="mw-pf-chev" aria-hidden="true">›</span></button>' : '') +
                    '<button type="button" class="mw-pf-btn mw-pf-secondary" data-pf="extra" data-plan="' + (v.plan_id | 0) + '">Extra work <span class="mw-pf-muted">(new one-off)</span></button>' +
                    '<button type="button" class="mw-pf-btn mw-pf-ghost" data-pf="notnow">Not now</button>' +
                '</div>', true
            );
        } catch (err) {
            teardown();
        }
    }

    function showDifferent() {
        var o = current;
        var rows = (o.others || []).map(function (v) {
            return '<li class="mw-pf-row"><div class="mw-pf-row-text"><div class="mw-pf-row-title">' + esc(v.service_type || v.title) + '</div>' +
                   '<div class="mw-pf-row-sub">' + whenParts(v) + (v.overdue ? ' · overdue' : '') + '</div></div>' +
                   '<button type="button" class="mw-pf-btn mw-pf-primary mw-pf-btn-sm" data-pf="start" data-visit="' + (v.visit_id | 0) + '">Start</button></li>';
        }).concat((o.plans_without_visit || []).map(function (p) {
            return '<li class="mw-pf-row"><div class="mw-pf-row-text"><div class="mw-pf-row-title">' + esc(p.service_type || p.title) + '</div>' +
                   '<div class="mw-pf-row-sub">Nothing booked this week</div></div>' +
                   '<button type="button" class="mw-pf-btn mw-pf-secondary mw-pf-btn-sm" data-pf="extra" data-plan="' + (p.plan_id | 0) + '">Add today</button></li>';
        }));
        body(
            '<div class="mw-pf-eyebrow">' + PIN + esc(o.site_name || o.address) + '</div>' +
            '<h2 class="mw-pf-title" id="mwPfTitle">Which job are you doing?</h2>' +
            '<ul class="mw-pf-list">' + rows.join('') + '</ul>' +
            '<div class="mw-pf-actions"><button type="button" class="mw-pf-btn mw-pf-ghost" data-pf="back">‹ Back</button></div>'
        );
    }

    function showWorking(msg) {
        body('<div class="mw-pf-working"><div class="mw-pf-spinner" aria-hidden="true"></div><div>' + esc(msg) + '</div></div>');
    }
    function showDone(msg, queued) {
        busy = false;
        body('<div class="mw-pf-done"><div class="mw-pf-check" aria-hidden="true">✓</div><h2 class="mw-pf-title" id="mwPfTitle">' + esc(msg) + '</h2>' +
             (queued ? '<p class="mw-pf-note">No signal — saved on this phone. It sends when you\'re back online.</p>' : '') +
             '<div class="mw-pf-actions"><button type="button" class="mw-pf-btn mw-pf-primary" data-pf="done">Done</button></div></div>');
    }
    function showError(msg) {
        busy = false;
        body('<div class="mw-pf-done"><div class="mw-pf-x" aria-hidden="true">!</div><h2 class="mw-pf-title" id="mwPfTitle">' + esc(msg) + '</h2>' +
             '<div class="mw-pf-actions"><button type="button" class="mw-pf-btn mw-pf-secondary" data-pf="back">‹ Back</button>' +
             '<button type="button" class="mw-pf-btn mw-pf-ghost" data-pf="notnow">Close</button></div></div>');
    }

    // ── Actions ──────────────────────────────────────────────────────────────
    function onAction(kind, btn) {
        if (kind === 'close' || kind === 'notnow') { notNow(); return; }
        if (kind === 'back') { if (current) show(current); else teardown(); return; }
        if (kind === 'different') { showDifferent(); return; }
        if (kind === 'done') { teardown(); window.location.reload(); return; }
        if (kind === 'start') { startVisit(parseInt(btn.getAttribute('data-visit'), 10)); return; }
        if (kind === 'extra') { extraWork(parseInt(btn.getAttribute('data-plan'), 10)); return; }
    }

    function notNow() {
        if (current) dismiss(current.property_id);
        teardown();
    }

    /** The existing timer path — job-timer.php start (offline-queued, idempotent by header). */
    function startTimer(visitId) {
        var b = { action: 'start', visit_id: visitId };
        var fix = current && current.fix;
        if (fix && fix.lat && fix.lng) { b.lat = fix.lat; b.lng = fix.lng; }
        return withTimeout(fetch('/crm/api/job-timer.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Idempotency-Key': uuid() },
            body: JSON.stringify(b)
        }).then(function (r) { return r.json(); }), REQUEST_TIMEOUT_MS);
    }

    function afterStart(visitId, data, label, queuedMove) {
        if (!data || !data.success) {
            showError((data && data.error) || 'The visit moved to today, but the timer did not start. Start it from the schedule.');
            return;
        }
        try {
            if (window.MwTimeClock) {
                if (window.MwTimeClock.notifyJobTimerStarted) window.MwTimeClock.notifyJobTimerStarted();
                if (window.MwTimeClock.fetchStatus) window.MwTimeClock.fetchStatus();
            }
            document.dispatchEvent(new CustomEvent('mw-pull-forward-started', { detail: { visit_id: visitId } }));
        } catch (e) { /* the timer is running; UI refresh is a nicety */ }
        showDone(label, !!(data.queued || queuedMove));
    }

    function startVisit(visitId) {
        if (!visitId || !current || busy) return;
        busy = true;
        showWorking('Moving it to today…');
        postJson(API + '?mode=accept', { visit_id: visitId, property_id: current.property_id, request_key: uuid() })
            .then(function (res) {
                var d = res.data;
                if (!d || !d.success) { showError((d && d.error) || 'Could not move the visit.'); return null; }
                showWorking('Starting the timer…');
                return startTimer(visitId).then(function (t) { afterStart(visitId, t, 'Timer running — it\'s on today\'s schedule', d.queued); });
            })
            .catch(function () { showError('No connection — try again in a moment.'); });
    }

    function extraWork(planId) {
        if (!planId || busy) return;
        busy = true;
        showWorking('Adding a one-off visit…');
        postJson('/crm/api/field-job.php', { action: 'add_visit', plan_id: planId, client_request_id: uuid() })
            .then(function (res) {
                var d = res.data;
                if (!d || !d.success) { showError((d && d.error) || 'Could not add the visit.'); return null; }
                if (!d.visit_id) { showDone('Visit added', d.queued); return null; }
                showWorking('Starting the timer…');
                return startTimer(d.visit_id).then(function (t) { afterStart(d.visit_id, t, 'Extra visit added — timer running', d.queued); });
            })
            .catch(function () { showError('No connection — try again in a moment.'); });
    }

    // ── Boot ─────────────────────────────────────────────────────────────────
    function boot() {
        if (!isFieldDevice()) return;
        setTimeout(check, FIRST_CHECK_DELAY_MS);
        timer = setInterval(check, CHECK_EVERY_MS);
        window.addEventListener('pagehide', function () { clearInterval(timer); teardown(); }, { once: true });
        // A hidden page must not come back to a sheet nobody asked for mid-task.
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden' && root && !busy) teardown();
        });
        // Auto-arrival started something: this sheet is no longer the question.
        document.addEventListener('mw-proximity-auto-start', function () { if (root && !busy) teardown(); });
    }

    window.MwPullForward = { check: check, show: show, close: close };

    if (!window.MW_PULL_FORWARD_NO_BOOT) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
        else boot();
    }
})();
