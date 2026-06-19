<?php
/**
 * My Schedule — Employee Daily Schedule + Job Timer
 * Shows today's assigned jobs with start/stop timer + GPS proximity detection.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('schedule.view');

$db = getDB();

// Get current date (or from query param)
$viewDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $viewDate)) {
    $viewDate = date('Y-m-d');
}

$isToday = ($viewDate === date('Y-m-d'));
$prevDate = date('Y-m-d', strtotime($viewDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($viewDate . ' +1 day'));

// Get the user's jobs for this date
$jobs = getUserJobsForDate($user['id'], $viewDate);

// Get active clock entry
$activeClock = getActiveClockEntry($user['id']);

// Get active job timer (if any)
$activeJobTimer = getActiveJobTimer($user['id']);

// Daily stats
$completedCount = 0;
$totalEstimatedMin = 0;
foreach ($jobs as $j) {
    if ($j['status'] === 'completed') $completedCount++;
    $totalEstimatedMin += (int)($j['estimated_duration_minutes'] ?? 0);
}

// Service type colors
$serviceColors = [
    'landscaping' => '#2D8659',
    'lawn_care' => '#7FD858',
    'snow_removal' => '#3B82F6',
    'hedge_trimming' => '#8B5CF6',
    'garden_maintenance' => '#F59E0B',
    'seasonal_cleanup' => '#EC4899',
];

// GPS proximity setting
$gpsProximityMeters = (int)getTimeClockSetting('gps_proximity_meters', '150');

// Native-app enforcement — non-admin, non-truck crew must use the Android APK
$trackRow = $db->prepare("SELECT IFNULL(device_type,'personal') AS device_type, COALESCE(location_tracking_enabled,0) AS tracking_on FROM users WHERE id = ?");
$trackRow->execute([$user['id']]);
$trackRow = $trackRow->fetch(PDO::FETCH_ASSOC);
$deviceType       = $trackRow['device_type'] ?? 'personal';
$trackingEnabled  = (bool)($trackRow['tracking_on'] ?? false);
// Gate fires for: tracking-enabled crew on any device type.
// Exempt: admin role only. Truck tablets must use the app too — Trackimo is the backup, not the primary.
$needsNativeApp = $trackingEnabled
               && !in_array($user['role'], ['admin']);

// All jobs for today (any crew) — used for GPS proximity so ANY crew near a property can clock in
$allJobsToday = $isToday ? getAllJobsForDate($viewDate) : [];
// Filter out jobs already in the user's assigned list and completed ones
$proximityJobs = [];
$assignedJobIds = array_column($jobs, 'id');
foreach ($allJobsToday as $aj) {
    if (!in_array($aj['id'], $assignedJobIds) && !empty($aj['property_lat']) && !empty($aj['property_lng'])) {
        $proximityJobs[] = $aj;
    }
}

$pageTitle = 'My Schedule';
$activePage = 'timeclock';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<!-- ═══ Mobile/Tablet: In-Page Clock Section (no JS dependency) ═══ -->
<div class="mw-mobile-clock" id="mobileClockSection">
    <?php if ($activeClock): ?>
        <div class="mw-mobile-clock-active">
            <div class="mw-mobile-clock-status">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                <span class="mw-mobile-clock-timer" id="mobileClockTimer">
                    <?php
                    // Use MySQL-calculated elapsed (avoids PHP/MySQL timezone mismatch)
                    $elapsed = max(0, (int)$activeClock['elapsed_seconds']);
                    $h = floor($elapsed / 3600);
                    $m = floor(($elapsed % 3600) / 60);
                    $s = $elapsed % 60;
                    echo sprintf('%02d:%02d:%02d', $h, $m, $s);
                    ?>
                </span>
                <span class="mw-mobile-clock-label">Clocked In</span>
            </div>
            <button class="mw-mobile-clock-btn mw-mobile-clock-out" id="mobileClockOut">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect></svg>
                Clock Out
            </button>
        </div>
    <?php else: ?>
        <button class="mw-mobile-clock-btn mw-mobile-clock-in" id="mobileClockIn">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polygon points="10 8 16 12 10 16 10 8"></polygon></svg>
            Clock In
        </button>
    <?php endif; ?>
</div>

<!-- Schedule Header -->
<div class="mw-schedule-header">
    <div>
        <h1 class="h3 mb-1 mw-schedule-title-desktop">My Schedule</h1>
        <div class="mw-schedule-date-nav">
            <a href="?date=<?php echo $prevDate; ?>" class="btn btn-sm btn-outline-secondary" aria-label="Previous day">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
            </a>
            <span class="mw-schedule-date-display">
                <?php echo date('l, M j, Y', strtotime($viewDate)); ?>
            </span>
            <a href="?date=<?php echo $nextDate; ?>" class="btn btn-sm btn-outline-secondary" aria-label="Next day">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <?php if (!$isToday): ?>
                <a href="?date=<?php echo date('Y-m-d'); ?>" class="mw-schedule-today-btn">Today</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex align-items-center gap-3 mw-schedule-status-desktop" style="gap: 12px;">
        <!-- GPS Status -->
        <div class="mw-gps-status mw-gps-inactive" id="gpsStatus">
            <span class="mw-gps-dot"></span>
            <span id="gpsStatusText">GPS off</span>
        </div>

        <!-- Clock Status -->
        <?php if ($activeClock): ?>
            <span class="badge badge-success" style="font-size: 0.8rem; padding: 6px 12px;">
                <i data-feather="clock" style="width:12px;height:12px;"></i>
                Clocked In
            </span>
        <?php else: ?>
            <span class="badge badge-secondary" style="font-size: 0.8rem; padding: 6px 12px;">Not Clocked In</span>
        <?php endif; ?>
    </div>
</div>

<!-- Day Stats -->
<div class="mw-schedule-stats mb-4">
    <div class="mw-schedule-stat">
        <div class="mw-schedule-stat-value" id="mwStatJobs"><?php echo count($jobs); ?></div>
        <div class="mw-schedule-stat-label">Jobs Today</div>
    </div>
    <div class="mw-schedule-stat">
        <div class="mw-schedule-stat-value"><?php echo $completedCount; ?></div>
        <div class="mw-schedule-stat-label">Completed</div>
    </div>
    <div class="mw-schedule-stat">
        <div class="mw-schedule-stat-value" id="mwStatEst"><?php echo formatMinutesAsHours($totalEstimatedMin); ?></div>
        <div class="mw-schedule-stat-label">Est. Total</div>
    </div>
</div>

<?php if (!empty($jobs) && count($jobs) > 1): ?>
<!-- Route From Here — reorders the job list by straight-line distance from current GPS location -->
<div class="mw-route-bar">
    <button type="button" class="mw-route-btn" id="mwRouteBtn">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
        <span class="mw-route-btn-label">Route From Here</span>
    </button>
    <div class="mw-route-indicator" id="mwRouteIndicator" style="display:none;">
        <span class="mw-route-indicator-text">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            Sorted by distance from your location
        </span>
        <a href="#" class="mw-route-reset" id="mwRouteReset">Reset to scheduled order</a>
    </div>
</div>
<?php endif; ?>

<!-- Job Timeline -->
<div class="mw-job-timeline" id="jobTimeline">
    <?php if (empty($jobs)): ?>
        <div class="mw-schedule-empty">
            <i data-feather="calendar"></i>
            <h3>No jobs scheduled</h3>
            <p>You have no jobs assigned for <?php echo date('l, M j', strtotime($viewDate)); ?>.</p>
        </div>
    <?php else: ?>
        <?php $__routeSeq = 0; ?>
        <?php foreach ($jobs as $job):
            $statusClass = '';
            if ($job['status'] === 'in_progress') $statusClass = 'mw-tc-in-progress';
            if ($job['status'] === 'completed') $statusClass = 'mw-tc-completed';

            $serviceColor = $serviceColors[$job['service_type']] ?? '#888';
            $serviceLabel = ucwords(str_replace('_', ' ', $job['service_type'] ?? 'General'));

            $timeStart = $job['scheduled_time_start'] ? date('g:i A', strtotime($job['scheduled_time_start'])) : '';
            $timeEnd = $job['scheduled_time_end'] ? date('g:i A', strtotime($job['scheduled_time_end'])) : '';

            $hasActiveTimer = !empty($job['active_timer_id']);
        ?>
        <div class="mw-tc-card <?php echo $statusClass; ?>"
             id="jobCard-<?php echo (int)$job['id']; ?>"
             data-job-id="<?php echo (int)$job['id']; ?>"
             data-seq="<?php echo $__routeSeq++; ?>"
             data-est-min="<?php echo (int)($job['estimated_duration_minutes'] ?? 60); ?>"
             data-lat="<?php echo htmlspecialchars($job['property_lat'] ?? ''); ?>"
             data-lng="<?php echo htmlspecialchars($job['property_lng'] ?? ''); ?>"
             data-status="<?php echo htmlspecialchars($job['status']); ?>">

            <div class="mw-tc-card-header">
                <span class="mw-tc-card-time">
                    <?php echo $timeStart; ?><?php echo $timeEnd ? ' - ' . $timeEnd : ''; ?>
                </span>
                <span class="mw-tc-card-service" style="background: <?php echo $serviceColor; ?>;">
                    <?php echo htmlspecialchars($serviceLabel); ?>
                </span>
            </div>

            <div class="mw-tc-card-title">
                <?php echo htmlspecialchars($job['title'] ?? $job['job_number']); ?>
            </div>

            <div class="mw-tc-card-client">
                <?php echo htmlspecialchars($job['company_name'] ?? ''); ?>
            </div>

            <div class="mw-tc-card-address">
                <i data-feather="map-pin"></i>
                <?php echo htmlspecialchars($job['property_address'] ?? 'No address'); ?>
                <?php if ($job['property_city']): ?>
                    , <?php echo htmlspecialchars($job['property_city']); ?>
                <?php endif; ?>
            </div>

            <div class="mw-tc-card-footer">
                <div class="mw-tc-card-duration">
                    <i data-feather="clock"></i>
                    <?php echo (int)($job['estimated_duration_minutes'] ?? 60); ?> min estimated
                </div>

                <?php if ($job['status'] === 'scheduled'): ?>
                    <button class="mw-tc-btn mw-tc-btn-start"
                            onclick="startJob(<?php echo (int)$job['id']; ?>)">
                        <i data-feather="play"></i> Start Job
                    </button>

                <?php elseif ($job['status'] === 'in_progress' && $hasActiveTimer): ?>
                    <div class="mw-tc-active-timer" id="jobTimer-<?php echo (int)$job['id']; ?>"
                         data-elapsed="<?php echo max(0, (int)$job['timer_elapsed_seconds']); ?>">
                        <i data-feather="activity"></i>
                        <span class="mw-tc-timer-display">00:00:00</span>
                    </div>
                    <div style="display:flex; gap: 6px;">
                        <button class="mw-tc-btn mw-tc-btn-pause"
                                onclick="pauseJob(<?php echo (int)$job['id']; ?>)">
                            <i data-feather="pause"></i> Pause
                        </button>
                        <button class="mw-tc-btn mw-tc-btn-stop"
                                onclick="stopJob(<?php echo (int)$job['id']; ?>)">
                            <i data-feather="check-circle"></i> Complete
                        </button>
                    </div>

                <?php elseif ($job['status'] === 'in_progress' && !$hasActiveTimer): ?>
                    <!-- In progress but timer paused — allow restart or complete -->
                    <span class="badge badge-warning" style="font-size: 0.8rem;">Timer Paused</span>
                    <div style="display:flex; gap: 6px;">
                        <button class="mw-tc-btn mw-tc-btn-start"
                                onclick="startJob(<?php echo (int)$job['id']; ?>)">
                            <i data-feather="play"></i> Resume
                        </button>
                        <button class="mw-tc-btn mw-tc-btn-stop"
                                onclick="stopJob(<?php echo (int)$job['id']; ?>)">
                            <i data-feather="check-circle"></i> Complete
                        </button>
                    </div>

                <?php elseif ($job['status'] === 'completed'): ?>
                    <span class="mw-tc-done-badge">
                        <i data-feather="check-circle"></i> Completed
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($job['status'] !== 'completed'): ?>
            <button type="button" class="mw-tc-skip-btn" onclick="skipJob(<?php echo (int)$job['id']; ?>)">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 4 15 12 5 20 5 4"/><line x1="19" y1="5" x2="19" y2="19"/></svg>
                Skip this job
            </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Hidden proximity data: jobs from OTHER crews that this user can also clock into if nearby -->
<?php foreach ($proximityJobs as $pj): ?>
<div class="mw-proximity-job" style="display:none;"
     data-job-id="<?php echo (int)$pj['id']; ?>"
     data-lat="<?php echo htmlspecialchars($pj['property_lat']); ?>"
     data-lng="<?php echo htmlspecialchars($pj['property_lng']); ?>"
     data-title="<?php echo htmlspecialchars($pj['title'] ?? $pj['job_number']); ?>"
     data-address="<?php echo htmlspecialchars($pj['property_address'] ?? ''); ?>"
     data-status="<?php echo htmlspecialchars($pj['status']); ?>">
</div>
<?php endforeach; ?>

<!-- GPS Proximity Alert (hidden by default, shown by JS) -->
<div class="mw-gps-alert" id="gpsAlert" style="display:none;">
    <span class="mw-gps-alert-text" id="gpsAlertText"></span>
    <div class="mw-gps-alert-actions">
        <button class="mw-gps-alert-yes" id="gpsAlertYes">Start Timer</button>
        <button class="mw-gps-alert-no" id="gpsAlertNo">Dismiss</button>
    </div>
</div>

<script>
/**
 * My Schedule — Job Timer + GPS Proximity Logic
 */
