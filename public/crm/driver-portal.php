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

// Only designated drivers can see the driver portal — admins use the main CRM
if (empty($user['is_driver'])) {
    header('Location: /crm/');
    exit;
}

$db    = getDB();
$today = date('Y-m-d');

// ── Pre-shift quiz gate ────────────────────────────────────────────────────────
$preshiftEnabled = false;
$preshiftDone    = false;
$preshiftLen     = 3;
(function () use ($db, $user, &$preshiftEnabled, &$preshiftDone, &$preshiftLen) {
    $row = $db->query(
        "SELECT setting_value FROM ops_settings WHERE setting_key='quiz_preshift_enabled'"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['setting_value'] != '1') return;
    // Per-user override — defensive until migration 1015 runs
    try {
        $skipRow = $db->prepare("SELECT quiz_preshift_skip FROM users WHERE id = ?");
        $skipRow->execute([$user['id']]);
        if ($skipRow->fetchColumn()) return;
    } catch (Throwable $e) {
        // Column not yet migrated — ignore
    }
    $preshiftEnabled = true;
    $lrow = $db->query(
        "SELECT setting_value FROM ops_settings WHERE setting_key='quiz_preshift_session_length'"
    )->fetch(PDO::FETCH_ASSOC);
    $preshiftLen = max(3, (int)($lrow['setting_value'] ?? 3));
    $done = $db->prepare(
        "SELECT id FROM quiz_preshift_log WHERE user_id=? AND log_date=CURDATE()"
    );
    $done->execute([$user['id']]);
    $preshiftDone = (bool)$done->fetch();
})();
$hour             = (int)date('G');
$greetingHour     = $hour;
$dayGreeting      = $greetingHour < 12 ? 'Good morning' : ($greetingHour < 17 ? 'Good afternoon' : 'Good evening');
$greetingFirstName = explode(' ', trim($user['name'] ?? 'there'))[0];

// ── Today's jobs ──────────────────────────────────────────────────────────────
$jobs       = getUserJobsForDate($user['id'], $today);
$totalStops = count($jobs);

// Completed stops
$completedStops = 0;
foreach ($jobs as $j) {
    if (($j['status'] ?? '') === 'completed') $completedStops++;
}

