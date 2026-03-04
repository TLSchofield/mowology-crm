<?php
/**
 * Driver Portal — Mobile-first daily dashboard for field drivers.
 *
 * Shows: welcome card, today's jobs, weather, clock in/out, vehicle trip report.
 * Pre-trip check: prompted at start of shift.
 * Post-trip check: required before clocking out.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';
require_once 'includes/timeclock-functions.php';
require_once 'includes/weather-service.php';

requireLogin();
$user = getCurrentUser();

$db    = getDB();
$today = date('Y-m-d');
$hour  = (int)date('G');

if ($hour < 12)       $greeting = 'Good morning';
elseif ($hour < 17)   $greeting = 'Good afternoon';
else                  $greeting = 'Good evening';

$firstName = explode(' ', $user['name'])[0];

// ── Today's jobs ──────────────────────────────────────────────────────────────
$jobs = getUserJobsForDate($user['id'], $today);
$jobCount = count($jobs);

// Estimate revenue: sum estimated_duration_minutes * some rate — or just count
// Show simple job count + rough revenue from job plans if available
$totalRevenue = 0;
foreach ($jobs as $j) {
    // Try to pull quoted amount from job_plans
}
// Query separately for revenue (join job_plans)
$revStmt = $db->prepare("
    SELECT COALESCE(SUM(jp.price_per_visit), 0) as total_revenue
    FROM job_visits jv
    JOIN job_plans jp ON jv.plan_id = jp.id
    WHERE jv.scheduled_date = ?
      AND jv.assigned_crew_id = ?
      AND jv.status IN ('scheduled','in_progress','completed')
");
$revStmt->execute([$today, $user['id']]);
$totalRevenue = (float)($revStmt->fetchColumn() ?: 0);

// ── Clock status ──────────────────────────────────────────────────────────────
$activeClock = getActiveClockEntry($user['id']);
$isClockedIn = (bool)$activeClock;

if ($isClockedIn) {
    $elapsedSec = (int)($activeClock['elapsed_seconds'] ?? 0);
    $elapsedH   = floor($elapsedSec / 3600);
    $elapsedM   = floor(($elapsedSec % 3600) / 60);
    $elapsedStr = $elapsedH > 0 ? "{$elapsedH}h {$elapsedM}m" : "{$elapsedM}m";
} else {
    $elapsedStr = '';
}

// ── Trip report ───────────────────────────────────────────────────────────────
$tripStmt = $db->prepare("SELECT * FROM vehicle_trip_reports WHERE driver_id = ? AND report_date = ?");
$tripStmt->execute([$user['id'], $today]);
$tripReport = $tripStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$preComplete  = $tripReport && $tripReport['pre_trip_at']  !== null;
$postComplete = $tripReport && $tripReport['post_trip_at'] !== null;
$preTime      = $preComplete  ? date('g:i a', strtotime($tripReport['pre_trip_at']))  : null;
$postTime     = $postComplete ? date('g:i a', strtotime($tripReport['post_trip_at'])) : null;

// ── Weather ───────────────────────────────────────────────────────────────────
$weather = [];
try {
    $morningWeather   = getWeatherForecast('Vancouver', 'BC', $today);
    $afternoonWeather = $morningWeather; // same day data, use high/low split
} catch (Throwable $t) {
    $morningWeather = $afternoonWeather = [];
}

$wIcon  = function_exists('getWeatherIcon')  ? getWeatherIcon($morningWeather['condition']  ?? 'Clear') : '☁️';
$wHigh  = isset($morningWeather['temp_high']) ? (int)$morningWeather['temp_high'] : null;
$wLow   = isset($morningWeather['temp_low'])  ? (int)$morningWeather['temp_low']  : null;
$wCond  = $morningWeather['condition'] ?? null;
$wPrecip = isset($morningWeather['precipitation']) ? (int)$morningWeather['precipitation'] : 0;

// ── CSRF ──────────────────────────────────────────────────────────────────────
$csrf = generateCSRFToken();

$pageTitle  = 'Driver Portal';
$activePage = 'driver';
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="dp-page">

    <!-- ══ Welcome Card ══ -->
    <div class="dp-card">
        <div class="dp-welcome-name"><?php echo htmlspecialchars($greeting . ', ' . $firstName); ?></div>
        <div class="dp-welcome-date"><?php echo date('l, F j'); ?> &bull; <?php echo htmlspecialchars($user['name']); ?></div>
    </div>

    <!-- ══ Today's Jobs ══ -->
    <div class="dp-card">
        <div class="dp-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 3H8l-2 4h12l-2-4z"/></svg>
            Today's Jobs
        </div>
        <?php if ($jobCount > 0): ?>
        <div class="dp-jobs-row">
            <div class="dp-jobs-stat">
                <div class="dp-jobs-num"><?php echo $jobCount; ?></div>
                <div class="dp-jobs-lbl"><?php echo $jobCount === 1 ? 'Visit' : 'Visits'; ?></div>
            </div>
            <?php if ($totalRevenue > 0): ?>
            <div class="dp-jobs-divider"></div>
            <div class="dp-jobs-stat">
                <div class="dp-jobs-num">$<?php echo number_format($totalRevenue, 0); ?></div>
                <div class="dp-jobs-lbl">Est. Revenue</div>
            </div>
            <?php endif; ?>
            <div style="flex:1; text-align:right;">
                <a href="/crm/timeclock/my-schedule.php" class="dp-jobs-link">
                    View Schedule
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="dp-no-jobs">No jobs scheduled today.</div>
        <?php endif; ?>
    </div>

    <!-- ══ Weather ══ -->
    <?php if ($wHigh !== null || $wCond): ?>
    <div class="dp-card" id="dpWeatherCard">
        <div class="dp-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9z"/></svg>
            Today's Weather
        </div>
        <div class="dp-weather-row">
            <?php if ($wHigh !== null): ?>
            <div class="dp-weather-period">
                <div class="dp-weather-icon"><?php echo $wIcon; ?></div>
                <div>
                    <div class="dp-weather-label">Morning</div>
                    <div class="dp-weather-temp"><?php echo $wHigh; ?>&deg;C</div>
                    <?php if ($wCond): ?><div class="dp-weather-cond"><?php echo htmlspecialchars($wCond); ?></div><?php endif; ?>
                </div>
            </div>
            <?php if ($wLow !== null): ?>
            <div class="dp-weather-divider"></div>
            <div class="dp-weather-period">
                <div>
                    <div class="dp-weather-label">Afternoon Low</div>
                    <div class="dp-weather-temp"><?php echo $wLow; ?>&deg;C</div>
                    <?php if ($wPrecip > 20): ?><div class="dp-weather-cond"><?php echo $wPrecip; ?>% precip.</div><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="dp-no-jobs">Weather data unavailable.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ Clock In / Out ══ -->
    <div class="dp-card">
        <div class="dp-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Time Clock
        </div>
        <div class="dp-clock-row" id="dpClockRow">
            <?php if ($isClockedIn): ?>
            <div class="dp-clock-status">
                <div class="dp-clock-dot in"></div>
                Clocked In
                <span class="dp-clock-elapsed" id="dpElapsed"><?php echo htmlspecialchars($elapsedStr); ?></span>
            </div>
            <button class="dp-clock-btn clock-out" id="dpClockOutBtn" onclick="dpClockOut()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Clock Out
            </button>
            <?php else: ?>
            <div class="dp-clock-status">
                <div class="dp-clock-dot out"></div>
                Clocked Out
            </div>
            <button class="dp-clock-btn clock-in" id="dpClockInBtn" onclick="dpClockIn()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                Clock In
            </button>
            <?php endif; ?>
        </div>
        <?php if ($isClockedIn && !$postComplete): ?>
        <div class="dp-clock-blocked" id="dpClockoutWarning">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Complete the post-trip vehicle check before clocking out
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ Vehicle Check ══ -->
    <div class="dp-card">
        <div class="dp-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            Vehicle Check &mdash; RAM 3500 PF8865
        </div>

        <div class="dp-check-row">
            <!-- Pre-trip badge -->
            <?php if ($preComplete): ?>
            <span class="dp-check-badge done">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Pre-Trip <span class="dp-check-time">&nbsp;<?php echo htmlspecialchars($preTime); ?></span>
            </span>
            <?php else: ?>
            <span class="dp-check-badge pending">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Pre-Trip: Pending
            </span>
            <?php endif; ?>

            <!-- Post-trip badge -->
            <?php if ($postComplete): ?>
            <span class="dp-check-badge done">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Post-Trip <span class="dp-check-time">&nbsp;<?php echo htmlspecialchars($postTime); ?></span>
            </span>
            <?php elseif ($preComplete): ?>
            <span class="dp-check-badge pending">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Post-Trip: Pending
            </span>
            <?php else: ?>
            <span class="dp-check-badge not-done">Post-Trip: Waiting</span>
            <?php endif; ?>
        </div>

        <?php if (!$preComplete): ?>
        <button class="dp-action-btn primary" onclick="dpOpenForm('pre')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            <div class="dp-action-btn-label">
                Start Pre-Trip Inspection
                <div class="dp-action-btn-sub">Running order, odometer, defects</div>
            </div>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
        <?php elseif ($preComplete && !$postComplete): ?>
        <button class="dp-action-btn primary" onclick="dpOpenForm('post')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            <div class="dp-action-btn-label">
                Complete Post-Trip Check
                <div class="dp-action-btn-sub">Odometer end, HOS, remarks</div>
            </div>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
        <?php else: ?>
        <a href="/crm/api/trip-report.php?action=download&id=<?php echo (int)$tripReport['id']; ?>"
           class="dp-action-btn secondary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <div class="dp-action-btn-label">
                Download Today's Report PDF
                <div class="dp-action-btn-sub">Saved for compliance records</div>
            </div>
        </a>
        <?php endif; ?>
    </div>

</div><!-- /.dp-page -->

<!-- ════════════════════════════════════════════════════════════════════════════
     PRE-TRIP OVERLAY
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="dp-overlay" id="dpPreTripOverlay">
    <div class="dp-overlay-header">
        <button class="dp-overlay-back" onclick="dpCloseForm('pre')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        </button>
        <div>
            <div class="dp-overlay-title">Pre-Trip Inspection</div>
            <div class="dp-overlay-subtitle">RAM 3500 PF8865 &bull; <?php echo date('M j, Y'); ?></div>
        </div>
    </div>
    <div class="dp-overlay-body">
        <form id="dpPreTripForm" onsubmit="dpSubmitPreTrip(event)">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="save_pre_trip">

            <!-- Running Order -->
            <div class="dp-form-section">
                <div class="dp-form-section-title">Running Order Checklist</div>

                <?php
                $checks = [
                    'chk_leaks'          => 'Check Beneath Truck for Leaks',
                    'chk_hitch'          => 'Hitch Secure & Light Plug Attached',
                    'chk_rear_gate'      => 'Rear Truck Gate Secure',
                    'chk_loads_secure'   => 'Loads Secure',
                    'chk_trailer_lights' => 'Trailer Lights',
                    'chk_truck_brakes'   => 'Truck Brakes',
                    'chk_truck_lights'   => 'Truck Lights',
                    'chk_mirrors'        => 'Mirrors',
                    'chk_tire_pressure'  => 'Tire Pressure',
                    'chk_washer_wipers'  => 'Washer Fluids + Wipers',
                ];
                foreach ($checks as $name => $label):
                    $checked = ($tripReport && !empty($tripReport[$name])) ? 'checked' : '';
                ?>
                <label class="dp-check-item">
                    <input type="checkbox" name="<?php echo $name; ?>" <?php echo $checked; ?>>
                    <div class="dp-check-icon"></div>
                    <span class="dp-check-label"><?php echo htmlspecialchars($label); ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- Odometer -->
            <div class="dp-form-section">
                <div class="dp-form-section-title">Odometer</div>
                <div class="dp-field">
                    <label for="dpOdomStart">Start of Day (km)</label>
                    <input type="number" id="dpOdomStart" name="odometer_start" min="0" max="999999"
                           placeholder="e.g. 48250"
                           value="<?php echo htmlspecialchars((string)($tripReport['odometer_start'] ?? '')); ?>">
                </div>
            </div>

            <!-- Defects -->
            <div class="dp-form-section">
                <div class="dp-form-section-title">Defects & Repairs</div>
                <div class="dp-field">
                    <label>MUST Be Fixed Before Safe To Drive <small style="text-transform:none;font-weight:400;">(contact Vital Auto Repair & office immediately)</small></label>
                    <textarea name="defects_critical" rows="3" placeholder="Describe critical defects, or leave blank if none"><?php echo htmlspecialchars((string)($tripReport['defects_critical'] ?? '')); ?></textarea>
                </div>
                <label class="dp-check-item" style="margin-bottom:8px;">
                    <input type="checkbox" name="defect_unhitch" <?php echo ($tripReport && !empty($tripReport['defect_unhitch'])) ? 'checked' : ''; ?>>
                    <div class="dp-check-icon"></div>
                    <span class="dp-check-label">Unhitch Trailer</span>
                </label>
                <div class="dp-field">
                    <label>Non-Urgent Defects / Repairs</label>
                    <textarea name="defects_non_urgent" rows="2" placeholder="Describe non-urgent issues, or leave blank"><?php echo htmlspecialchars((string)($tripReport['defects_non_urgent'] ?? '')); ?></textarea>
                </div>
            </div>

            <!-- Safe to Drive -->
            <div class="dp-form-section">
                <div class="dp-form-section-title">Safe to Drive Declaration</div>
                <div class="dp-ack-box <?php echo ($tripReport && !empty($tripReport['safe_to_drive'])) ? 'checked' : ''; ?>"
                     id="dpAckBox" onclick="dpToggleAck()">
                    <div class="dp-ack-text">
                        By ticking this box and submitting the form you acknowledge that you take full legal responsibility for the truck being safe to drive on Canadian highways.
                    </div>
                    <div class="dp-ack-checkbox">
                        <div class="dp-ack-indicator" id="dpAckIndicator"></div>
                        <span class="dp-ack-text-label" id="dpAckLabel">Safe to Drive — I confirm</span>
                    </div>
                </div>
                <input type="hidden" name="safe_to_drive" id="dpSafeToDriveInput"
                       value="<?php echo ($tripReport && !empty($tripReport['safe_to_drive'])) ? '1' : '0'; ?>">
            </div>

            <!-- MVA Legal text -->
            <div style="background:#f5f5f5;border-left:3px solid #2D8659;padding:10px 12px;border-radius:0 6px 6px 0;font-size:11px;color:#555;line-height:1.5;margin-bottom:16px;">
                <strong style="color:#1A5F4A;display:block;margin-bottom:4px;">MVA 37.18.01b) — Cycle 1 — Within 160 Km</strong>
                (a) The driver operates within a radius of 160 km of the home terminal,
                (b) The driver returns to the home terminal each day to begin a minimum of 8 consecutive hours of off-duty time, and
                (c) The carrier maintains accurate and legible records for a minimum period of 6 months.
            </div>

            <button type="submit" class="dp-submit-btn" id="dpPreTripSubmitBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Save Pre-Trip Inspection
            </button>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     POST-TRIP OVERLAY
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="dp-overlay" id="dpPostTripOverlay">
    <div class="dp-overlay-header">
        <button class="dp-overlay-back" onclick="dpCloseForm('post')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        </button>
        <div>
            <div class="dp-overlay-title">Post-Trip Check</div>
            <div class="dp-overlay-subtitle">RAM 3500 PF8865 &bull; <?php echo date('M j, Y'); ?></div>
        </div>
    </div>
    <div class="dp-overlay-body">
        <form id="dpPostTripForm" onsubmit="dpSubmitPostTrip(event)">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="save_post_trip">

            <!-- Odometer end -->
            <div class="dp-form-section">
                <div class="dp-form-section-title">Odometer</div>
                <div class="dp-field">
                    <label for="dpOdomEnd">End of Day (km)</label>
                    <input type="number" id="dpOdomEnd" name="odometer_end" min="0" max="999999"
                           placeholder="e.g. 48640"
                           value="<?php echo htmlspecialchars((string)($tripReport['odometer_end'] ?? '')); ?>">
                </div>
            </div>

            <!-- End of day remarks -->
            <div class="dp-form-section">
                <div class="dp-form-section-title">End of Day Remarks & Defect Report</div>
                <div class="dp-field">
                    <label>Remarks <small style="text-transform:none;font-weight:400;">(if none, leave blank)</small></label>
                    <textarea name="end_of_day_remarks" rows="4"
                              placeholder="Any issues, incidents, or observations from today"><?php echo htmlspecialchars((string)($tripReport['end_of_day_remarks'] ?? '')); ?></textarea>
                </div>
            </div>

            <!-- Hours of Service -->
            <div class="dp-form-section">
                <div class="dp-form-section-title">Hours of Service — Bundled to Equal 24 hrs (Midnight to Midnight)</div>
                <div class="dp-field">
                    <label>On-Duty Driving Time — Start Time to Finish</label>
                    <input type="text" name="hos_on_duty_driving"
                           placeholder="e.g. 7:00am – 4:30pm (9.5 hrs)"
                           value="<?php echo htmlspecialchars((string)($tripReport['hos_on_duty_driving'] ?? '')); ?>">
                </div>
                <div class="dp-field">
                    <label>On-Duty Other</label>
                    <input type="text" name="hos_on_duty_other"
                           placeholder="e.g. 0.5 hrs (loading/unloading)"
                           value="<?php echo htmlspecialchars((string)($tripReport['hos_on_duty_other'] ?? '')); ?>">
                </div>
                <div class="dp-field">
                    <label>Off-Duty</label>
                    <input type="text" name="hos_off_duty"
                           placeholder="e.g. 14 hrs"
                           value="<?php echo htmlspecialchars((string)($tripReport['hos_off_duty'] ?? '')); ?>">
                </div>
            </div>

            <button type="submit" class="dp-submit-btn" id="dpPostTripSubmitBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Submit Post-Trip &amp; Generate PDF
            </button>
        </form>
    </div>
</div>

<!-- Toast -->
<div class="dp-toast" id="dpToast"></div>

<script>
var DP = {
    csrf:        <?php echo json_encode($csrf); ?>,
    isClockedIn: <?php echo $isClockedIn ? 'true' : 'false'; ?>,
    preComplete: <?php echo $preComplete ? 'true' : 'false'; ?>,
    postComplete:<?php echo $postComplete ? 'true' : 'false'; ?>,
    clockStart:  <?php echo $isClockedIn ? (int)strtotime($activeClock['clock_in']) : 'null'; ?>,
    reportId:    <?php echo $tripReport ? (int)$tripReport['id'] : 'null'; ?>
};

// ── Overlay open/close ────────────────────────────────────────────────────────
function dpOpenForm(type) {
    var id = type === 'pre' ? 'dpPreTripOverlay' : 'dpPostTripOverlay';
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}
function dpCloseForm(type) {
    var id = type === 'pre' ? 'dpPreTripOverlay' : 'dpPostTripOverlay';
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}

// Close overlays on back button
window.addEventListener('popstate', function() {
    document.querySelectorAll('.dp-overlay.active').forEach(function(el) {
        el.classList.remove('active');
        document.body.style.overflow = '';
    });
});

// ── Safe-to-drive acknowledgment toggle ──────────────────────────────────────
function dpToggleAck() {
    var box   = document.getElementById('dpAckBox');
    var input = document.getElementById('dpSafeToDriveInput');
    var label = document.getElementById('dpAckLabel');
    var checked = box.classList.toggle('checked');
    input.value = checked ? '1' : '0';
    label.textContent = checked ? 'Safe to Drive — Confirmed ✓' : 'Safe to Drive — I confirm';
}

// ── Clock In ─────────────────────────────────────────────────────────────────
function dpClockIn() {
    var btn = document.getElementById('dpClockInBtn');
    btn.disabled = true;
    btn.textContent = 'Clocking in…';

    var lat = null, lng = null;
    function doClockIn() {
        fetch('/app/Modules/Team/Api/time-clock.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'clock_in', lat: lat, lng: lng})
        })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.success || data.clocked_in) {
                dpToast('Clocked in!');
                // Flatline animation on weather card before reload
                var weatherCard = document.getElementById('dpWeatherCard');
                if (weatherCard) {
                    weatherCard.classList.add('mw-flatline-out');
                    setTimeout(function() {
                        weatherCard.classList.remove('mw-flatline-out');
                        weatherCard.classList.add('mw-flatline-collapse');
                        setTimeout(function(){ location.reload(); }, 380);
                    }, 900);
                } else {
                    setTimeout(function(){ location.reload(); }, 800);
                }
            } else {
                dpToast(data.error || 'Clock in failed', true);
                btn.disabled = false;
                btn.textContent = 'Clock In';
            }
        })
        .catch(function(){ dpToast('Network error', true); btn.disabled = false; btn.textContent = 'Clock In'; });
    }

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(pos){ lat = pos.coords.latitude; lng = pos.coords.longitude; doClockIn(); },
            function(){    doClockIn(); },
            { timeout: 5000 }
        );
    } else {
        doClockIn();
    }
}

// ── Clock Out ─────────────────────────────────────────────────────────────────
function dpClockOut() {
    if (!DP.postComplete) {
        dpToast('Complete the post-trip vehicle check first', true);
        // Scroll to vehicle check card
        document.querySelector('.dp-card:last-of-type').scrollIntoView({behavior:'smooth'});
        return;
    }

    var btn = document.getElementById('dpClockOutBtn');
    btn.disabled = true;
    btn.textContent = 'Clocking out…';

    var lat = null, lng = null;
    function doClockOut() {
        fetch('/app/Modules/Team/Api/time-clock.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'clock_out', lat: lat, lng: lng})
        })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.success || !data.clocked_in) {
                dpToast('Clocked out. Good work today!');
                setTimeout(function(){ location.reload(); }, 1000);
            } else {
                dpToast(data.error || 'Clock out failed', true);
                btn.disabled = false;
                btn.textContent = 'Clock Out';
            }
        })
        .catch(function(){ dpToast('Network error', true); btn.disabled = false; btn.textContent = 'Clock Out'; });
    }

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(pos){ lat = pos.coords.latitude; lng = pos.coords.longitude; doClockOut(); },
            function(){    doClockOut(); },
            { timeout: 5000 }
        );
    } else {
        doClockOut();
    }
}

// ── Submit Pre-Trip ───────────────────────────────────────────────────────────
function dpSubmitPreTrip(e) {
    e.preventDefault();
    var btn = document.getElementById('dpPreTripSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Saving…';

    var form = document.getElementById('dpPreTripForm');
    var data = new FormData(form);

    fetch('/crm/api/trip-report.php', { method: 'POST', body: data })
    .then(function(r){ return r.json(); })
    .then(function(res) {
        if (res.success) {
            dpToast('Pre-trip inspection saved!');
            setTimeout(function(){ location.reload(); }, 800);
        } else {
            dpToast(res.error || 'Save failed', true);
            btn.disabled = false;
            btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Save Pre-Trip Inspection';
        }
    })
    .catch(function(){ dpToast('Network error', true); btn.disabled = false; });
}

// ── Submit Post-Trip ──────────────────────────────────────────────────────────
function dpSubmitPostTrip(e) {
    e.preventDefault();
    var btn = document.getElementById('dpPostTripSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Generating PDF…';

    var form = document.getElementById('dpPostTripForm');
    var data = new FormData(form);

    fetch('/crm/api/trip-report.php', { method: 'POST', body: data })
    .then(function(r){ return r.json(); })
    .then(function(res) {
        if (res.success) {
            dpToast('Post-trip complete! PDF saved.');
            setTimeout(function(){ location.reload(); }, 1000);
        } else {
            dpToast(res.error || 'Save failed', true);
            btn.disabled = false;
            btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Submit Post-Trip & Generate PDF';
        }
    })
    .catch(function(){ dpToast('Network error', true); btn.disabled = false; });
}

// ── Elapsed clock timer ───────────────────────────────────────────────────────
if (DP.isClockedIn && DP.clockStart) {
    function updateElapsed() {
        var now  = Math.floor(Date.now() / 1000);
        var secs = now - DP.clockStart;
        var h = Math.floor(secs / 3600);
        var m = Math.floor((secs % 3600) / 60);
        var el = document.getElementById('dpElapsed');
        if (el) el.textContent = h > 0 ? h + 'h ' + m + 'm' : m + 'm';
    }
    updateElapsed();
    setInterval(updateElapsed, 30000);
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function dpToast(msg, isError) {
    var el = document.getElementById('dpToast');
    el.textContent = msg;
    el.className = 'dp-toast' + (isError ? ' error' : '');
    void el.offsetWidth;
    el.classList.add('show');
    setTimeout(function(){ el.classList.remove('show'); }, 3000);
}
</script>

<?php include 'includes/appstack_footer.php'; ?>