(function() {
    'use strict';

    var GPS_PROXIMITY_METERS = <?php echo $gpsProximityMeters; ?>;
    var watchId = null;
    var currentLat = null;
    var currentLng = null;
    var alertedJobs = {}; // Track which jobs we've already alerted for
    var jobTimerIntervals = {};

    // ── Initialize ──
    initGPS();
    initJobTimers();

    // ── GPS ──

    function initGPS() {
        // ── Native Capacitor: background-aware GPS for proximity ──
        if (window.MwNative && window.MwNative.geo) {
            window.MwNative.geo.startBackgroundTracking(function(pos, error) {
                if (error) {
                    setGPSStatus('error', error.code || 'GPS error');
                    return;
                }
                currentLat = pos.lat;
                currentLng = pos.lng;
                setGPSStatus('active', 'GPS active');
                checkProximity();
            });
            return;
        }

        // ── Browser fallback ──
        if (!navigator.geolocation) {
            setGPSStatus('error', 'Not supported');
            return;
        }

        watchId = navigator.geolocation.watchPosition(
            function(pos) {
                currentLat = pos.coords.latitude;
                currentLng = pos.coords.longitude;
                setGPSStatus('active', 'GPS active');
                checkProximity();
            },
            function(err) {
                if (err.code === 1) {
                    setGPSStatus('error', 'GPS denied');
                } else {
                    setGPSStatus('inactive', 'GPS unavailable');
                }
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }
        );
    }

    function setGPSStatus(state, text) {
        var el = document.getElementById('gpsStatus');
        var textEl = document.getElementById('gpsStatusText');
        if (!el) return;
        el.className = 'mw-gps-status mw-gps-' + state;
        if (textEl) textEl.textContent = text;
    }

    function checkProximity() {
        if (currentLat === null || currentLng === null) return;

        // Check assigned jobs (visible cards)
        var cards = document.querySelectorAll('.mw-tc-card[data-status="scheduled"]');
        cards.forEach(function(card) {
            var jobId = card.getAttribute('data-job-id');
            var lat = parseFloat(card.getAttribute('data-lat'));
            var lng = parseFloat(card.getAttribute('data-lng'));

            if (isNaN(lat) || isNaN(lng) || alertedJobs[jobId]) return;

            var distance = haversineDistance(currentLat, currentLng, lat, lng);
            if (distance <= GPS_PROXIMITY_METERS) {
                alertedJobs[jobId] = true;
                showGPSAlert(jobId, card.querySelector('.mw-tc-card-title').textContent.trim(), false);
            }
        });

        // Check OTHER crews' jobs (hidden proximity data) — any crew near a property can clock in
        var proximityJobs = document.querySelectorAll('.mw-proximity-job[data-status="scheduled"]');
        proximityJobs.forEach(function(el) {
            var jobId = el.getAttribute('data-job-id');
            var lat = parseFloat(el.getAttribute('data-lat'));
            var lng = parseFloat(el.getAttribute('data-lng'));

            if (isNaN(lat) || isNaN(lng) || alertedJobs[jobId]) return;

            var distance = haversineDistance(currentLat, currentLng, lat, lng);
            if (distance <= GPS_PROXIMITY_METERS) {
                alertedJobs[jobId] = true;
                var title = el.getAttribute('data-title');
                var address = el.getAttribute('data-address');
                showGPSAlert(jobId, title + (address ? ' (' + address + ')' : ''), true);
            }
        });
    }

    function haversineDistance(lat1, lon1, lat2, lon2) {
        var R = 6371000; // Earth radius in meters
        var dLat = toRad(lat2 - lat1);
        var dLon = toRad(lon2 - lon1);
        var a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
                Math.sin(dLon/2) * Math.sin(dLon/2);
        var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return R * c;
    }

    function toRad(deg) { return deg * Math.PI / 180; }

    function showGPSAlert(jobId, jobTitle, isOtherCrew) {
        // Native notification (works even when app is backgrounded)
        var notifTitle = isOtherCrew ? 'Near Job Site (Other Crew)' : 'Near Job Site';
        var notifMsg = 'You\'re near "' + jobTitle + '". Open app to start timer.';
        if (window.MwNative && window.MwNative.notifications) {
            window.MwNative.notifications.notify(notifTitle, notifMsg, parseInt(jobId, 10));
        }

        var alert = document.getElementById('gpsAlert');
        var text = document.getElementById('gpsAlertText');
        var prefix = isOtherCrew ? 'Nearby job (not assigned to you): ' : '';
        text.textContent = prefix + 'You\'re near "' + jobTitle + '". Start timer?';
        alert.style.display = 'flex';

        document.getElementById('gpsAlertYes').onclick = function() {
            alert.style.display = 'none';
            startJob(parseInt(jobId), true);
        };

        document.getElementById('gpsAlertNo').onclick = function() {
            alert.style.display = 'none';
        };

        // Auto-dismiss after 30 seconds
        setTimeout(function() { alert.style.display = 'none'; }, 30000);
    }

    // ── Job Timer Displays ──

    function initJobTimers() {
        var timers = document.querySelectorAll('.mw-tc-active-timer');
        timers.forEach(function(timer) {
            var initialElapsed = parseInt(timer.getAttribute('data-elapsed'), 10);
            if (isNaN(initialElapsed)) return;
            var display = timer.querySelector('.mw-tc-timer-display');
            var jobId = timer.id.replace('jobTimer-', '');
            var tickStart = Math.floor(Date.now() / 1000);

            function updateDisplay() {
                var elapsed = initialElapsed + (Math.floor(Date.now() / 1000) - tickStart);
                display.textContent = formatSec(elapsed);
            }

            updateDisplay();
            jobTimerIntervals[jobId] = setInterval(updateDisplay, 1000);
        });
    }

    function formatSec(s) {
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        return pad(h) + ':' + pad(m) + ':' + pad(sec);
    }

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    // ── API Calls ──

    window.startJob = function(jobId, autoStarted) {
        var body = { action: 'start', visit_id: jobId, auto_started: !!autoStarted };
        if (currentLat !== null) { body.lat = currentLat; body.lng = currentLng; }

        fetch('/crm/api/job-timer.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showToast('Timer started for job', 'success');
                // Refresh page to update UI
                location.reload();
            } else {
                showToast(data.error || 'Failed to start timer', 'error');
            }
        })
        .catch(function() { showToast('Network error', 'error'); });
    };

    window.stopJob = function(jobId) {
        if (!confirm('Complete this job?')) return;

        var body = { action: 'stop', visit_id: jobId, complete_visit: true };
        if (currentLat !== null) { body.lat = currentLat; body.lng = currentLng; }

        fetch('/crm/api/job-timer.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showToast('Job completed — ' + data.duration_formatted, 'success');
                if (jobTimerIntervals[jobId]) clearInterval(jobTimerIntervals[jobId]);
                location.reload();
            } else {
                showToast(data.error || 'Failed to stop timer', 'error');
            }
        })
        .catch(function() { showToast('Network error', 'error'); });
    };

    window.pauseJob = function(jobId) {
        var body = { action: 'pause', visit_id: jobId };
        if (currentLat !== null) { body.lat = currentLat; body.lng = currentLng; }

        fetch('/crm/api/job-timer.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showToast('Timer paused', 'info');
                if (jobTimerIntervals[jobId]) clearInterval(jobTimerIntervals[jobId]);
                location.reload();
            } else {
                showToast(data.error || 'Failed to pause timer', 'error');
            }
        })
        .catch(function() { showToast('Network error', 'error'); });
    };

    // ── Toast ──
    function showToast(message, type) {
        var toast = document.createElement('div');
        toast.className = 'mw-clock-toast mw-clock-toast-' + (type || 'info');
        toast.textContent = message;
        document.body.appendChild(toast);
        toast.offsetHeight;
        toast.classList.add('mw-clock-toast-visible');
        setTimeout(function() {
            toast.classList.remove('mw-clock-toast-visible');
            setTimeout(function() { toast.remove(); }, 300);
        }, 3000);
    }

})();
</script>