// Estimated revenue
$revStmt = $db->prepare("
    SELECT COALESCE(SUM(jp.price_per_visit), 0)
    FROM job_visits jv
    JOIN job_plans jp ON jv.plan_id = jp.id
    WHERE jv.scheduled_date = ?
      AND jv.assigned_crew_id = ?
      AND jv.status IN ('scheduled','in_progress','completed')
");
$revStmt->execute([$today, $user['id']]);
$summaryRevenue = (float)($revStmt->fetchColumn() ?: 0);

// Estimated time
$summaryMinutes = 0;
foreach ($jobs as $j) {
    $summaryMinutes += (int)($j['estimated_duration_minutes'] ?? 0);
}
$summaryMinutes += $totalStops > 0 ? (max(0, $totalStops - 1) * 8 + 10) : 0;

// ── Clock status ──────────────────────────────────────────────────────────────
// Auto-resolve any stale clock entry from a prior day before evaluating state.
resolveStaleClockEntry($user['id']);
$activeClock = getActiveClockEntry($user['id']);
$isClockedIn = (bool)$activeClock;

// Already clocked in — only redirect to homebase if pre-trip is also done.
// If pre-trip is pending, stay here so JS can auto-open the overlay.
if ($isClockedIn) {
    $tripCheckStmt = $db->prepare("SELECT pre_trip_at FROM vehicle_trip_reports WHERE driver_id = ? AND report_date = ? LIMIT 1");
    $tripCheckStmt->execute([$user['id'], $today]);
    $tripCheckRow = $tripCheckStmt->fetch(PDO::FETCH_ASSOC);
    if ($tripCheckRow && $tripCheckRow['pre_trip_at'] !== null) {
        header('Location: /crm/homebase.php');
        exit;
    }
    // Pre-trip not done yet — fall through; JS will skip splash/quiz and open the overlay
}

// ── Trip report ───────────────────────────────────────────────────────────────
$tripStmt = $db->prepare("SELECT * FROM vehicle_trip_reports WHERE driver_id = ? AND report_date = ?");
$tripStmt->execute([$user['id'], $today]);
$tripReport = $tripStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$preComplete  = $tripReport && $tripReport['pre_trip_at']  !== null;
$postComplete = $tripReport && $tripReport['post_trip_at'] !== null;
$preTime      = $preComplete  ? date('g:i a', strtotime($tripReport['pre_trip_at']))  : null;
$postTime     = $postComplete ? date('g:i a', strtotime($tripReport['post_trip_at'])) : null;

// ── Weather — hourly AM/PM split (same as schedule.php) ──────────────────────
$weatherAM = null;
$weatherPM = null;
try {
    $hourlyBlocks = getHourlyForecastByCity('Vancouver', 'BC');
    $amBlocks = []; $pmBlocks = [];
    foreach ($hourlyBlocks as $blk) {
        if (strncmp($blk['hour'], $today, 10) !== 0) continue;
        $h = (int)substr($blk['hour'], 11, 2);
        if ($h >= 6  && $h < 12) $amBlocks[] = $blk;
        elseif ($h >= 12 && $h < 17) $pmBlocks[] = $blk;
    }
    if (!empty($amBlocks)) $weatherAM = $amBlocks[intval(count($amBlocks) / 2)];
    if (!empty($pmBlocks)) $weatherPM = $pmBlocks[intval(count($pmBlocks) / 2)];
} catch (Throwable $t) { /* fall through to daily fallback */ }

// Fallback to daily forecast
$dailyWeather = [];
try { $dailyWeather = getWeatherForecast('Vancouver', 'BC', $today); } catch (Throwable $t) {}
if (!$weatherAM) {
    $weatherAM = [
        'temp_c'            => $dailyWeather['temp_low']    ?? 8,
        'condition'         => $dailyWeather['condition']   ?? 'Clear',
        'icon'              => getWeatherIcon($dailyWeather['condition'] ?? 'Clear'),
        'precip_chance_pct' => $dailyWeather['precipitation'] ?? 0,
    ];
}
if (!$weatherPM) {
    $weatherPM = [
        'temp_c'            => $dailyWeather['temp_high']   ?? 12,
        'condition'         => $dailyWeather['condition']   ?? 'Clear',
        'icon'              => getWeatherIcon($dailyWeather['condition'] ?? 'Clear'),
        'precip_chance_pct' => $dailyWeather['precipitation'] ?? 0,
    ];
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
$csrf = generateCSRFToken();

$pageTitle  = 'Driver Portal';
$activePage = 'driver';
$bodyClass  = 'mw-page-driver'; // Suppresses global mobile nav bars — driver portal has its own UI
$extraHead  = '<link href="/crm/css/mobile-cards.css?v=20260420a" rel="stylesheet">';

?>
<?php include 'includes/appstack_head.php'; ?>

<!-- ════════════════════════════════════════════════════════════════════════════
     SCREEN 0 — Logo Splash
     Auto-advances after 1.5s → quiz (if needed) or Home Base
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="dp-logo-screen" id="dpLogoScreen">
    <div class="dp-logo-inner">
        <svg class="dp-logo-leaf" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M28 6C14 6 6 18 6 28C6 40 16 50 28 50C40 50 50 40 50 28C50 16 42 6 28 6Z" fill="rgba(127,216,88,0.15)"/>
            <path d="M28 12C20 12 12 20 12 30C12 38 18 44 28 46C28 46 40 38 44 28C47 20 38 12 28 12Z" fill="rgba(127,216,88,0.3)"/>
            <path d="M28 14C19 18 16 26 18 34C22 30 28 28 34 30C36 24 34 16 28 14Z" fill="#7FD858"/>
            <path d="M28 14L26 36" stroke="#2D8659" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        <div class="dp-logo-wordmark">MOWOLOGY</div>
        <div class="dp-logo-sub">Field Operations</div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     SCREEN 1 — Pre-Shift Quiz
     Only rendered when quiz is enabled and not yet done today.
     ════════════════════════════════════════════════════════════════════════════ -->
<?php if ($preshiftEnabled && !$preshiftDone): ?>
<div class="dp-quiz-screen" id="dpQuizScreen">
    <div class="dp-quiz-inner">
        <div class="dp-quiz-header">
            <div class="mw-ps-progress" id="dpQuizProgress"></div>
            <div class="mw-ps-hdr-label">Pre-Shift Check</div>
        </div>
        <div class="mw-ps-body" id="dpQuizBody">
            <div class="mw-ps-loading">Loading quiz…</div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="dp-page" id="dpHomeBase" style="display:none">

    <!-- ══ Day Summary Card — metrics, weather, clock in ══════════════════════ -->
    <div class="mw-ds-wrap" id="dpDaySummary">

        <!-- Metrics card -->
        <div class="mw-ds-card">
            <div class="mw-ds-greeting">
                <span class="mw-ds-hi"><?php echo htmlspecialchars($dayGreeting . ', ' . $greetingFirstName); ?></span>
                <span class="mw-ds-date-lbl"><?php echo date('l, F j'); ?></span>
            </div>
            <?php if ($totalStops > 0 || $summaryRevenue > 0 || $summaryMinutes > 0): ?>
            <div class="mw-ds-metrics">
                <div class="mw-ds-metric">
                    <span class="mw-ds-mval"><?php echo $totalStops; ?></span>
                    <span class="mw-ds-mlbl"><?php echo $totalStops === 1 ? 'Stop' : 'Stops'; ?></span>
                </div>
                <?php if ($summaryRevenue > 0): ?>
                <div class="mw-ds-metric">
                    <span class="mw-ds-mval">$<?php echo number_format($summaryRevenue, 0); ?></span>
                    <span class="mw-ds-mlbl">Est. Revenue</span>
                </div>
                <?php endif; ?>
                <?php if ($summaryMinutes > 0): ?>
                <div class="mw-ds-metric">
                    <span class="mw-ds-mval"><?php echo $summaryMinutes >= 60 ? round($summaryMinutes / 60, 1) . 'h' : $summaryMinutes . 'm'; ?></span>
                    <span class="mw-ds-mlbl">Est. Time</span>
                </div>
                <?php endif; ?>
                <?php if ($completedStops > 0): ?>
                <div class="mw-ds-metric mw-ds-metric-done">
                    <span class="mw-ds-mval"><?php echo $completedStops; ?>/<?php echo $totalStops; ?></span>
                    <span class="mw-ds-mlbl">Done</span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Weather AM / PM -->
        <div class="mw-ds-weather-row">
            <div class="mw-ds-wx mw-ds-wx-am">
                <span class="mw-ds-wx-label">Morning</span>
                <span class="mw-ds-wx-icon"><?php echo $weatherAM['icon'] ?? '☀️'; ?></span>
                <span class="mw-ds-wx-temp"><?php echo round((float)($weatherAM['temp_c'] ?? 8)); ?>&deg;</span>
                <span class="mw-ds-wx-cond"><?php echo htmlspecialchars(ucfirst(strtolower($weatherAM['condition'] ?? 'Clear'))); ?></span>
                <?php if (!empty($weatherAM['precip_chance_pct']) && (int)$weatherAM['precip_chance_pct'] > 10): ?>
                <span class="mw-ds-wx-precip">💧 <?php echo (int)$weatherAM['precip_chance_pct']; ?>%</span>
                <?php endif; ?>
            </div>
            <div class="mw-ds-wx mw-ds-wx-pm">
                <span class="mw-ds-wx-label">Afternoon</span>
                <span class="mw-ds-wx-icon"><?php echo $weatherPM['icon'] ?? '⛅'; ?></span>
                <span class="mw-ds-wx-temp"><?php echo round((float)($weatherPM['temp_c'] ?? 12)); ?>&deg;</span>
                <span class="mw-ds-wx-cond"><?php echo htmlspecialchars(ucfirst(strtolower($weatherPM['condition'] ?? 'Clear'))); ?></span>
                <?php if (!empty($weatherPM['precip_chance_pct']) && (int)$weatherPM['precip_chance_pct'] > 10): ?>
                <span class="mw-ds-wx-precip">💧 <?php echo (int)$weatherPM['precip_chance_pct']; ?>%</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Hero Clock In card -->
        <div class="mw-ds-clock-card dp-clock-hero">
            <div class="mw-ds-clock-info">
                <div class="mw-ds-clock-dot"></div>
                <span class="mw-ds-clock-status">Ready to start your shift</span>
            </div>
            <button class="mw-ds-clock-btn mw-ds-clock-btn-in dp-clock-hero-btn" id="dpClockInBtn" type="button" onclick="dpClockIn()">Clock In</button>
        </div>

    </div><!-- /.mw-ds-wrap -->

    <!-- ══ Vehicle Check — only shown when returning at end of day (pre-trip already done) ══ -->
    <?php if ($preComplete): ?>
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

        <?php if (!$postComplete): ?>
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
    <?php endif; // preComplete ?>

</div><!-- /.dp-page #dpHomeBase -->

<!-- ════════════════════════════════════════════════════════════════════════════
     PRE-TRIP OVERLAY
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="dp-overlay" id="dpPreTripOverlay">
    <div class="dp-overlay-header" style="justify-content:center;">
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

        <!-- Manager PIN bypass — last resort for edge cases (office day, truck not used, etc.) -->
        <div id="dpPinBypassSection" style="margin-top:24px;padding-top:16px;border-top:1px solid rgba(0,0,0,.08);text-align:center;">
            <button type="button" onclick="dpTogglePinBypass()" style="background:none;border:none;color:#888;font-size:12px;cursor:pointer;text-decoration:underline;padding:4px 8px;">
                Skip &mdash; Manager Override
            </button>
            <div id="dpPinBypassForm" style="display:none;margin-top:12px;">
                <p style="font-size:12px;color:#666;margin-bottom:10px;">Enter the manager PIN to bypass today&rsquo;s pre-trip. This is logged.</p>
                <div style="display:flex;gap:8px;justify-content:center;align-items:center;">
                    <input type="password" id="dpManagerPin" maxlength="4" inputmode="numeric" pattern="[0-9]*"
                           style="width:90px;text-align:center;font-size:20px;letter-spacing:6px;padding:8px;border:1px solid #ccc;border-radius:6px;"
                           placeholder="••••">
                    <button type="button" onclick="dpSubmitPinBypass()"
                            style="background:#2D8659;color:#fff;border:none;border-radius:6px;padding:10px 16px;font-size:14px;cursor:pointer;">
                        Confirm
                    </button>
                </div>
                <div id="dpPinError" style="color:#c00;font-size:12px;margin-top:6px;display:none;">Incorrect PIN</div>
            </div>
        </div>
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
// Inject flatline CSS at runtime — bypasses SW/HTTP cache on device
function mwInjectFlatlineCSS() {
    if (document.getElementById('mw-flatline-css')) return;
    var s = document.createElement('style');
    s.id = 'mw-flatline-css';
    s.textContent = '@keyframes mw-flatline{0%{clip-path:inset(0% 0% 0% 0%);opacity:1;filter:none}20%{clip-path:inset(48% 0% 48% 0%);opacity:1;filter:drop-shadow(0 0 6px #7FD858) drop-shadow(0 0 20px rgba(127,216,88,.6))}65%{clip-path:inset(48% 0% 48% 0%);opacity:1;filter:drop-shadow(0 0 6px #7FD858) drop-shadow(0 0 20px rgba(127,216,88,.6))}88%{clip-path:inset(48% 0% 48% 0%);opacity:0;filter:none}100%{clip-path:inset(50% 0% 50% 0%);opacity:0;filter:none}}.mw-flatline-out{animation:mw-flatline 2.8s ease-out forwards}';
    document.head.appendChild(s);
}
var DP = {
    csrf:           <?php echo json_encode($csrf); ?>,
    isClockedIn:    <?= $isClockedIn ? 'true' : 'false' ?>,
    preComplete:    <?php echo $preComplete ? 'true' : 'false'; ?>,
    postComplete:   <?php echo $postComplete ? 'true' : 'false'; ?>,
    clockStart:     null,
    reportId:       <?php echo $tripReport ? (int)$tripReport['id'] : 'null'; ?>,
    quizEnabled:    <?php echo $preshiftEnabled ? 'true' : 'false'; ?>,
    quizDone:       <?php echo $preshiftDone ? 'true' : 'false'; ?>,
    quizLen:        <?php echo (int)$preshiftLen; ?>,
    quizSessionId:  null,
    quizCurrent:    1,
    quizCorrect:    0,
    quizStartTs:    0
};

// ── Overlay open/close ────────────────────────────────────────────────────────
function dpOpenForm(type) {
    var id = type === 'pre' ? 'dpPreTripOverlay' : 'dpPostTripOverlay';
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}
function dpCloseForm(type) {
    // Pre-trip is mandatory — cannot be dismissed without submitting or PIN bypass.
    if (type === 'pre') return;
    var id = 'dpPostTripOverlay';
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}

// Close overlays on back button — pre-trip is undismissable, only close post-trip.
window.addEventListener('popstate', function() {
    var postTrip = document.getElementById('dpPostTripOverlay');
    if (postTrip && postTrip.classList.contains('active')) {
        postTrip.classList.remove('active');
        document.body.style.overflow = '';
    }
});

// ── Manager PIN bypass ────────────────────────────────────────────────────────
function dpTogglePinBypass() {
    var form = document.getElementById('dpPinBypassForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
    if (form.style.display === 'block') {
        setTimeout(function(){ document.getElementById('dpManagerPin').focus(); }, 100);
    }
}

function dpSubmitPinBypass() {
    var pin = document.getElementById('dpManagerPin').value.trim();
    var errEl = document.getElementById('dpPinError');
    errEl.style.display = 'none';

    if (!pin || pin.length < 4) {
        errEl.textContent = 'Enter the 4-digit manager PIN';
        errEl.style.display = 'block';
        return;
    }

    fetch('/crm/api/manager-pin-verify.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({pin: pin, bypass_reason: 'manager_override', csrf_token: DP.csrf})
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.success) {
            dpToast('Pre-trip bypassed. Starting your day…');
            setTimeout(function(){ window.location.href = '/crm/homebase.php'; }, 900);
        } else {
            document.getElementById('dpManagerPin').value = '';
            errEl.textContent = data.error || 'Incorrect PIN';
            errEl.style.display = 'block';
        }
    })
    .catch(function(){
        errEl.textContent = 'Network error — try again';
        errEl.style.display = 'block';
    });
}

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
        fetch('/crm/api/time-clock.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'clock_in', lat: lat, lng: lng})
        })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.success || data.clocked_in) {
                dpToast('Clocked in! Complete your vehicle inspection.');
                // Open pre-trip form (Driver Portal Form) — redirect to schedule after completion
                setTimeout(function(){ dpOpenForm('pre'); }, 600);
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
        fetch('/crm/api/time-clock.php', {
            method: 'POST',
            credentials: 'same-origin',
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
            dpToast('Pre-trip complete! Starting your day…');
            setTimeout(function(){ window.location.href = '/crm/homebase.php'; }, 900);
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

// ── Toast ─────────────────────────────────────────────────────────────────────
function dpToast(msg, isError) {
    var el = document.getElementById('dpToast');
    el.textContent = msg;
    el.className = 'dp-toast' + (isError ? ' error' : '');
    void el.offsetWidth;
    el.classList.add('show');
    setTimeout(function(){ el.classList.remove('show'); }, 3000);
}

// ── Screen Manager ─────────────────────────────────────────────────────────────
function dpShowHomeBase() {
    var home = document.getElementById('dpHomeBase');
    if (home) { home.style.display = ''; home.classList.add('dp-screen-fadein'); }
}

// ── Logo Splash → Quiz/Home auto-advance ──────────────────────────────────────
(function dpInitFlow() {
    var logo = document.getElementById('dpLogoScreen');
    var quiz = document.getElementById('dpQuizScreen');

    // Deep-link via ?open=pre or ?open=post (time-clock widget redirects here
    // when a driver tries to clock in/out without completing the trip report).
    var openParam = (new URLSearchParams(window.location.search)).get('open');
    if (openParam === 'post' || openParam === 'pre') {
        if (logo) logo.style.display = 'none';
        dpShowHomeBase();
        setTimeout(function(){ dpOpenForm(openParam); }, 200);
        return;
    }

    // Already clocked in but pre-trip not done — skip splash/quiz, go straight to the overlay
    if (DP.isClockedIn && !DP.preComplete) {
        if (logo) logo.style.display = 'none';
        dpShowHomeBase();
        setTimeout(function(){ dpOpenForm('pre'); }, 200);
        return;
    }

    // After 1.5s, fade out the logo
    setTimeout(function() {
        if (logo) logo.classList.add('dp-logo-out');
        setTimeout(function() {
            if (logo) logo.style.display = 'none';
            // Show quiz screen if quiz is needed, otherwise home base
            if (DP.quizEnabled && !DP.quizDone && quiz) {
                quiz.style.display = 'flex';
                quiz.classList.add('dp-screen-fadein');
                dpQuizStart();
            } else {
                dpShowHomeBase();
            }
        }, 400); // match CSS transition duration
    }, 1500);
})();

// ── Pre-Shift Quiz (driver portal) ────────────────────────────────────────────
function dpQuizStart() {
    var body = document.getElementById('dpQuizBody');
    if (body) body.innerHTML = '<div class="mw-ps-loading">Loading quiz…</div>';

    fetch('/crm/api/quiz.php?action=start', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ mode: 'seasonal', session_length: DP.quizLen, csrf_token: DP.csrf })
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.success) { dpQuizError('Could not load quiz'); return; }
        DP.quizSessionId = d.session_id;
        DP.quizCurrent   = 1;
        DP.quizCorrect   = 0;
        dpQuizLoad(1);
    })
    .catch(function() { dpQuizError('Connection error'); });
}

