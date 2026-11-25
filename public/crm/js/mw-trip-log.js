/**
 * MwTripLog — the commercial vehicle log on web / Android (Capacitor).
 *
 * Two jobs:
 *
 * 1. WHO owes a log is decided per SHIFT. After clock-in the crew member is asked once,
 *    "Are you driving a company vehicle this shift?" — instead of the permanent
 *    users.is_driver flag, which never asked an owner who drives some days and always
 *    forced a flagged employee through it even as a passenger.
 *
 * 2. SERVER FIRST, PHONE AS THE BACKUP. With signal an entry is filed straight away and
 *    the driver sees it confirmed (or sees the server's objection while still on the
 *    form). Only when the server can't be reached is it kept on the phone — with the
 *    time it was actually DONE — and filed automatically when back in range, in order.
 *    A driver is responsible for their own log; a dead zone must never be why one
 *    doesn't exist. The server honours the device time within bounds and treats a
 *    replay as the same inspection, so re-sending can never create a second trip.
 *
 * Usage:
 *   MwTripLog.afterClockIn(res, userId)  → Promise<url|null>  where to go next
 *   MwTripLog.submit(action, fields, userId) → Promise<{status:'filed'|'queued'|'rejected', ...}>
 *   MwTripLog.flush(userId)              → files anything waiting
 *   MwTripLog.waiting(userId)            → count saved on this phone
 */