<!-- ═══ Route From Here — GPS distance sort (Capacitor + browser) ═══ -->
<script>
(function() {
    'use strict';

    var btn = document.getElementById('mwRouteBtn');
    if (!btn) return; // button only rendered when 2+ jobs exist

    var timeline  = document.getElementById('jobTimeline');
    var label     = btn.querySelector('.mw-route-btn-label');
    var indicator = document.getElementById('mwRouteIndicator');
    var resetLink = document.getElementById('mwRouteReset');

    var ROUTE_DATE  = '<?php echo htmlspecialchars($viewDate, ENT_QUOTES); ?>';
    var STORAGE_KEY = 'route_order_' + ROUTE_DATE;
    var DEFAULT_LABEL = 'Route From Here';

    function haversineKm(lat1, lon1, lat2, lon2) {
        var R = 6371; // km
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLon = (lon2 - lon1) * Math.PI / 180;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLon / 2) * Math.sin(dLon / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function getCards() {
        return Array.prototype.slice.call(timeline.querySelectorAll('.mw-tc-card'));
    }

    function bySeq(a, b) {
        return (parseInt(a.getAttribute('data-seq'), 10) || 0) -
               (parseInt(b.getAttribute('data-seq'), 10) || 0);
    }

    // Re-append cards in the given id order; any card not listed (no coords /
    // newly added) goes to the bottom in its original scheduled order.
    function applyOrder(idList) {
        var byId = {};
        getCards().forEach(function(c) { byId[c.getAttribute('data-job-id')] = c; });
        idList.forEach(function(id) {
            if (byId[id]) { timeline.appendChild(byId[id]); delete byId[id]; }
        });
        Object.keys(byId).map(function(id) { return byId[id]; })
            .sort(bySeq)
            .forEach(function(c) { timeline.appendChild(c); });
    }

    function sortByDistance(lat, lng) {
        var withCoords = [], without = [];
        getCards().forEach(function(c) {
            var clat = parseFloat(c.getAttribute('data-lat'));
            var clng = parseFloat(c.getAttribute('data-lng'));
            if (isNaN(clat) || isNaN(clng)) { without.push(c); return; }
            c._routeDist = haversineKm(lat, lng, clat, clng);
            withCoords.push(c);
        });

        withCoords.sort(function(a, b) { return a._routeDist - b._routeDist; });
        without.sort(bySeq);

        withCoords.concat(without).forEach(function(c) { timeline.appendChild(c); });

        var order = withCoords.map(function(c) { return c.getAttribute('data-job-id'); });
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(order)); } catch (e) {}

        showIndicator();
    }

    function showIndicator() { if (indicator) indicator.style.display = 'flex'; }
    function hideIndicator() { if (indicator) indicator.style.display = 'none'; }

    function setBtn(state) {
        btn.classList.remove('mw-route-btn-error');
        if (state === 'loading') {
            btn.disabled = true;
            label.textContent = 'Getting location…';
        } else if (state === 'denied') {
            // Permission refused — surface a clear, distinct message (Android prompt declined).
            btn.disabled = false;
            btn.classList.add('mw-route-btn-error');
            label.textContent = 'Location permission required';
            setTimeout(function() {
                btn.classList.remove('mw-route-btn-error');
                label.textContent = DEFAULT_LABEL;
            }, 5000);
        } else if (state === 'error') {
            // Timeout / no fix / unavailable.
            btn.disabled = false;
            btn.classList.add('mw-route-btn-error');
            label.textContent = 'Location unavailable — check permissions';
            setTimeout(function() {
                btn.classList.remove('mw-route-btn-error');
                label.textContent = DEFAULT_LABEL;
            }, 4000);
        } else { // idle / done
            btn.disabled = false;
            label.textContent = DEFAULT_LABEL;
        }
    }

    // High-accuracy GPS options. Android can be slow to acquire a first fix
    // (cold start in a parked truck), so give it a generous 15s timeout.
    var GPS_OPTS = { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 };

    function permGranted(p) {
        return p && (p.location === 'granted' || p.coarseLocation === 'granted');
    }

    // GPS acquisition. onErr receives a reason: 'denied' (permission refused)
    // or 'error' (timeout / no fix / plugin missing).
    function acquire(onOk, onErr) {
        var isNative = window.Capacitor && window.Capacitor.isNativePlatform &&
                       window.Capacitor.isNativePlatform();
        var Geo = isNative && window.Capacitor.Plugins ? window.Capacitor.Plugins.Geolocation : null;

        // ── Native Android: request permission explicitly, then get a fix ──
        if (Geo) {
            var fix = function() {
                Geo.getCurrentPosition(GPS_OPTS)
                    .then(function(pos) {
                        if (pos && pos.coords) { onOk(pos.coords.latitude, pos.coords.longitude); }
                        else { onErr('error'); }
                    })
                    .catch(function() { onErr('error'); });
            };

            Geo.checkPermissions()
                .then(function(status) {
                    if (permGranted(status)) { fix(); return; }
                    // Not yet granted — show the Android system prompt.
                    Geo.requestPermissions({ permissions: ['location', 'coarseLocation'] })
                        .then(function(req) {
                            if (permGranted(req)) { fix(); }
                            else { onErr('denied'); }
                        })
                        .catch(function() { onErr('denied'); });
                })
                .catch(function() {
                    // checkPermissions unsupported on this plugin build — try a fix anyway.
                    fix();
                });
            return;
        }

        // ── Browser fallback (testing outside the app) ──
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function(pos) { onOk(pos.coords.latitude, pos.coords.longitude); },
                function(err) { onErr(err && err.code === 1 ? 'denied' : 'error'); },
                GPS_OPTS
            );
        } else {
            onErr('error');
        }
    }

    btn.addEventListener('click', function() {
        setBtn('loading');
        acquire(
            function(lat, lng) { sortByDistance(lat, lng); setBtn('done'); },
            function(reason) { setBtn(reason === 'denied' ? 'denied' : 'error'); }
        );
    });

    resetLink.addEventListener('click', function(e) {
        e.preventDefault();
        try { localStorage.removeItem(STORAGE_KEY); } catch (err) {}
        getCards().sort(bySeq).forEach(function(c) { timeline.appendChild(c); });
        hideIndicator();
    });

    // Restore a persisted sort for this date (survives page refresh; resets per-day).
    try {
        var saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        if (saved && saved.length) { applyOrder(saved); showIndicator(); }
    } catch (err) {}
})();
</script>

