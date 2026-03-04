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
            <a href="?date=<?php echo $prevDate; ?>" class="btn btn-sm btn-outline-secondary">&laquo;</a>
            <span class="mw-schedule-date-display">
                <?php echo date('l, M j, Y', strtotime($viewDate)); ?>
            </span>
            <a href="?date=<?php echo $nextDate; ?>" class="btn btn-sm btn-outline-secondary">&raquo;</a>
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
        <div class="mw-schedule-stat-value"><?php echo count($jobs); ?></div>
        <div class="mw-schedule-stat-label">Jobs Today</div>
    </div>
    <div class="mw-schedule-stat">
        <div class="mw-schedule-stat-value"><?php echo $completedCount; ?></div>
        <div class="mw-schedule-stat-label">Completed</div>
    </div>
    <div class="mw-schedule-stat">
        <div class="mw-schedule-stat-value"><?php echo formatMinutesAsHours($totalEstimatedMin); ?></div>
        <div class="mw-schedule-stat-label">Est. Total</div>
    </div>
</div>

<!-- Job Timeline -->
<div class="mw-job-timeline" id="jobTimeline">
    <?php if (empty($jobs)): ?>
        <div class="mw-schedule-empty">
            <i data-feather="calendar"></i>
            <h3>No jobs scheduled</h3>
            <p>You have no jobs assigned for <?php echo date('l, M j', strtotime($viewDate)); ?>.</p>
        </div>
    <?php else: ?>
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

<!-- Mobile clock button logic (zero dependency — inline, no fetch needed for display) -->
<script>
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
                        var targets = [
                            document.getElementById('mobileClockSection'),
                            document.querySelector('.mw-schedule-stats')
                        ].filter(Boolean);

                        targets.forEach(function(el) { el.classList.add('mw-flatline-out'); });
                        setTimeout(function() { location.reload(); }, 1450);
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
    // handles tracking natively — no need for this browser-based backup.
    if (window.MwNative && window.MwNative.geo) return;

    var SEND_INTERVAL = 30000; // 30 seconds
    var gpsWatchId = null;
    var sendTimer = null;
    var latestPos = null;

    function sendGPS() {
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

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
