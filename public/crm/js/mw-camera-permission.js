/**
 * MwCameraPermission — shared Android camera-permission guard
 * ──────────────────────────────────────────────────────────
 * On the native Android Capacitor app, the OS camera is reached through the
 * WebView file picker (`<input type="file" capture="environment">`). When the
 * app's CAMERA permission is OFF, that picker throws the literal string
 * "Access denied" with no guidance.
 *
 * This module caches the camera permission state and surfaces an actionable
 * "Camera access denied → Open Settings" dialog so any page can guard its
 * photo-capture buttons. Helpers were extracted from schedule-pill-workflow.js
 * (which has its own private copies for the schedule page).
 *
 * Public API — window.MwCameraPermission:
 *   isNativeAndroid()        → bool
 *   refresh(cb)              → re-query permission; cb(stateStr) when resolved
 *   isDenied()               → cached state === 'denied'
 *   showDeniedDialog()       → full-screen guidance overlay
 *
 * Loaded globally in appstack_footer.php.
 */
(function (global) {
    'use strict';

    var _camPermState = null; // 'granted' | 'denied' | 'prompt' | null (unknown)

    function isNativeAndroid() {
        try {
            return !!(global.Capacitor &&
                      global.Capacitor.isNativePlatform &&
                      global.Capacitor.isNativePlatform() &&
                      global.Capacitor.getPlatform &&
                      global.Capacitor.getPlatform() === 'android');
        } catch (e) { return false; }
    }

    /**
     * Query the WebView camera permission and cache it in _camPermState.
     * Uses the standard Permissions API (Chromium-based Android WebView supports
     * the 'camera' permission name). Attaches a one-time onchange listener so the
     * cache stays fresh if the user toggles the permission in Settings and returns.
     * Calls cb(stateStr) when resolved; degrades gracefully where unsupported.
     */
    function refreshCameraPermission(cb) {
        if (!navigator.permissions || !navigator.permissions.query) {
            if (cb) cb(_camPermState);
            return;
        }
        try {
            navigator.permissions.query({ name: 'camera' }).then(function (status) {
                _camPermState = status.state; // 'granted' | 'denied' | 'prompt'
                if (!status._mwBound) {
                    status._mwBound = true;
                    status.onchange = function () { _camPermState = status.state; };
                }
                if (cb) cb(_camPermState);
            }).catch(function () {
                // 'camera' not a queryable permission on this device — leave unknown.
                if (cb) cb(_camPermState);
            });
        } catch (e) {
            if (cb) cb(_camPermState);
        }
    }

    /**
     * Open the OS app-settings screen so the user can enable Camera. Prefers a
     * native helper, then the Capacitor App plugin's openUrl with an Android
     * app-details intent. Best-effort: the dialog's text instructions stand on
     * their own if the device ignores the intent.
     */
    function openAppSettings() {
        try {
            if (global.MwNative && typeof global.MwNative.openAppSettings === 'function') {
                global.MwNative.openAppSettings();
                return;
            }
            var plugins = global.Capacitor && global.Capacitor.Plugins;
            if (plugins && plugins.App && typeof plugins.App.openUrl === 'function') {
                var pkg = (global.Capacitor.getPlatform && global.Capacitor.getPlatform() === 'android')
                    ? 'ca.mowology.crew' : null; // app id is .crew (not .crm)
                var url = pkg
                    ? 'intent://' + pkg + '#Intent;scheme=package;action=android.settings.APPLICATION_DETAILS_SETTINGS;end'
                    : 'app-settings:';
                plugins.App.openUrl({ url: url }).catch(function () {
                    // Fall back to the generic Settings app.
                    plugins.App.openUrl({ url: 'intent://#Intent;action=android.settings.SETTINGS;end' })
                        .catch(function () {});
                });
            }
        } catch (e) { /* text instructions cover this */ }
    }

    /**
     * Full-screen "Camera access denied" guidance with an Open Settings button.
     * Shown when the OS has blocked camera access for the app.
     */
    function showCameraDeniedDialog() {
        var existing = document.getElementById('mw-cam-denied-dialog');
        if (existing) return;

        var overlay = document.createElement('div');
        overlay.id = 'mw-cam-denied-dialog';
        overlay.style.cssText =
            'position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,0.6);' +
            'display:flex;align-items:center;justify-content:center;padding:24px;';

        overlay.innerHTML =
            '<div style="background:#fff;border-radius:14px;max-width:340px;width:100%;' +
                'padding:24px 22px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.4);">' +
                '<div style="font-size:2rem;margin-bottom:10px;">📷</div>' +
                '<h3 style="margin:0 0 10px;font-size:1.1rem;font-weight:700;color:#1a1a1a;">Camera access denied</h3>' +
                '<p style="margin:0 0 18px;font-size:0.88rem;line-height:1.5;color:#555;">' +
                    'Camera access denied — go to <strong>Settings &rsaquo; Apps &rsaquo; Mowology &rsaquo; ' +
                    'Permissions</strong> and enable <strong>Camera</strong>.' +
                '</p>' +
                '<button id="mw-cam-denied-settings" style="' +
                    'display:block;width:100%;padding:13px;margin-bottom:10px;border:none;border-radius:8px;' +
                    'background:#2d8659;color:#fff;font-weight:700;font-size:0.92rem;cursor:pointer;">' +
                    'Open Settings' +
                '</button>' +
                '<button id="mw-cam-denied-dismiss" style="' +
                    'display:block;width:100%;padding:11px;border:none;border-radius:8px;' +
                    'background:transparent;color:#888;font-weight:600;font-size:0.88rem;cursor:pointer;">' +
                    'Not now' +
                '</button>' +
            '</div>';

        document.body.appendChild(overlay);
        document.getElementById('mw-cam-denied-settings').addEventListener('click', function () {
            openAppSettings();
            overlay.remove();
        });
        document.getElementById('mw-cam-denied-dismiss').addEventListener('click', function () {
            overlay.remove();
        });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) overlay.remove();
        });
    }

    // Cache permission state up front on native Android so the very first photo
    // tap can be guarded synchronously (preserving the user-gesture requirement).
    if (isNativeAndroid()) refreshCameraPermission();

    global.MwCameraPermission = {
        isNativeAndroid : isNativeAndroid,
        refresh         : refreshCameraPermission,
        isDenied        : function () { return _camPermState === 'denied'; },
        showDeniedDialog: showCameraDeniedDialog
    };

})(window);
