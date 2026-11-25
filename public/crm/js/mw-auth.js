/**
 * mw-auth.js — Mowology CRM client-side JWT manager
 *
 * Responsibilities:
 *  1. Store / retrieve the 30-day JWT issued on login.
 *  2. Auto-redirect on the login page when a valid token already exists
 *     (offline login — skips the server round-trip entirely).
 *  3. Patch window.fetch so every /crm/api/ request carries the
 *     Authorization: Bearer header — meaning API calls work even when
 *     the PHP session has expired, as long as the JWT is valid.
 *
 * No dependencies. Loads synchronously before page logic.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'mw_jwt';
    // Buffer: treat a token expiring within 5 minutes as already expired
    var EXPIRY_BUFFER_S = 300;

    // ── Storage helpers ───────────────────────────────────────────────────────

    function store(tokenObj) {
        // tokenObj = { token: string, user: {id,email,name,role}, exp: unix_ts }
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(tokenObj)); } catch (e) {}
    }

    function getValid() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return null;
            var d = JSON.parse(raw);
            if (!d || !d.token || !d.exp) return null;
            if (Math.floor(Date.now() / 1000) >= d.exp - EXPIRY_BUFFER_S) {
                localStorage.removeItem(STORAGE_KEY);
                return null;
            }
            return d;
        } catch (e) { return null; }
    }

    function getToken() {
        var d = getValid();
        return d ? d.token : null;
    }

    function getUser() {
        var d = getValid();
        return d ? d.user : null;
    }

    function clear() {
        try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
    }

    // ── Offline login bypass ──────────────────────────────────────────────────
    // If the user lands on the login page but already has a valid token,
    // skip the server login and go straight to the dashboard.
    // This is what makes "local login" work during a server outage.

    function checkOfflineLogin() {
        var path = window.location.pathname;
        if (path.indexOf('login_secure.php') === -1 && path.indexOf('login.php') === -1) return;
        var d = getValid();
        if (d) {
            window.location.replace('/crm/dashboard_appstack.php');
        }
    }

    // ── Fetch interceptor ─────────────────────────────────────────────────────
    // Attaches Bearer token to every /crm/api/ and /api/ request so that
    // requireLoginOrJwt() on the server side accepts the call.

    var _originalFetch = window.fetch;
    window.fetch = function (input, init) {
        var url = (typeof input === 'string') ? input : (input.url || '');
        if (url.indexOf('/crm/api/') !== -1 || url.indexOf('/api/') !== -1) {
            var token = getToken();
            if (token) {
                init = init || {};
                init.headers = init.headers || {};
                // Support both plain object and Headers instance
                if (typeof init.headers.set === 'function') {
                    init.headers.set('Authorization', 'Bearer ' + token);
                } else {
                    init.headers['Authorization'] = 'Bearer ' + token;
                }
            }
        }
        return _originalFetch.call(this, input, init);
    };

    // ── Public API ────────────────────────────────────────────────────────────

    window.MwAuth = {
        store:    store,
        getValid: getValid,
        getToken: getToken,
        getUser:  getUser,
        clear:    clear,
    };

    // Run offline login check immediately (synchronous — no defer)
    checkOfflineLogin();

})();
