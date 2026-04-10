/**
 * Mowology API Error Toast
 * ══════════════════════════════════════════════════════════════════════
 * Consumes the structured error shape returned by CRM APIs (introduced
 * in Sprint Ⓐ A7 for pow-actions.php):
 *
 *   { success: false, error: "...", code: "CODE", retryable: boolean }
 *
 * and renders a toast matching the severity, with an inline "Try again"
 * button when retryable === true.
 *
 * Fires MwHaptics.error() / .warning() automatically.
 *
 * Usage
 *   fetch('/crm/api/pow-actions.php', ...)
 *     .then(function (r) { return r.json(); })
 *     .then(function (data) {
 *         if (!data.success) {
 *             MwApiToast.show(data, function () { retryTheSameCall(); });
 *             return;
 *         }
 *         // success path
 *     });
 *
 * Falls back to a plain toast when the response lacks the structured
 * fields so legacy API handlers still render a reasonable message.
 */
(function () {
    'use strict';

    if (typeof window === 'undefined') return;
    if (window.MwApiToast) return;

    // Pick a toast type based on the structured code. Retryable / server
    // errors get a warning pulse; validation and auth failures get an
    // error pulse so the user feels the difference.
    function severityFor(data) {
        var code = (data && data.code) || '';
        if (!code) return 'error';
        if (code === 'DB_ERROR' || code === 'SERVER_ERROR' || code === 'NETWORK') return 'warning';
        return 'error';
    }

    function showPlainToast(message, kind) {
        if (typeof window.mwToast === 'function') {
            window.mwToast(message, kind);
            return;
        }
        // Last-resort: alert() so the user sees something on pages that
        // never loaded mw-toast.js. Not pretty but better than silent.
        try { window.alert(message); } catch (_) { /* ignore */ }
    }

    /**
     * Render a toast for an API error response.
     *
     * @param data       {object}   the parsed response body
     * @param onRetry    {function} optional retry handler; shown as a
     *                              button in the toast when
     *                              data.retryable === true
     */
    function show(data, onRetry) {
        data = data || {};
        var kind = severityFor(data);
        var msg  = data.error || 'Something went wrong. Please try again.';

        // Haptic feedback
        try {
            if (window.MwHaptics) {
                if (kind === 'warning') window.MwHaptics.warning();
                else window.MwHaptics.error();
            }
        } catch (_) { /* ignore */ }

        // If we have a retry handler AND the response is retryable, wire
        // it into a custom toast element — otherwise fall through to the
        // plain mwToast() helper.
        if (typeof onRetry === 'function' && data.retryable && typeof window.mwToast === 'function') {
            var container = document.getElementById('mw-toast-container') ||
                            createContainer();
            var toast = document.createElement('div');
            toast.className = 'mw-toast mw-toast--' + kind + ' mw-toast--has-action';
            toast.setAttribute('role', 'alert');
            toast.setAttribute('aria-live', 'assertive');
            toast.setAttribute('aria-atomic', 'true');

            var msgEl = document.createElement('span');
            msgEl.className = 'mw-toast-msg';
            msgEl.textContent = msg;

            var retryBtn = document.createElement('button');
            retryBtn.type = 'button';
            retryBtn.className = 'mw-toast-action';
            retryBtn.textContent = 'Try again';
            retryBtn.setAttribute('data-haptic', 'tap');
            retryBtn.addEventListener('click', function () {
                dismiss(toast);
                try { onRetry(); } catch (e) { /* swallow */ }
            });

            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'mw-toast-close';
            closeBtn.setAttribute('aria-label', 'Dismiss');
            closeBtn.innerHTML = '&times;';
            closeBtn.addEventListener('click', function () { dismiss(toast); });

            toast.appendChild(msgEl);
            toast.appendChild(retryBtn);
            toast.appendChild(closeBtn);
            container.appendChild(toast);

            // Auto-dismiss after 8s (longer than a plain toast — user
            // needs time to notice the retry button)
            setTimeout(function () { dismiss(toast); }, 8000);
            return;
        }

        // Fallback path — plain toast
        showPlainToast(msg, kind);
    }

    function createContainer() {
        var c = document.createElement('div');
        c.id = 'mw-toast-container';
        c.className = 'mw-toast-container';
        c.setAttribute('role', 'region');
        c.setAttribute('aria-label', 'Notifications');
        document.body.appendChild(c);
        return c;
    }

    function dismiss(toast) {
        if (!toast || !toast.parentNode) return;
        toast.classList.add('mw-toast--leaving');
        setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 250);
    }

    window.MwApiToast = { show: show };
}());