<!-- ═══ Skip Job — marks the visit 'skipped' (existing pow-actions endpoint) ═══ -->
<script>
(function() {
    'use strict';

    var SKIP_CSRF   = '<?php echo htmlspecialchars(generateCSRFToken(), ENT_QUOTES); ?>';
    var ROUTE_DATE  = '<?php echo htmlspecialchars($viewDate, ENT_QUOTES); ?>';
    var STORAGE_KEY = 'route_order_' + ROUTE_DATE;
    var totalEstMin = <?php echo (int)$totalEstimatedMin; ?>;

    function toast(message, type) {
        var t = document.createElement('div');
        t.className = 'mw-clock-toast mw-clock-toast-' + (type || 'info');
        t.textContent = message;
        document.body.appendChild(t);
        t.offsetHeight;
        t.classList.add('mw-clock-toast-visible');
        setTimeout(function() {
            t.classList.remove('mw-clock-toast-visible');
            setTimeout(function() { t.remove(); }, 300);
        }, 3000);
    }

    // Drop the skipped visit from the persisted "Route From Here" order so a
    // refresh doesn't try to re-place a card that no longer exists.
    function removeFromRouteOrder(jobId) {
        try {
            var saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
            if (saved && saved.length) {
                var next = saved.filter(function(id) { return String(id) !== String(jobId); });
                localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
            }
        } catch (e) {}
    }

    // Skipped jobs must not count toward the route stats.
    function updateStats(card) {
        var jobsEl = document.getElementById('mwStatJobs');
        if (jobsEl) {
            var n = Math.max(0, (parseInt(jobsEl.textContent, 10) || 0) - 1);
            jobsEl.textContent = n;
        }
        var estEl = document.getElementById('mwStatEst');
        if (estEl) {
            var mins = parseInt(card.getAttribute('data-est-min'), 10) || 0;
            totalEstMin = Math.max(0, totalEstMin - mins);
            estEl.textContent = Math.floor(totalEstMin / 60) + 'h ' + (totalEstMin % 60) + 'm';
        }
    }

    // Collapse + fade the card, then remove it from the DOM.
    function removeCard(card) {
        if (!card) return;
        card.style.maxHeight = card.scrollHeight + 'px';
        card.style.overflow = 'hidden';
        card.offsetHeight; // force reflow so the collapse transition runs
        card.classList.add('mw-tc-removing');
        setTimeout(function() { card.remove(); }, 350);
    }

    window.skipJob = function(jobId) {
        if (!confirm('Skip this job? It will be removed from today’s route.')) return;

        var card = document.getElementById('jobCard-' + jobId);

        fetch('/crm/api/pow-actions.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'skip_visit', visit_id: jobId, csrf_token: SKIP_CSRF })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.success) {
                removeFromRouteOrder(jobId);
                if (card) updateStats(card);
                removeCard(card);
                toast('Job skipped', 'info');
            } else {
                alert((data && data.error) || 'Could not skip this job. Please try again.');
            }
        })
        .catch(function() { alert('Network error — please try again.'); });
    };
})();
</script>

