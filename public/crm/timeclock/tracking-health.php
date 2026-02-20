<?php
/**
 * Tracking Health — Diagnostics page for crew GPS tracking.
 * Shows: permission status, last fix, accuracy, battery optimization,
 *        device info, unsynced points, and OEM-specific guidance.
 *
 * Accessible to all logged-in users (crew see their own, admin sees all).
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
    <div class="col-12">
        <h1 class="h3 mb-0">GPS Tracking Health</h1>
        <p class="text-muted mb-0">Diagnostics for your crew GPS tracking system</p>
    </div>
</div>

<!-- Native App Detection -->
<div id="nativeCheck" class="alert alert-warning" style="display:none;">
    <strong>Browser Mode:</strong> You're viewing this in a web browser. Full diagnostics require the Mowology Crew Android app.
    <a href="/crm/downloads/mowology-crew.apk" class="alert-link">Download the app</a>
</div>

<!-- Health Cards Grid -->
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

    <!-- Battery & Sync -->
    <div class="col-md-6 col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">Battery & Sync</h5></div>
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

<!-- OEM Battery Instructions -->
<div class="card mb-3" id="oemCard" style="display:none;">
    <div class="card-header bg-warning text-dark">
        <h5 class="card-title mb-0">Battery Optimization Setup Required</h5>
    </div>
    <div class="card-body">
        <p class="mb-2">Your phone's battery optimization may kill GPS tracking in the background. Follow these steps:</p>
        <p class="mb-3 fw-bold" id="oemInstructions">—</p>
        <button class="btn btn-warning" id="btnBatteryExempt" onclick="requestBatteryExemption()">
            Request Battery Exemption
        </button>
    </div>
</div>

<!-- Actions -->
<div class="card mb-3">
    <div class="card-header"><h5 class="card-title mb-0">Actions</h5></div>
    <div class="card-body">
        <button class="btn btn-outline-primary me-2" onclick="refreshHealth()">
            <i data-feather="refresh-cw" class="me-1"></i>Refresh Diagnostics
        </button>
        <button class="btn btn-outline-success me-2" onclick="testGPS()">
            <i data-feather="crosshair" class="me-1"></i>Test GPS Fix
        </button>
        <button class="btn btn-outline-info" onclick="forceSyncNow()">
            <i data-feather="upload-cloud" class="me-1"></i>Force Sync Now
        </button>
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

    // Show browser warning if not native
    if (!isNative) {
        document.getElementById('nativeCheck').style.display = 'block';
    }

    // Also check browser geolocation permission
    checkBrowserPermissions();
    refreshHealth();

    function checkBrowserPermissions() {
        if (navigator.permissions && navigator.permissions.query) {
            navigator.permissions.query({ name: 'geolocation' }).then(function(result) {
                var el = document.getElementById('hLocPerm');
                if (result.state === 'granted') {
                    el.innerHTML = '<span class="badge bg-success">Granted</span>';
                } else if (result.state === 'denied') {
                    el.innerHTML = '<span class="badge bg-danger">Denied</span>';
                } else {
                    el.innerHTML = '<span class="badge bg-warning text-dark">Not asked</span>';
                }
            });
        }
    }

    window.refreshHealth = function() {
        if (!isNative) {
            // Browser-only diagnostics
            document.getElementById('hTrackingActive').textContent = 'N/A (browser)';
            document.getElementById('hNetwork').textContent = navigator.onLine ? 'Online' : 'Offline';
            return;
        }

        window.MwNative.tracking.getHealth().then(function(h) {
            // GPS Status
            setHealthValue('hTrackingActive', h.isTrackingActive,
                h.isTrackingActive ? 'badge bg-success' : 'badge bg-secondary',
                h.isTrackingActive ? 'Active' : 'Inactive');

            if (h.lastFixTime > 0) {
                var ago = Math.round((Date.now() - h.lastFixTime) / 1000);
                var agoText = ago < 60 ? ago + 's ago' : Math.round(ago / 60) + 'min ago';
                document.getElementById('hLastFix').textContent = agoText;
            } else {
                document.getElementById('hLastFix').textContent = 'No fix yet';
            }

            if (h.lastFixAccuracy >= 0) {
                var accClass = h.lastFixAccuracy <= 10 ? 'text-success' :
                               h.lastFixAccuracy <= 30 ? 'text-warning' : 'text-danger';
                document.getElementById('hAccuracy').innerHTML =
                    '<span class="' + accClass + '">' + Math.round(h.lastFixAccuracy) + 'm</span>';
            }

            document.getElementById('hProvider').textContent = h.lastFixProvider || '—';
            document.getElementById('hActivity').textContent = h.currentActivity || 'UNKNOWN';
            document.getElementById('hInterval').textContent =
                h.currentIntervalMs ? (h.currentIntervalMs / 1000) + 's' : '—';

            // Permissions
            setHealthBadge('hGpsEnabled', h.gpsEnabled);
            setHealthBadge('hNetLoc', h.networkLocationEnabled);

            // Battery
            if (h.batteryOptimizationIgnored) {
                document.getElementById('hBattery').innerHTML =
                    '<span class="badge bg-success">Unrestricted</span>';
            } else {
                document.getElementById('hBattery').innerHTML =
                    '<span class="badge bg-danger">Restricted</span>';
                // Show OEM card
                document.getElementById('oemCard').style.display = 'block';
                if (h.oemBatteryInfo) {
                    document.getElementById('oemInstructions').textContent = h.oemBatteryInfo;
                }
            }

            // Sync
            document.getElementById('hUnsynced').textContent = h.pointsUnsyncedCount || '0';
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

    window.testGPS = function() {
        var card = document.getElementById('gpsTestCard');
        var result = document.getElementById('gpsTestResult');
        card.style.display = 'block';
        result.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Acquiring GPS fix...';

        var startTime = Date.now();

        if (window.MwNative && window.MwNative.geo) {
            window.MwNative.geo.getCurrentPosition().then(function(pos) {
                var elapsed = Date.now() - startTime;
                result.innerHTML =
                    '<strong class="text-success">GPS Fix Acquired</strong><br>' +
                    'Lat: ' + pos.lat.toFixed(6) + ', Lng: ' + pos.lng.toFixed(6) + '<br>' +
                    'Accuracy: ' + Math.round(pos.accuracy) + 'm<br>' +
                    'Time to fix: ' + (elapsed / 1000).toFixed(1) + 's';
            }).catch(function(err) {
                result.innerHTML = '<strong class="text-danger">GPS Failed:</strong> ' + err.message;
            });
        } else if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    var elapsed = Date.now() - startTime;
                    result.innerHTML =
                        '<strong class="text-success">GPS Fix (Browser)</strong><br>' +
                        'Lat: ' + pos.coords.latitude.toFixed(6) +
                        ', Lng: ' + pos.coords.longitude.toFixed(6) + '<br>' +
                        'Accuracy: ' + Math.round(pos.coords.accuracy) + 'm<br>' +
                        'Time to fix: ' + (elapsed / 1000).toFixed(1) + 's';
                },
                function(err) {
                    result.innerHTML = '<strong class="text-danger">GPS Failed:</strong> ' + err.message;
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );
        } else {
            result.innerHTML = '<strong class="text-danger">Geolocation not supported</strong>';
        }
    };

    window.requestBatteryExemption = function() {
        if (window.MwNative && window.MwNative.tracking) {
            window.MwNative.tracking.requestBatteryExemption().then(function() {
                // Refresh after a delay to check if it was granted
                setTimeout(refreshHealth, 2000);
            });
        }
    };

    window.forceSyncNow = function() {
        // Trigger server sync of any queued GPS points (browser localStorage queue)
        if (window.MwTimeClock && typeof window.MwTimeClock.restartTracking === 'function') {
            window.MwTimeClock.restartTracking();
        }
        alert('Sync triggered. Check "Unsynced Points" count after a few seconds.');
        setTimeout(refreshHealth, 3000);
    };

    function setHealthValue(id, value, badgeClass, text) {
        document.getElementById(id).innerHTML =
            '<span class="' + badgeClass + '">' + text + '</span>';
    }

    function setHealthBadge(id, enabled) {
        document.getElementById(id).innerHTML = enabled
            ? '<span class="badge bg-success">Enabled</span>'
            : '<span class="badge bg-danger">Disabled</span>';
    }
})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