function dpQuizProgress(qNum) {
    var out = '';
    for (var i = 1; i <= DP.quizLen; i++) {
        var cls = i < qNum ? 'done' : (i === qNum ? 'active' : '');
        out += '<span class="mw-ps-dot' + (cls ? ' ' + cls : '') + '"></span>';
    }
    var p = document.getElementById('dpQuizProgress');
    if (p) p.innerHTML = out;
}

function dpQuizLoad(qNum) {
    dpQuizProgress(qNum);
    var body = document.getElementById('dpQuizBody');
    if (body) body.innerHTML = '<div class="mw-ps-loading">Loading…</div>';

    fetch('/crm/api/quiz.php?action=question&session_id=' + DP.quizSessionId + '&q=' + qNum)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { dpQuizError(d.error); return; }
            dpQuizRender(d, qNum);
        })
        .catch(function() { dpQuizError('Connection error'); });
}

function dpQuizRender(d, qNum) {
    DP.quizStartTs = Date.now();
    var q    = d.question;
    var opts = d.options || [];
    var imgHtml = q.image_path ? '<div class="mw-ps-img-wrap"><img src="' + dpEsc(q.image_path) + '" class="mw-ps-img" alt=""></div>' : '';
    var optHtml = '<div class="mw-ps-options">';
    opts.forEach(function(o) {
        optHtml += '<button class="mw-ps-option" data-oid="' + o.id + '" data-qid="' + q.id + '" data-qnum="' + qNum + '">' + dpEsc(o.option_text) + '</button>';
    });
    optHtml += '</div>';
    var body = document.getElementById('dpQuizBody');
    if (body) {
        body.innerHTML =
            '<div class="mw-ps-cat" style="color:' + dpEsc(q.category_colour || '#2D8659') + '">' + dpEsc(q.category_name || '') + '</div>' +
            imgHtml +
            '<div class="mw-ps-qtext">' + dpEsc(q.question_text) + '</div>' +
            optHtml;
        body.querySelectorAll('.mw-ps-option').forEach(function(btn) {
            btn.addEventListener('click', function() {
                dpQuizAnswer(
                    parseInt(btn.getAttribute('data-oid')),
                    parseInt(btn.getAttribute('data-qid')),
                    parseInt(btn.getAttribute('data-qnum'))
                );
            });
        });
    }
}

