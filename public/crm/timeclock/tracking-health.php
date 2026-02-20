<?php
/**
 * Tracking Health — GPS diagnostics + device setup wizard.
 *
 * Shows a setup checklist with one-tap fix buttons for every required
 * device setting (permissions, battery, GPS). Falls back gracefully
 * in browser mode. Accessible to all logged-in crew.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle = 'Tracking Health';
$activePage = 'schedule';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="row mb-3">
    <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-0">GPS Tracking Health</h1>
            <p class="text-muted mb-0">Device setup &amp; diagnostics for crew GPS tracking</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary btn-sm" onclick="runSetupCheck()">
                <i data-feather="refresh-cw" class="me-1"></i>Re-check All
            </button>
            <button class="btn btn-outline-success btn-sm" onclick="testGPS()">
                <i data-feather="crosshair" class="me-1"></i>Test GPS
            </button>
            <button class="btn btn-outline-info btn-sm" onclick="forceSyncNow()">
                <i data-feather="upload-cloud" class="me-1"></i>Sync Now
            </button>
        </div>
    </div>
</div>

<!-- App Update Available -->
<div id="updateAlert" class="alert alert-warning d-flex align-items-center gap-3 mb-3" style="display:none!important;">
    <span style="font-size:1.5rem;">⬆️</span>
    <div class="flex-grow-1">
        <strong>App Update Available</strong> — version <span id="updateVersion"></span> is ready.<br>
        <small id="updateNotes" class="text-muted"></small>
    </div>
    <a id="updateApkLink" href="/crm/downloads/mowology-crew.apk" class="btn btn-warning btn-sm flex-shrink-0" download>
        Download Update
    </a>
</div>

<!-- Browser Mode Warning -->
<div id="nativeCheck" class="alert alert-warning mb-3" style="display:none;">
    <strong>Browser Mode:</strong> You're viewing this in a web browser — full setup checks require the Mowology Crew Android app.
    <a href="/crm/downloads/mowology-crew.apk" class="alert-link ms-1">Download the app</a>
</div>

<!-- ═══════════════════════════════════════════════════════
     SETUP WIZARD
     ═══════════════════════════════════════════════════════ -->
<div class="card mb-3" id="setupWizard">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h5 class="card-title mb-0">
            <i data-feather="settings" class="me-2" style="width:18px;height:18px;"></i>Device Setup
        </h5>
        <span id="setupSummaryBadge" class="badge bg-secondary">Checking…</span>
    </div>
    <div class="card-body p-0">

        <!-- Setup Item Template (repeated for each check) -->
        <!-- id="si-{key}" -->

        <!-- 1. GPS Hardware -->
        <div class="mw-setup-item" id="si-gps">
            <div class="mw-setup-icon" id="si-gps-icon">⏳</div>
            <div class="mw-setup-body">
                <div class="mw-setup-title">GPS / Location Services</div>
                <div class="mw-setup-desc" id="si-gps-desc">Checking…</div>
            </div>
            <div class="mw-setup-action" id="si-gps-action"></div>
        </div>

        <!-- 2. Foreground location permission -->
        <div class="mw-setup-item" id="si-loc">
            <div class="mw-setup-icon" id="si-loc-icon">⏳</div>
            <div class="mw-setup-body">
                <div class="mw-setup-title">Location Permission</div>
                <div class="mw-setup-desc" id="si-loc-desc">Checking…</div>
            </div>
            <div class="mw-setup-action" id="si-loc-action"></div>
        </div>

        <!-- 3. Background location permission -->
        <div class="mw-setup-item" id="si-bgloc">
            <div class="mw-setup-icon" id="si-bgloc-icon">⏳</div>
            <div class="mw-setup-body">
                <div class="mw-setup-title">Background Location</div>
                <div class="mw-setup-desc" id="si-bgloc-desc">Checking…</div>
            </div>
            <div class="mw-setup-action" id="si-bgloc-action"></div>
        </div>

        <!-- 4. Battery optimization -->
        <div class="mw-setup-item" id="si-battery">
            <div class="mw-setup-icon" id="si-battery-icon">⏳</div>
            <div class="mw-setup-body">
                <div class="mw-setup-title">Battery Optimization</div>
                <div class="mw-setup-desc" id="si-battery-desc">Checking…</div>
            </div>
            <div class="mw-setup-action" id="si-battery-action"></div>
        </div>

        <!-- 5. Network / sync -->
        <div class="mw-setup-item" id="si-network">
            <div class="mw-setup-icon" id="si-network-icon">⏳</div>
            <div class="mw-setup-body">
                <div class="mw-setup-title">Network Connection</div>
                <div class="mw-setup-desc" id="si-network-desc">Checking…</div>
            </div>
            <div class="mw-setup-action" id="si-network-action"></div>
        </div>

    </div><!-- /card-body -->

    <!-- OEM-specific battery instructions (shown inline when needed) -->
    <div id="oemInlineCard" class="card-footer bg-warning-subtle border-top border-warning" style="display:none;">
        <strong>📱 Steps for your phone:</strong>
        <p class="mb-2 mt-1" id="oemInlineText">—</p>
        <button class="btn btn-warning btn-sm" onclick="requestBatteryExemption()">
            Open Battery Settings Now
        </button>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     DIAGNOSTICS DETAIL
     ═══════════════════════════════════════════════════════ -->
<div class="row" id="healthCards">

    <!-- GPS Status -->
    <div class="col-md-6 col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">GPS Status</h5></div>
            <div class="card-body">
                <div class="mw-health-row">
                    <span class="mw-health-label">Tracking Active</span>
                    <span class="mw-health-value" id="hTrackingActive">—</span>
                </div>
                <div class="mw-health-row">
                    <span class="mw-health-label">Last Fix</span>
                    <span class="mw-health-value" id="hLastFix">—</span>
                </div>
                <div class="mw-health-row">
                    <span class="mw-health-label">Accuracy</span>
                    <span class="mw-health-value" id="hAccuracy">—</span>
                </div>
                <div class="mw-health-row">
                    <span class="mw-health-label">Provider</span>
                    <span class="mw-health-value" id="hProvider">—</span>
                </div>
                <div class="mw-health-row">
                    <span class="mw-health-label">Activity</span>
                    <span class="mw-health-value" id="hActivity">—</span>
                </div>
                <div class="mw-health-row">
                    <span class="mw-health-label">GPS Interval</span>
                    <span class="mw-health-value" id="hInterval">—</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Permissions -->
    <div class="col-md-6 col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">Permissions</h5></div>
            <div class="card-body">
                <div class="mw-health-row">
                    <span class="mw-health-label">Location (foreground)</span>
                    <span class="mw-health-value" id="hLocPerm">—</span>
                </div>
                <div class="mw-health-row">
                    <span class="mw-health-label">Location (background)</span>
                    <span class="mw-health-value" id="hBgLocPerm">—</span>
                </div>
                <div class="mw-health-row">
                    <span class="mw-health-label">GPS Hardware</span>
                    <span class="mw-health-value" id="hGpsEnabled">—</span>
                </div>
                <div class="mw-health-row">
                    <span class="mw-health-label">Network Location</span>
                    <span class="mw-health-value" id="hNetLoc">—</span>
                </div>
            </div>
        </div>
    </div>

    <!-- App Version -->
    <div class="col-md-6 col-lg-4 mb-3">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0">App Version</h5>
                <span id="versionStatusBadge" class="badge bg-secondary">Checking…</span>
            </div>
            <div class="card-body">
                <div class="mw-health-row">
                    <span class="mw-health-label">Installed</span>
                    <span class="mw-health-value" id="hAppVersion">—</span>
                </div>
                <div class="mw-health-row">
                    <span class="mw-health-label">Latest</span>
                    <span class="mw-health-value" id="hLatestVersion">—</span>
                </div>
                <div class="mw-health-row">
                    <span class="mw-health-label">Released</span>
                    <span class="mw-health-value" id="hReleaseDate">—</span>
                </div>
                <div class="mt-2" id="versionUpdateRow" style="display:none;">
                    <a id="versionApkLink" href="/crm/downloads/mowology-crew.apk"
                       class="btn btn-warning btn-sm w-100" download>
                        ⬆ Download Update
                    </a>
                </div>
                <div class="mt-2" id="versionCurrentRow" style="display:none;">
                    <span class="text-success small">✓ App is up to date</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Battery & Sync -->
    <div class="col-md-6 col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">Battery &amp; Sync</h5></div>
            <div class="card-body">
                <div class="mw-health-row">
                    <span class="mw-health-label">Battery Optimization</span>
                    <span class="mw-health-value" id="hBattery">—</span>
                </div>
                <div class="mw-health-row">
                    <span class="mw-health-label">Unsynced Points</span>
                    <span class="mw-health-value" id="hUnsynced">—</span>
                </div>
                <div class="mw-health-row">
                    <span class="mw-health-label">Unsynced Events</span>
                    <span class="mw-health-value" id="hUnsyncedEvents">—</span>
                </div>
                <div class="mw-health-row">
                    <span class="mw-health-label">Last Sync</span>
                    <span class="mw-health-value" id="hLastSync">—</span>
                </div>
                <div class="mw-health-row">
                    <span class="mw-health-label">Network</span>
                    <span class="mw-health-value" id="hNetwork">—</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- GPS Test Result -->
<div class="card mb-3" id="gpsTestCard" style="display:none;">
    <div class="card-header"><h5 class="card-title mb-0">GPS Test Result</h5></div>
    <div class="card-body" id="gpsTestResult">—</div>
</div>

<script>
(function() {
    'use strict';

    var isNative = !!(window.MwNative && window.MwNative.tracking);

    if (!isNative) {
        document.getElementById('nativeCheck').style.display = 'block';
    }

    /* ────────────────────────────────────────────────────
       SETUP WIZARD
    ──────────────────────────────────────────────────── */

    // State tracked across the check
    var setupState = {
        gps:     null,  // true | false | 'unknown'
        loc:     null,
        bgloc:   null,
        battery: null,
        network: null
    };

    // Render helpers
    function siSet(key, status, desc, actionHtml) {
        var item = document.getElementById('si-' + key);
        var icon = document.getElementById('si-' + key + '-icon');
        var descEl = document.getElementById('si-' + key + '-desc');
        var actionEl = document.getElementById('si-' + key + '-action');

        item.className = 'mw-setup-item';
        if (status === 'ok')   { item.classList.add('is-ok');    icon.textContent = '✅'; }
        if (status === 'warn') { item.classList.add('is-warn');  icon.textContent = '⚠️'; }
        if (status === 'error'){ item.classList.add('is-error'); icon.textContent = '❌'; }
        if (status === 'wait') { icon.textContent = '⏳'; }

        descEl.innerHTML = desc;
        actionEl.innerHTML = actionHtml || '';
    }

    function fixBtn(label, onclick) {
        return '<button class="btn btn-sm btn-warning" onclick="' + onclick + '">' + label + '</button>';
    }
    function manualBtn(label, onclick) {
        return '<button class="btn btn-sm btn-outline-secondary" onclick="' + onclick + '">' + label + '</button>';
    }

    function updateSummaryBadge() {
        var badge = document.getElementById('setupSummaryBadge');
        var errors = Object.values(setupState).filter(function(v) { return v === false; }).length;
        var unknowns = Object.values(setupState).filter(function(v) { return v === null; }).length;
        if (unknowns > 0) {
            badge.className = 'badge bg-secondary'; badge.textContent = 'Checking…';
        } else if (errors === 0) {
            badge.className = 'badge bg-success'; badge.textContent = '✓ All Good';
        } else {
            badge.className = 'badge bg-danger'; badge.textContent = errors + ' issue' + (errors > 1 ? 's' : '') + ' found';
        }
    }

    /* ── Individual checks ── */

    function checkNetwork() {
        var online = isNative ? window.MwNative.network.isOnline : navigator.onLine;
        setupState.network = online;
        if (online) {
            siSet('network', 'ok', 'Connected — GPS data will sync to the server automatically.');
        } else {
            siSet('network', 'warn',
                'No internet connection. GPS points will be saved on the device and synced when you reconnect.',
                manualBtn('Retry', 'runSetupCheck()')
            );
        }
        updateSummaryBadge();
    }

    function checkGPS(health) {
        // health is null in browser mode
        if (!isNative || !health) {
            setupState.gps = 'unknown';
            siSet('gps', 'warn',
                'Can\'t check GPS hardware in browser mode. Install the Android app for full diagnostics.',
                '<a href="/crm/downloads/mowology-crew.apk" class="btn btn-sm btn-warning" download>Download App</a>'
            );
            updateSummaryBadge();
            return;
        }
        if (health.gpsEnabled) {
            setupState.gps = true;
            siSet('gps', 'ok', 'GPS is turned on and ready.');
        } else {
            setupState.gps = false;
            siSet('gps', 'error',
                'GPS is turned off on your phone. Location tracking cannot work without it.',
                fixBtn('Turn On GPS', 'openLocationSettings()')
            );
        }
        updateSummaryBadge();
    }

    function checkLocPermission(health) {
        if (!isNative) {
            // Use browser Permissions API as fallback
            if (navigator.permissions && navigator.permissions.query) {
                navigator.permissions.query({ name: 'geolocation' }).then(function(result) {
                    var el = document.getElementById('hLocPerm');
                    if (result.state === 'granted') {
                        el.innerHTML = '<span class="badge bg-success">Granted</span>';
                        setupState.loc = true;
                        siSet('loc', 'ok', 'Location permission is granted in this browser.');
                    } else if (result.state === 'denied') {
                        el.innerHTML = '<span class="badge bg-danger">Denied</span>';
                        setupState.loc = false;
                        siSet('loc', 'error',
                            'Location permission is blocked in this browser. You\'ll need to reset it in browser settings.',
                            manualBtn('How to fix', 'showBrowserPermHelp()')
                        );
                    } else {
                        el.innerHTML = '<span class="badge bg-warning text-dark">Not asked</span>';
                        setupState.loc = 'unknown';
                        siSet('loc', 'warn', 'Location permission hasn\'t been requested yet. It will be asked when you start a job.');
                    }
                    updateSummaryBadge();
                });
            } else {
                setupState.loc = 'unknown';
                siSet('loc', 'warn', 'Can\'t check location permission in this browser.');
                updateSummaryBadge();
            }
            return;
        }

        // Native: check health.fgLocationGranted if available, else infer from GPS working
        var granted = health ? (health.fgLocationGranted !== false) : null;
        if (granted === true || (health && health.gpsEnabled && health.lastFixAccuracy >= 0)) {
            setupState.loc = true;
            siSet('loc', 'ok', 'Location permission is granted — the app can access your position.');
            document.getElementById('hLocPerm').innerHTML = '<span class="badge bg-success">Granted</span>';
        } else if (granted === false) {
            setupState.loc = false;
            siSet('loc', 'error',
                'Location permission was denied. GPS tracking and time clock won\'t work without it.',
                fixBtn('Grant Permission', 'requestLocationPermission()')
            );
            document.getElementById('hLocPerm').innerHTML = '<span class="badge bg-danger">Denied</span>';
        } else {
            setupState.loc = 'unknown';
            siSet('loc', 'warn', 'Location permission status is unknown. Try starting a job — Android will ask if needed.');
            document.getElementById('hLocPerm').innerHTML = '<span class="badge bg-warning text-dark">Unknown</span>';
        }
        updateSummaryBadge();
    }

    function checkBgLocPermission(health) {
        if (!isNative || !health) {
            setupState.bgloc = 'unknown';
            siSet('bgloc', 'warn',
                'Background location can only be checked inside the Android app.',
                null
            );
            document.getElementById('hBgLocPerm').innerHTML = '<span class="badge bg-secondary">N/A</span>';
            updateSummaryBadge();
            return;
        }

        var granted = health.bgLocationGranted;
        if (granted === true) {
            setupState.bgloc = true;
            siSet('bgloc', 'ok', 'Background location is allowed — GPS will track even when your screen is off.');
            document.getElementById('hBgLocPerm').innerHTML = '<span class="badge bg-success">Granted</span>';
        } else if (granted === false) {
            setupState.bgloc = false;
            siSet('bgloc', 'error',
                'Background location is not allowed. GPS will stop tracking when you lock your screen — clocking hours will be inaccurate.',
                fixBtn('Fix in Settings', 'openAppSettings()')
            );
            document.getElementById('hBgLocPerm').innerHTML = '<span class="badge bg-danger">Denied</span>';
        } else {
            // Older app builds don't report bgLocationGranted — infer "likely OK" if tracking is active
            setupState.bgloc = 'unknown';
            siSet('bgloc', 'warn',
                'Background location status unknown. If GPS stops when your screen locks, open App Settings → Permissions → Location → Allow all the time.',
                manualBtn('Open App Settings', 'openAppSettings()')
            );
            document.getElementById('hBgLocPerm').innerHTML = '<span class="badge bg-warning text-dark">Unknown</span>';
        }
        updateSummaryBadge();
    }

    function checkBattery(health) {
        if (!isNative || !health) {
            setupState.battery = 'unknown';
            siSet('battery', 'warn',
                'Battery optimization can only be checked inside the Android app.',
                null
            );
            updateSummaryBadge();
            return;
        }

        if (health.batteryOptimizationIgnored) {
            setupState.battery = true;
            siSet('battery', 'ok', 'Battery optimization is disabled for Mowology — GPS tracking will never be killed in the background.');
            document.getElementById('hBattery').innerHTML = '<span class="badge bg-success">Unrestricted</span>';
            document.getElementById('oemInlineCard').style.display = 'none';
        } else {
            setupState.battery = false;
            var oemText = health.oemBatteryInfo
                ? health.oemBatteryInfo
                : 'Go to Settings → Apps → Mowology → Battery → Unrestricted (or "Don\'t optimise").';
            siSet('battery', 'error',
                'Battery optimization is ON. Android will kill the GPS service in the background, causing missed time and gaps in route tracking.',
                fixBtn('Disable Battery Optimization', 'requestBatteryExemption()')
            );
            document.getElementById('hBattery').innerHTML = '<span class="badge bg-danger">Restricted</span>';
            // Show OEM instructions in the card footer
            document.getElementById('oemInlineText').textContent = oemText;
            document.getElementById('oemInlineCard').style.display = 'block';
        }
        updateSummaryBadge();
    }

    /* ── Main check runner ── */

    window.runSetupCheck = function() {
        // Reset all to pending
        ['gps','loc','bgloc','battery','network'].forEach(function(k) {
            setupState[k] = null;
            siSet(k, 'wait', 'Checking…', '');
        });
        document.getElementById('setupSummaryBadge').className = 'badge bg-secondary';
        document.getElementById('setupSummaryBadge').textContent = 'Checking…';

        checkNetwork();

        if (!isNative) {
            checkGPS(null);
            checkLocPermission(null);
            checkBgLocPermission(null);
            checkBattery(null);
            // Also update detail cards with browser-level data
            document.getElementById('hTrackingActive').textContent = 'N/A (browser)';
            document.getElementById('hNetwork').textContent = navigator.onLine ? 'Online' : 'Offline';
            return;
        }

        window.MwNative.tracking.getHealth().then(function(h) {
            checkGPS(h);
            checkLocPermission(h);
            checkBgLocPermission(h);
            checkBattery(h);

            // Update detail diagnostic cards
            setHealthValue('hTrackingActive', h.isTrackingActive,
                h.isTrackingActive ? 'badge bg-success' : 'badge bg-secondary',
                h.isTrackingActive ? 'Active' : 'Inactive');

            if (h.lastFixTime > 0) {
                var ago = Math.round((Date.now() - h.lastFixTime) / 1000);
                document.getElementById('hLastFix').textContent =
                    ago < 60 ? ago + 's ago' : Math.round(ago / 60) + 'min ago';
            } else {
                document.getElementById('hLastFix').textContent = 'No fix yet';
            }

            if (h.lastFixAccuracy >= 0) {
                var accClass = h.lastFixAccuracy <= 10 ? 'text-success' :
                               h.lastFixAccuracy <= 30 ? 'text-warning' : 'text-danger';
                document.getElementById('hAccuracy').innerHTML =
                    '<span class="' + accClass + '">' + Math.round(h.lastFixAccuracy) + 'm</span>';
            }

            document.getElementById('hProvider').textContent   = h.lastFixProvider || '—';
            document.getElementById('hActivity').textContent   = h.currentActivity || 'UNKNOWN';
            document.getElementById('hInterval').textContent   = h.currentIntervalMs ? (h.currentIntervalMs / 1000) + 's' : '—';
            document.getElementById('hGpsEnabled').innerHTML   = h.gpsEnabled   ? '<span class="badge bg-success">Enabled</span>'  : '<span class="badge bg-danger">Disabled</span>';
            document.getElementById('hNetLoc').innerHTML       = h.networkLocationEnabled ? '<span class="badge bg-success">Enabled</span>' : '<span class="badge bg-danger">Disabled</span>';
            document.getElementById('hUnsynced').textContent   = h.pointsUnsyncedCount  || '0';
            document.getElementById('hUnsyncedEvents').textContent = h.eventsUnsyncedCount || '0';

            if (h.lastSyncTime > 0) {
                var syncAgo = Math.round((Date.now() - h.lastSyncTime) / 1000);
                document.getElementById('hLastSync').textContent =
                    syncAgo < 60 ? syncAgo + 's ago' : Math.round(syncAgo / 60) + 'min ago';
            }

            document.getElementById('hNetwork').textContent =
                window.MwNative.network.isOnline ? 'Online' : 'Offline';
        });
    };

    /* ────────────────────────────────────────────────────
       FIX ACTIONS (called by setup wizard buttons)
    ──────────────────────────────────────────────────── */

    window.openLocationSettings = function() {
        // Ask native to open Android Location Settings
        if (window.MwNative && window.MwNative.tracking && typeof window.MwNative.tracking.openLocationSettings === 'function') {
            window.MwNative.tracking.openLocationSettings();
        } else if (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.NativeSettings) {
            window.Capacitor.Plugins.NativeSettings.openAndroid({ option: 'location_source_settings' });
        } else {
            alert('Go to your phone\'s Settings → Location → turn on "Use location"');
        }
        // Re-check after a short delay in case user flips the switch and comes back
        setTimeout(runSetupCheck, 3000);
    };

    window.requestLocationPermission = function() {
        if (window.MwNative && window.MwNative.geo) {
            // Trigger a getCurrentPosition which will prompt for permissions
            window.MwNative.geo.getCurrentPosition()
                .then(function() { setTimeout(runSetupCheck, 1000); })
                .catch(function() {
                    // Permission still denied — send user to app settings
                    openAppSettings();
                });
        } else if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function() { setTimeout(runSetupCheck, 500); },
                function() { showBrowserPermHelp(); }
            );
        }
    };

    window.openAppSettings = function() {
        // Open the Mowology app page in Android Settings
        if (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.NativeSettings) {
            window.Capacitor.Plugins.NativeSettings.openAndroid({ option: 'application_details_settings' });
        } else if (window.MwNative && window.MwNative.tracking && typeof window.MwNative.tracking.openAppSettings === 'function') {
            window.MwNative.tracking.openAppSettings();
        } else {
            alert('Go to Settings → Apps → Mowology → Permissions → Location → choose "Allow all the time".');
        }
        setTimeout(runSetupCheck, 4000);
    };

    window.requestBatteryExemption = function() {
        if (window.MwNative && window.MwNative.tracking) {
            window.MwNative.tracking.requestBatteryExemption().then(function() {
                setTimeout(runSetupCheck, 2000);
            });
        }
    };

    window.showBrowserPermHelp = function() {
        alert(
            'To re-enable location in your browser:\n\n' +
            'Chrome: tap the lock icon in the address bar → Site settings → Location → Allow\n\n' +
            'Or go to Chrome Settings → Site Settings → Location and remove the block for this site.'
        );
    };

    /* ────────────────────────────────────────────────────
       GPS TEST
    ──────────────────────────────────────────────────── */

    window.testGPS = function() {
        var card = document.getElementById('gpsTestCard');
        var result = document.getElementById('gpsTestResult');
        card.style.display = 'block';
        result.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Acquiring GPS fix…';

        var startTime = Date.now();

        if (window.MwNative && window.MwNative.geo) {
            window.MwNative.geo.getCurrentPosition().then(function(pos) {
                var elapsed = Date.now() - startTime;
                result.innerHTML =
                    '<strong class="text-success">✅ GPS Fix Acquired</strong><br>' +
                    'Lat: ' + pos.lat.toFixed(6) + ', Lng: ' + pos.lng.toFixed(6) + '<br>' +
                    'Accuracy: ' + Math.round(pos.accuracy) + 'm<br>' +
                    'Time to fix: ' + (elapsed / 1000).toFixed(1) + 's';
            }).catch(function(err) {
                result.innerHTML = '<strong class="text-danger">❌ GPS Failed:</strong> ' + err.message;
            });
        } else if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    var elapsed = Date.now() - startTime;
                    result.innerHTML =
                        '<strong class="text-success">✅ GPS Fix (Browser)</strong><br>' +
                        'Lat: ' + pos.coords.latitude.toFixed(6) + ', Lng: ' + pos.coords.longitude.toFixed(6) + '<br>' +
                        'Accuracy: ' + Math.round(pos.coords.accuracy) + 'm<br>' +
                        'Time to fix: ' + (elapsed / 1000).toFixed(1) + 's';
                },
                function(err) {
                    result.innerHTML = '<strong class="text-danger">❌ GPS Failed:</strong> ' + err.message;
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );
        } else {
            result.innerHTML = '<strong class="text-danger">Geolocation not supported in this browser.</strong>';
        }
    };

    /* ────────────────────────────────────────────────────
       FORCE SYNC
    ──────────────────────────────────────────────────── */

    window.forceSyncNow = function() {
        if (window.MwTimeClock && typeof window.MwTimeClock.restartTracking === 'function') {
            window.MwTimeClock.restartTracking();
        }
        alert('Sync triggered. Check "Unsynced Points" in the Battery & Sync card after a few seconds.');
        setTimeout(runSetupCheck, 3000);
    };

    /* ────────────────────────────────────────────────────
       APP VERSION CHECK
    ──────────────────────────────────────────────────── */

    (function checkAppVersion() {
        var installedVersion = (window.MwNative && window.MwNative.appVersion) || null;
        var hInstalled  = document.getElementById('hAppVersion');
        var hLatest     = document.getElementById('hLatestVersion');
        var hReleaseDate = document.getElementById('hReleaseDate');
        var badge       = document.getElementById('versionStatusBadge');
        var updateRow   = document.getElementById('versionUpdateRow');
        var currentRow  = document.getElementById('versionCurrentRow');
        var updateAlert = document.getElementById('updateAlert');

        hInstalled.innerHTML = installedVersion
            ? installedVersion
            : '<span class="text-muted">Unknown (browser)</span>';

        function semverNewer(a, b) {
            var pa = (a || '0').split('.').map(Number);
            var pb = (b || '0').split('.').map(Number);
            for (var i = 0; i < 3; i++) {
                if ((pb[i] || 0) > (pa[i] || 0)) return true;
                if ((pb[i] || 0) < (pa[i] || 0)) return false;
            }
            return false;
        }

        fetch('/crm/api/app-version.php', { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(data) {
                if (!data) { badge.textContent = 'Error'; return; }

                hLatest.textContent      = data.version;
                hReleaseDate.textContent = data.released || '—';

                if (data.apk_url) {
                    document.getElementById('versionApkLink').href = data.apk_url;
                    document.getElementById('updateApkLink').href  = data.apk_url;
                }

                if (installedVersion && semverNewer(installedVersion, data.version)) {
                    badge.className = 'badge bg-warning text-dark';
                    badge.textContent = 'Update Available';
                    updateRow.style.display = 'block';
                    document.getElementById('updateVersion').textContent = data.version;
                    document.getElementById('updateNotes').textContent   = data.release_notes || '';
                    updateAlert.style.cssText = 'display:flex!important;';
                } else if (installedVersion) {
                    badge.className = 'badge bg-success';
                    badge.textContent = 'Up to date';
                    currentRow.style.display = 'block';
                } else {
                    badge.className = 'badge bg-secondary';
                    badge.textContent = 'Browser mode';
                }
            })
            .catch(function() {
                badge.className = 'badge bg-secondary';
                badge.textContent = 'Offline';
            });
    })();

    /* ────────────────────────────────────────────────────
       HELPERS
    ──────────────────────────────────────────────────── */

    function setHealthValue(id, value, badgeClass, text) {
        document.getElementById(id).innerHTML =
            '<span class="' + badgeClass + '">' + text + '</span>';
    }

    /* ── Bootstrap ── */
    runSetupCheck();

})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