<!-- Mobile clock button logic (zero dependency — inline, no fetch needed for display) -->
<script>
// Inject flatline CSS at runtime — bypasses SW/HTTP cache on device
function mwInjectFlatlineCSS() {
    if (document.getElementById('mw-flatline-css')) return;
    var s = document.createElement('style');
    s.id = 'mw-flatline-css';
    s.textContent = '@keyframes mw-flatline{0%{clip-path:inset(0% 0% 0% 0%);opacity:1;filter:none}20%{clip-path:inset(48% 0% 48% 0%);opacity:1;filter:drop-shadow(0 0 6px #7FD858) drop-shadow(0 0 20px rgba(127,216,88,.6))}65%{clip-path:inset(48% 0% 48% 0%);opacity:1;filter:drop-shadow(0 0 6px #7FD858) drop-shadow(0 0 20px rgba(127,216,88,.6))}88%{clip-path:inset(48% 0% 48% 0%);opacity:0;filter:none}100%{clip-path:inset(50% 0% 50% 0%);opacity:0;filter:none}}.mw-flatline-out{animation:mw-flatline 2.8s ease-out forwards}';
    document.head.appendChild(s);
}
(function() {
    'use strict';

    // Mobile clock-in timer (keep it ticking if clocked in)
    // Uses MySQL TIMESTAMPDIFF elapsed_seconds to avoid PHP/MySQL timezone mismatch
    var mobileTimer = document.getElementById('mobileClockTimer');
    if (mobileTimer) {
        var initialElapsed = <?php echo $activeClock ? max(0, (int)$activeClock['elapsed_seconds']) : '0'; ?>;
        if (initialElapsed >= 0 && <?php echo $activeClock ? 'true' : 'false'; ?>) {
            var tickStart = Math.floor(Date.now() / 1000);
            setInterval(function() {
                var elapsed = initialElapsed + (Math.floor(Date.now() / 1000) - tickStart);
                var h = Math.floor(elapsed / 3600);
                var m = Math.floor((elapsed % 3600) / 60);
                var s = elapsed % 60;
                mobileTimer.textContent =
                    (h < 10 ? '0' + h : h) + ':' +
                    (m < 10 ? '0' + m : m) + ':' +
                    (s < 10 ? '0' + s : s);
            }, 1000);
        }
    }

    // Mobile clock-in button
    var btnIn = document.getElementById('mobileClockIn');
    if (btnIn) {
        btnIn.addEventListener('click', function() {
            btnIn.disabled = true;
            btnIn.textContent = 'Clocking in...';

            // Get GPS first, then clock in
            var lat = null, lng = null;
            function doClockIn() {
                fetch('/crm/api/time-clock.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'clock_in', lat: lat, lng: lng })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        // Flatline animation — clock section + day stats card vanish before reload
                        mwInjectFlatlineCSS();
                        var targets = [
                            document.getElementById('mobileClockSection'),
                            document.querySelector('.mw-schedule-stats')
                        ].filter(Boolean);

                        targets.forEach(function(el) { el.classList.add('mw-flatline-out'); });
                        setTimeout(function() { location.reload(); }, 3200);
                    } else {
                        alert(data.error || 'Clock in failed');
                        btnIn.disabled = false;
                        btnIn.textContent = 'Clock In';
                    }
                })
                .catch(function() {
                    alert('Network error — please try again');
                    btnIn.disabled = false;
                    btnIn.textContent = 'Clock In';
                });
            }

            if (window.MwNative && window.MwNative.geo) {
                window.MwNative.geo.getCurrentPosition()
                    .then(function(pos) { lat = pos.lat; lng = pos.lng; doClockIn(); })
                    .catch(function() { doClockIn(); });
            } else if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(pos) { lat = pos.coords.latitude; lng = pos.coords.longitude; doClockIn(); },
                    function() { doClockIn(); },
                    { timeout: 5000, maximumAge: 60000 }
                );
            } else {
                doClockIn();
            }
        });
    }

    // Mobile clock-out button
    var btnOut = document.getElementById('mobileClockOut');
    if (btnOut) {
        btnOut.addEventListener('click', function() {
            if (!confirm('Clock out now?')) return;
            btnOut.disabled = true;
            btnOut.textContent = 'Clocking out...';

            var lat = null, lng = null;
            function doClockOut() {
                fetch('/crm/api/time-clock.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'clock_out', lat: lat, lng: lng })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.error || 'Clock out failed');
                        btnOut.disabled = false;
                        btnOut.textContent = 'Clock Out';
                    }
                })
                .catch(function() {
                    alert('Network error — please try again');
                    btnOut.disabled = false;
                    btnOut.textContent = 'Clock Out';
                });
            }

            if (window.MwNative && window.MwNative.geo) {
                window.MwNative.geo.getCurrentPosition()
                    .then(function(pos) { lat = pos.lat; lng = pos.lng; doClockOut(); })
                    .catch(function() { doClockOut(); });
            } else if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(pos) { lat = pos.coords.latitude; lng = pos.coords.longitude; doClockOut(); },
                    function() { doClockOut(); },
                    { timeout: 5000, maximumAge: 60000 }
                );
            } else {
                doClockOut();
            }
        });
    }
})();
</script>