function dpQuizAnswer(selectedOid, questionId, qNum) {
    var body = document.getElementById('dpQuizBody');
    if (body) body.querySelectorAll('.mw-ps-option').forEach(function(b) { b.disabled = true; });
    var taken = Math.min(30, Math.round((Date.now() - DP.quizStartTs) / 1000));

    fetch('/crm/api/quiz.php?action=answer', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            session_id: DP.quizSessionId,
            question_id: questionId,
            selected_option_id: selectedOid,
            time_taken_seconds: taken,
            csrf_token: DP.csrf
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.success) return;
        if (d.is_correct) DP.quizCorrect++;
        if (body) {
            body.querySelectorAll('.mw-ps-option').forEach(function(btn) {
                var oid = parseInt(btn.getAttribute('data-oid'));
                if (oid === d.correct_option_id) btn.classList.add('correct');
                else if (oid === selectedOid && !d.is_correct) btn.classList.add('wrong');
            });
        }
        setTimeout(function() {
            if (qNum < DP.quizLen) {
                DP.quizCurrent = qNum + 1;
                dpQuizLoad(DP.quizCurrent);
            } else {
                dpQuizSummary();
            }
        }, 1200);
    })
    .catch(function() { setTimeout(function() { dpQuizSummary(); }, 1200); });
}

