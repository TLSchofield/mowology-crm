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

$pageTitle = 'My Schedule';
$activePage = 'timeclock';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<!-- Schedule Header -->
<div class="mw-schedule-header">
    <div>
        <h1 class="h3 mb-1">My Schedule</h1>
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

    <div class="d-flex align-items-center gap-3" style="gap: 12px;">
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
                         data-start="<?php echo htmlspecialchars($job['timer_start_time']); ?>">
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

        var cards = document.querySelectorAll('.mw-tc-card[data-status="scheduled"]');
        cards.forEach(function(card) {
            var jobId = card.getAttribute('data-job-id');
            var lat = parseFloat(card.getAttribute('data-lat'));
            var lng = parseFloat(card.getAttribute('data-lng'));

            if (isNaN(lat) || isNaN(lng) || alertedJobs[jobId]) return;

            var distance = haversineDistance(currentLat, currentLng, lat, lng);
            if (distance <= GPS_PROXIMITY_METERS) {
                alertedJobs[jobId] = true;
                showGPSAlert(jobId, card.querySelector('.mw-tc-card-title').textContent.trim());
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

    function showGPSAlert(jobId, jobTitle) {
        var alert = document.getElementById('gpsAlert');
        var text = document.getElementById('gpsAlertText');
        text.textContent = 'You\'re near "' + jobTitle + '". Start timer?';
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
            var startStr = timer.getAttribute('data-start');
            if (!startStr) return;
            var startTime = new Date(startStr.replace(' ', 'T'));
            var display = timer.querySelector('.mw-tc-timer-display');
            var jobId = timer.id.replace('jobTimer-', '');

            function updateDisplay() {
                var elapsed = Math.floor((Date.now() - startTime.getTime()) / 1000);
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

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