<?php if ($activeClock): ?>
<!-- Backup GPS tracking for mobile — ensures pings continue even if topbar widget fails -->
<script>
(function() {
    'use strict';

    // In native Capacitor, the background GPS plugin in time-clock-widget.js
    // handles tracking natively. NOTE: capacitor-bridge.js (which sets
    // window.MwNative) loads in the footer — AFTER this inline script — so this
    // one-shot check usually can't see it yet. We therefore keep this browser
    // watcher as a fallback but make sendGPS() defer at send-time (when MwNative
    // is available) so it never competes with / double-counts native pings.
    if (window.MwNative && window.MwNative.geo) return;

    var SEND_INTERVAL = 30000; // 30 seconds
    var gpsWatchId = null;
    var sendTimer = null;
    var latestPos = null;

    function sendGPS() {
        // If the native background plugin is actively tracking, let it own the
        // pings — don't fire the foreground browser ping on top of it.
        if (window.MwNative && window.MwNative.geo && window.MwNative.geo.watchId) return;
        if (!latestPos) return;
        fetch('/crm/api/crew-location.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                lat: latestPos.lat,
                lng: latestPos.lng,
                accuracy: latestPos.accuracy,
                speed: latestPos.speed,
                heading: latestPos.heading
            })
        }).catch(function() { /* silent */ });
    }

    function startWatch() {
        if (gpsWatchId !== null) return;
        if (!navigator.geolocation) return;

        gpsWatchId = navigator.geolocation.watchPosition(
            function(pos) {
                latestPos = {
                    lat: pos.coords.latitude,
                    lng: pos.coords.longitude,
                    accuracy: pos.coords.accuracy,
                    speed: pos.coords.speed,
                    heading: pos.coords.heading
                };
            },
            function() { /* silent */ },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );

        sendGPS(); // immediate ping
        sendTimer = setInterval(sendGPS, SEND_INTERVAL);
    }

    function stopWatch() {
        if (gpsWatchId !== null) {
            navigator.geolocation.clearWatch(gpsWatchId);
            gpsWatchId = null;
        }
        if (sendTimer) {
            clearInterval(sendTimer);
            sendTimer = null;
        }
    }

    // Start tracking
    startWatch();

    // Restart on visibility change (mobile Safari suspends JS when backgrounded)
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            stopWatch();
            startWatch();
        }
    });

    // iOS bfcache
    window.addEventListener('pageshow', function(e) {
        if (e.persisted) {
            stopWatch();
            startWatch();
        }
    });
})();
</script>
<?php endif; ?>