function dpQuizSummary() {
    var pct   = Math.round(DP.quizCorrect / DP.quizLen * 100);
    var emoji = pct === 100 ? '🌟' : pct >= 67 ? '👍' : '💪';
    var msg   = pct === 100 ? 'Perfect score!' : pct >= 67 ? 'Great work!' : 'Keep learning!';
    var body  = document.getElementById('dpQuizBody');
    if (body) {
        body.innerHTML =
            '<div class="mw-ps-summary">' +
                '<div class="mw-ps-sum-emoji">' + emoji + '</div>' +
                '<div class="mw-ps-sum-score">' + DP.quizCorrect + ' / ' + DP.quizLen + ' correct</div>' +
                '<div class="mw-ps-sum-msg">' + msg + '</div>' +
                '<button class="btn mw-ps-done-btn" id="dpQuizDoneBtn">Start your day →</button>' +
            '</div>';
        document.getElementById('dpQuizDoneBtn').addEventListener('click', dpQuizComplete);
    }
}

function dpQuizComplete() {
    var btn = document.getElementById('dpQuizDoneBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }

    fetch('/crm/api/quiz.php?action=preshift_complete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            session_id: DP.quizSessionId,
            questions_correct: DP.quizCorrect,
            questions_asked: DP.quizLen,
            csrf_token: DP.csrf
        })
    })
    .then(function(r) { return r.json(); })
    .then(function() { dpQuizUnlock(); })
    .catch(function() { dpQuizUnlock(); });
}

function dpQuizUnlock() {
    var quiz = document.getElementById('dpQuizScreen');
    if (quiz) {
        quiz.classList.add('dp-screen-fadeout');
        setTimeout(function() {
            quiz.style.display = 'none';
            dpShowHomeBase();
        }, 400);
    } else {
        dpShowHomeBase();
    }
}

function dpEsc(str) {
    var d = document.createElement('div');
    d.textContent = String(str || '');
    return d.innerHTML;
}

function dpQuizError(msg) {
    var body = document.getElementById('dpQuizBody');
    if (body) body.innerHTML = '<div class="mw-ps-loading" style="color:#dc3545">Error: ' + dpEsc(msg) + ' — <button onclick="dpQuizStart()" style="color:var(--mw-green);background:none;border:none;cursor:pointer;text-decoration:underline;">Retry</button></div>';
}
</script>

<?php include 'includes/appstack_footer.php'; ?>