(function () {
    'use strict';
    if (window.MwTripLog) return;

    var KEY = 'mw-trip-log-queue';
    var API = '/crm/api/trip-report.php';
    var flushing = false;

    function csrf() {
        if (window.MW_CSRF_TOKEN) return window.MW_CSRF_TOKEN;
        var el = document.querySelector('input[name="csrf_token"]');
        return el ? el.value : '';
    }

    function readQueue() {
        try { return JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { return []; }
    }
    function writeQueue(q) {
        try { localStorage.setItem(KEY, JSON.stringify(q)); return true; } catch (e) { return false; }
    }

    function mine(userId) {
        return readQueue().filter(function (i) { return i.userId === userId && !i.rejection; });
    }

    /** POST one entry. Resolves {ok, data} on any HTTP answer; rejects only on a transport failure. */
    function post(fields, performedAt) {
        var body = new FormData();
        Object.keys(fields).forEach(function (k) {
            if (fields[k] !== undefined && fields[k] !== null) body.append(k, fields[k]);
        });
        body.set('csrf_token', csrf());
        body.set('performed_at', String(performedAt));
        return fetch(API, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) {
            // A login page or a 5xx is "couldn't file it", not "the server refused it".
            if (r.status === 401 || r.status === 403 || r.status >= 500) throw new Error('unavailable:' + r.status);
            return r.json().then(function (data) { return { ok: r.ok && data && data.success, data: data || {} }; });
        });
    }

    function enqueue(userId, action, fields, performedAt) {
        var q = readQueue();
        q.push({ id: String(Date.now()) + Math.random().toString(16).slice(2), userId: userId,
                 action: action, fields: fields, performedAt: performedAt, rejection: null });
        return writeQueue(q);
    }

    function flush(userId) {
        if (flushing || !userId) return Promise.resolve(0);
        var pending = mine(userId);
        if (!pending.length) return Promise.resolve(0);
        flushing = true;

        var filed = 0;
        function next() {
            var item = mine(userId)[0];
            if (!item) return Promise.resolve();
            var fields = Object.assign({}, item.fields, { action: item.action });
            // The device already made the driver confirm an unusual reading; nobody to ask now.
            if (item.action === 'save_post_trip') fields.confirm_odometer = '1';
            return post(fields, item.performedAt).then(function (res) {
                var q = readQueue();
                if (res.ok) {
                    writeQueue(q.filter(function (i) { return i.id !== item.id; }));
                    filed++;
                    return next();
                }
                // A definite refusal: keep it, show it, never drop it silently.
                q.forEach(function (i) { if (i.id === item.id) i.rejection = res.data.error || 'The server did not accept this entry.'; });
                writeQueue(q);
            });
        }
        return next().catch(function () { /* still no signal — leave it all queued, in order */ })
            .then(function () { flushing = false; notify(userId); return filed; });
    }

    /**
     * Server first; the phone is the backup.
     * @returns Promise<{status:'filed', data} | {status:'queued'} | {status:'rejected', message, code}>
     */
    function submit(action, fields, userId) {
        var performedAt = Date.now();
        fields = Object.assign({}, fields, { action: action });
        delete fields.csrf_token;       // a stored token would be stale by the time a queue files

        return flush(userId).then(function () {
            // Something older is still waiting → keep order: this one joins the back.
            if (mine(userId).length) { enqueue(userId, action, fields, performedAt); notify(userId); return { status: 'queued' }; }
            return post(fields, performedAt).then(function (res) {
                if (res.ok) return { status: 'filed', data: res.data };
                return { status: 'rejected', message: res.data.error || 'Could not save.', code: res.data.code || null };
            }, function () {
                enqueue(userId, action, fields, performedAt);
                notify(userId);
                return { status: 'queued' };
            });
        });
    }

    function notify(userId) {
        document.dispatchEvent(new CustomEvent('mw-trip-log-changed', {
            detail: { waiting: mine(userId).length,
                      rejected: readQueue().filter(function (i) { return i.userId === userId && i.rejection; }) }
        }));
    }

    // ── "Are you driving this shift?" ───────────────────────────────────────
    // Self-contained sheet: the pages that clock people in (app-launch, homebase) are
    // standalone and don't load the CRM stylesheet. Colours come from whichever page's
    // brand variable exists — no literal colour values here.
    var GREEN = 'var(--mw-green, var(--hb-green, var(--al-green, var(--dl-green, green))))';

    function ask() {
        return new Promise(function (resolve) {
            var wrap = document.createElement('div');
            wrap.setAttribute('role', 'dialog');
            wrap.setAttribute('aria-modal', 'true');
            wrap.style.cssText = 'position:fixed;inset:0;z-index:100000;display:flex;align-items:flex-end;justify-content:center;background:rgba(0,0,0,.55);';
            var btn = 'display:block;width:100%;padding:15px;margin-top:10px;border-radius:999px;font-size:16px;font-weight:700;';
            wrap.innerHTML =
                '<div style="width:100%;max-width:520px;background:Canvas;color:CanvasText;border-radius:20px 20px 0 0;padding:26px 22px calc(22px + env(safe-area-inset-bottom));text-align:center;">' +
                  '<h2 style="font-size:20px;margin:0 0 10px;">Are you driving a company vehicle this shift?</h2>' +
                  '<p style="font-size:14px;opacity:.75;margin:0 0 16px;line-height:1.45;">The driver has to inspect the vehicle before it moves and log the trip — it\'s a legal requirement. If you\'re riding along, you don\'t. You can change this later if you take over the wheel.</p>' +
                  '<button type="button" data-a="yes" style="' + btn + 'border:0;color:white;background:' + GREEN + ';">Yes, I\'m driving</button>' +
                  '<button type="button" data-a="no" style="' + btn + 'background:transparent;color:inherit;border:1px solid currentColor;opacity:.85;">No, I\'m not driving</button>' +
                '</div>';
            wrap.addEventListener('click', function (e) {
                var a = e.target && e.target.getAttribute && e.target.getAttribute('data-a');
                if (!a) return;
                document.body.removeChild(wrap);
                resolve(a === 'yes');
            });
            document.body.appendChild(wrap);
        });
    }

    /**
     * Call with the clock-in response. Resolves to the URL to go to next, or null to stay put.
     */
    function afterClockIn(res, userId) {
        res = res || {};
        if (res.pre_trip_required) return Promise.resolve('/crm/driver-log.php');
        if (!res.driver_question_required) return Promise.resolve(null);
        return ask().then(function (driving) {
            if (driving) return '/crm/driver-log.php';          // doing the pre-trip IS the declaration
            return submit('declare', { driving: '0' }, userId).then(function () { return null; });
        });
    }

    window.MwTripLog = {
        submit: submit,
        flush: flush,
        afterClockIn: afterClockIn,
        waiting: function (userId) { return mine(userId).length; },
        rejected: function (userId) { return readQueue().filter(function (i) { return i.userId === userId && i.rejection; }); },
        discardRejected: function (userId) {
            writeQueue(readQueue().filter(function (i) { return !(i.userId === userId && i.rejection); }));
            notify(userId);
        }
    };

    // Back in range, or the app came back to the front → file whatever is waiting.
    function auto() { if (window.MW_USER_ID) flush(window.MW_USER_ID); }
    window.addEventListener('online', auto);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) auto(); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', auto); else auto();
}());