<?php if ($needsNativeApp): ?>
<!-- Native-app enforcement gate: shown by JS when MwNative is absent -->
<div id="mw-native-gate" class="mw-native-gate" style="display:none;" aria-modal="true" role="alertdialog">
    <div class="mw-native-gate-card">
        <div class="mw-native-gate-logo">Mowology</div>
        <h2 class="mw-native-gate-heading">Use the Crew App</h2>
        <p class="mw-native-gate-body">For reliable GPS tracking and schedule updates, open Mowology in the Android app — not your browser.</p>

        <!-- Primary action: launch the installed app via Android intent.
             Falls back to the APK download if the app isn't installed.
             Package name = Capacitor appId (ca.mowology.crm). -->
        <a id="mw-native-gate-open" href="intent://mowology.ca/crm/timeclock/my-schedule.php#Intent;scheme=https;package=ca.mowology.crm;S.browser_fallback_url=https%3A%2F%2Fmowology.ca%2Fcrm%2Fdownloads%2Fmowology-crew.apk;end" class="mw-native-gate-btn">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:8px;vertical-align:middle"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            Already installed? Open the App
        </a>

        <div class="mw-native-gate-divider"><span>Don't have it yet?</span></div>

        <a id="mw-native-gate-download" href="/crm/downloads/mowology-crew.apk" class="mw-native-gate-btn mw-native-gate-btn-secondary" download>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:8px;vertical-align:middle"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download Mowology Crew App
        </a>
        <div id="mw-native-gate-downloading" class="mw-native-gate-downloading">
            APK downloading — open your <strong>Downloads</strong> app to install it.
            <a id="mw-native-gate-open-downloads" href="intent://downloads#Intent;scheme=file;package=com.android.documentsui;end" class="mw-native-gate-btn mw-native-gate-btn-secondary">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:8px;vertical-align:middle"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                Open Downloads folder
            </a>
        </div>
    </div>
