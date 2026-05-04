/**
 * Mowology CRM — JSON API Service Layer
 *
 * Wraps fetch() POST calls to JSON API endpoints with:
 *   - CSRF token injection (from MwApi.setToken())
 *   - Idempotency key generation and injection
 *   - HTTP error guard (throws on !r.ok)
 *   - Consistent Content-Type: application/json header
 *
 * Usage:
 *   MwApi.setToken(csrfToken);   // once on init, again after token refresh
 *   MwApi.post('/crm/api/job-timer.php', { action: 'start', visit_id: 42, ... })
 *       .then(function(data) {
 *           if (data.success) { ... } else { showToast(data.error); }
 *       })
 *       .catch(function(err) { console.error('[tag]', err); showToast('Network error.'); });
 *
 * Idempotency keys are generated per call and embedded in the body.
 * The offline-queue.js stores bodyRaw verbatim, so replay sends the same key —
 * PHP guardIdempotency() deduplicates via INSERT IGNORE on replay.
 *
 * Out of scope (handled by their own specialized code):
 *   - FormData uploads (media-upload.php) — own timeout, CSRF retry, status routing
 *   - GET requests (field-observations get-rules, get-csrf.php) — no mutation
 *
 * @package Mowology CRM
 */
(function () {
    'use strict';

    var _csrf = '';

    function setToken(token) {
        _csrf = token || '';
    }

    function generateIdempKey() {
        if (window.crypto && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }
        // Fallback for older WebKit (iOS 14)
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
        });
    }

    /**
     * POST to a JSON API endpoint.
     *
     * Automatically injects csrf_token and idempotency_key into the payload.
     * Throws on any non-2xx HTTP status. Returns the parsed response object.
     * Callers are responsible for checking data.success and handling data.error.
     */
    function post(endpoint, body) {
        var payload = Object.assign({}, body, {
            csrf_token:      _csrf,
            idempotency_key: generateIdempKey()
        });

        return fetch(endpoint, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload)
        })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    window.MwApi = {
        setToken: setToken,
        post:     post
    };

}());
