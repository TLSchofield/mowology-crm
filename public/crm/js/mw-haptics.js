/**
 * Mowology Haptics
 * ══════════════════════════════════════════════════════════════════════
 * Thin wrapper over @capacitor/haptics + navigator.vibrate fallback.
 * Safe to call from any page — no-ops gracefully when neither API is
 * available (e.g. desktop browsers without navigator.vibrate).
 *
 * API (window.MwHaptics.*):
 *
 *   tap()      → light tap, for general button presses
 *   success()  → affirmative double-pulse for confirmations (clock-in,
 *                visit complete, save succeeded)
 *   warning()  → medium pulse for validation errors and warnings
 *   error()    → heavy triple pulse for destructive / failed actions
 *   selection()→ very light tick for toggles, selects, tab changes
 *
 * Auto-wiring:
 *   Add `data-haptic="tap"` (or success / warning / error / selection)
 *   to any element. MwHaptics installs a capture-phase click listener
 *   on document that fires the matching effect. No JS changes needed
 *   on the caller side.
 */
(function () {
    'use strict';

    if (typeof window === 'undefined') return;
    if (window.MwHaptics) return;

    // ── Native-first path ────────────────────────────────
    // Capacitor Haptics plugin — available when running inside the
    // native Android shell. The Plugins object may not exist yet when
    // mw-haptics.js loads, so we resolve it lazily on every call.
    function hapticsPlugin() {
        if (!window.Capacitor || !window.Capacitor.Plugins) return null;
        return window.Capacitor.Plugins.Haptics || null;
    }

    // Web fallback — navigator.vibrate patterns (milliseconds)
    var vibratePatterns = {
        tap:       20,
        selection: 10,
        success:   [20, 40, 20],
        warning:   60,
        error:     [40, 60, 40, 60, 40],
    };

    function webVibrate(kind) {
        if (!navigator.vibrate) return;
        var p = vibratePatterns[kind];
        if (p === undefined) return;
        try { navigator.vibrate(p); } catch (_) { /* silent */ }
    }

    function nativeHaptic(kind) {
        var h = hapticsPlugin();
        if (!h) return false;
        try {
            switch (kind) {
                case 'tap':
                    h.impact({ style: 'Light' });
                    return true;
                case 'selection':
                    if (h.selectionStart) {
                        h.selectionStart();
                        h.selectionEnd && h.selectionEnd();
                    } else {
                        h.impact({ style: 'Light' });
                    }
                    return true;
                case 'success':
                    if (h.notification) {
                        h.notification({ type: 'SUCCESS' });
                    } else {
                        h.impact({ style: 'Medium' });
                    }
                    return true;
                case 'warning':
                    if (h.notification) {
                        h.notification({ type: 'WARNING' });
                    } else {
                        h.impact({ style: 'Medium' });
                    }
                    return true;
                case 'error':
                    if (h.notification) {
                        h.notification({ type: 'ERROR' });
                    } else {
                        h.impact({ style: 'Heavy' });
                    }
                    return true;
            }
        } catch (e) { /* plugin call failed — fall back */ }
        return false;
    }

    function fire(kind) {
        // Respect the user's motion / reduce-haptics preference
        try {
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return;
            }
        } catch (_) { /* ignore */ }

        if (nativeHaptic(kind)) return;
        webVibrate(kind);
    }

    window.MwHaptics = {
        tap:       function () { fire('tap'); },
        selection: function () { fire('selection'); },
        success:   function () { fire('success'); },
        warning:   function () { fire('warning'); },
        error:     function () { fire('error'); },
        fire:      fire,
    };

    // ── Auto-wiring via data-haptic="..." ────────────────
    // Capture-phase click listener so even elements with
    // stopPropagation() in their own onclick still get the haptic.
    function installAutoWire() {
        document.addEventListener('click', function (e) {
            var el = e.target;
            // walk up to 4 ancestors looking for data-haptic
            for (var i = 0; i < 4 && el && el !== document; i++) {
                if (el.dataset && el.dataset.haptic) {
                    fire(el.dataset.haptic);
                    return;
                }
                el = el.parentNode;
            }
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', installAutoWire);
    } else {
        installAutoWire();
    }
}());