</div>
<script>
(function() {
    'use strict';
    var gate = document.getElementById('mw-native-gate');
    if (!gate) return;

    // Decide whether we are running INSIDE the Capacitor app. The interstitial
    // must NEVER appear inside the app's own WebView — only in a real mobile
    // browser (Chrome, Samsung Internet, Firefox, etc.). Any signal counts.
    //
    // The old check relied solely on window.MwNative, which is set by
    // capacitor-bridge.js — but that script loads in the footer, AFTER this
    // inline block runs, so MwNative was always undefined here and the gate
    // showed even inside the app. We now check window.Capacitor directly (the
    // native bridge is injected early by the OS layer) and add a UA fallback.
    function isInApp() {
        try {
            if (window.Capacitor) {
                if (typeof window.Capacitor.isNativePlatform === 'function' &&
                    window.Capacitor.isNativePlatform()) return true;
                if (window.Capacitor.isNative) return true;
            }
        } catch (e) {}
        if (window.MwNative) return true;
        // Any Android WebView UA contains "wv"; real browsers do not. Also match
        // a custom token in case the native build appends one to the UA.
        var ua = navigator.userAgent || '';
        if (/;\s*wv\b/i.test(ua) || /\bwv\)/i.test(ua) || /CapApp|MowologyCrew/i.test(ua)) return true;
        return false;
    }

    function hideGate() {
        gate.style.display = 'none';
        document.body.style.overflow = '';
    }

    function showGate() {
        if (gate.style.display === 'flex') return; // already shown
        gate.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        // Post-download guidance: after Download is tapped the APK lands in the
        // Downloads folder with no system prompt, so tell them what to do next.
        var dl = document.getElementById('mw-native-gate-download');
        var note = document.getElementById('mw-native-gate-downloading');
        if (dl && note && !dl._mwBound) {
            dl._mwBound = true;
            dl.addEventListener('click', function() {
                setTimeout(function() { note.classList.add('is-visible'); }, 400);
            });
        }
    }

    // Detected as in-app immediately → never show.
    if (isInApp()) { hideGate(); return; }

    // Not detected yet. The native bridge can inject a beat after this inline
    // script runs, so don't show immediately — poll briefly. If the bridge
    // appears we stay hidden; if the grace window passes with no bridge, we're
    // in a real browser → show the gate. Keep listening afterwards so a late
    // bridge still dismisses it.
    var tries = 0;
    var poll = setInterval(function() {
        tries++;
        if (isInApp()) { clearInterval(poll); hideGate(); return; }
        if (tries >= 12) { clearInterval(poll); showGate(); } // ~1.2s grace
    }, 100);

    document.addEventListener('deviceready', function() { if (isInApp()) hideGate(); }, { once: true });
    window.addEventListener('load', function() { if (isInApp()) hideGate(); });
})();
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
