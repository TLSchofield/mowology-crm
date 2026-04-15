<?php
/**
 * Driver Log — Pre-trip inspection form for driver users.
 *
 * Shown BEFORE homebase.php for users with is_driver = true.
 * Flow: app-launch → [clock in] → driver-log.php → homebase.php
 *
 * If the pre-trip is already completed today, redirect straight to homebase.
 * Post-trip is handled from the menu in homebase (driver-portal.php legacy).
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();

// Only designated drivers need this form
if (empty($user['is_driver'])) {
    header('Location: /crm/homebase.php');
    exit;
}

// Must be clocked in
$activeClock = getActiveClockEntry($user['id']);
if (!$activeClock) {
    header('Location: /crm/app-launch.php');
    exit;
}

$db    = getDB();
$today = date('Y-m-d');

// Multi-trip-per-day state machine:
//   • no rows for today OR latest row is fully closed → this is a NEW
//     trip; render an empty pre-trip form (save will INSERT a new row
//     with the next trip_sequence).
//   • latest row has pre_trip_at set AND post_trip_at IS NULL →
//     there's already an open trip whose pre-trip is complete; send
//     the driver to homebase (they're between pre and post).
//   • (legacy) latest row has no pre_trip_at yet → pre-fill any
//     partially-saved fields so the driver can resume.
$tripStmt = $db->prepare("
    SELECT * FROM vehicle_trip_reports
    WHERE driver_id = ? AND report_date = ?
    ORDER BY id DESC
    LIMIT 1
");
$tripStmt->execute([$user['id'], $today]);
$latestRow = $tripStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$latestIsOpen   = $latestRow && !empty($latestRow['pre_trip_at']) && empty($latestRow['post_trip_at']);
$latestIsClosed = $latestRow && !empty($latestRow['pre_trip_at']) && !empty($latestRow['post_trip_at']);

if ($latestIsOpen) {
    // Current trip's pre-trip already done — nothing to fill in here.
    header('Location: /crm/homebase.php');
    exit;
}

// Render an empty form for new trips (whether this is trip #1 or the
// next trip in a split-shift day). Legacy in-progress rows (pre_trip_at
// NULL) still pre-fill their partial state.
$tripReport = $latestIsClosed ? null : $latestRow;

$csrf      = generateCSRFToken();
$firstName = $user['first_name'] ?? explode(' ', trim($user['full_name'] ?? 'Driver'))[0];
session_write_close();
?>
<!-- driver-log v20260317 -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0D3B2E">
    <title>Pre-Trip Log — Mowology</title>
    <link rel="icon" href="/assets/favicon/favicon.ico">
    <link rel="apple-touch-icon" href="/assets/favicon/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/crm/css/tokens.css?v=20260410a" rel="stylesheet">
    <link href="/crm/css/mw-sync-status.css?v=20260410a" rel="stylesheet">
    <script src="/crm/js/sw-register.js?v=20260410a" defer></script>
    <script src="/crm/js/mw-sync-status.js?v=20260410a" defer></script>
    <script src="/crm/js/mw-haptics.js?v=20260410a" defer></script>
    <style>
        /* Brand + layout tokens come from /crm/css/tokens.css loaded
           above. Backward-compat --dl-* aliases are defined there. */

        * { margin: 0; padding: 0; box-sizing: border-box; }

        /* Accessibility — visible focus rings */
        :where(a, button, input, textarea, select, [role="button"], [tabindex]):focus-visible {
            outline: 2px solid var(--dl-green);
            outline-offset: 2px;
            border-radius: 4px;
        }

        html, body {
            height: 100%;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--dl-bg);
            color: var(--dl-text);
            -webkit-font-smoothing: antialiased;
            overscroll-behavior: none;
        }

        /* ── Layout ──────────────────────────────────────────────── */
        .dl-page {
            min-height: 100dvh;
            display: flex; flex-direction: column;
        }

        /* ── Header ──────────────────────────────────────────────── */
        .dl-header {
            background: var(--dl-forest);
            padding: 14px 20px;
            padding-top: calc(14px + var(--dl-safe-top));
            display: flex; align-items: center; gap: 14px;
            position: sticky; top: 0; z-index: 10;
        }
        .dl-header-back {
            width: 36px; height: 36px;
            display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,0.1); border-radius: 10px;
            border: none; color: #fff; cursor: pointer;
            flex-shrink: 0;
        }
        .dl-header-back svg { stroke: #fff; }
        .dl-header-info { flex: 1; }
        .dl-header-title { color: #fff; font-weight: 700; font-size: 17px; letter-spacing: -0.3px; }
        .dl-header-sub   { color: rgba(255,255,255,0.55); font-size: 12px; margin-top: 1px; }

        /* ── Scroll body ─────────────────────────────────────────── */
        .dl-scroll {
            flex: 1;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 16px 14px calc(24px + var(--dl-safe-bottom));
            display: flex; flex-direction: column; gap: 12px;
        }

        /* ── Cards ───────────────────────────────────────────────── */
        .dl-card {
            background: var(--dl-card);
            border-radius: var(--dl-radius);
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .dl-card-header {
            padding: 14px 16px 10px;
            border-bottom: 1px solid var(--dl-border);
        }
        .dl-card-title {
            font-size: 13px; font-weight: 700;
            color: var(--dl-text); letter-spacing: -0.1px;
        }
        .dl-card-sub {
            font-size: 11px; color: var(--dl-muted); margin-top: 2px;
        }
        .dl-card-body { padding: 14px 16px; }

        /* ── Checklist items ─────────────────────────────────────── */
        .dl-check-item {
            display: flex; align-items: center; gap: 12px;
            min-height: 48px; /* WCAG AAA touch target */
            padding: 12px 16px;
            border-bottom: 1px solid var(--dl-border);
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }
        .dl-check-item:last-child { border-bottom: none; }
        .dl-check-item input[type="checkbox"] { display: none; }
        .dl-check-box {
            width: 24px; height: 24px; flex-shrink: 0;
            border: 2px solid var(--dl-border);
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.15s, border-color 0.15s;
        }
        .dl-check-box svg { display: none; stroke: #fff; }
        .dl-check-item input:checked ~ .dl-check-box {
            background: var(--dl-green); border-color: var(--dl-green);
        }
        .dl-check-item input:checked ~ .dl-check-box svg { display: block; }
        .dl-check-label { font-size: 14px; font-weight: 500; color: var(--dl-text); flex: 1; }

        /* ── Fields ──────────────────────────────────────────────── */
        .dl-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .dl-field:last-child { margin-bottom: 0; }
        .dl-label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; color: var(--dl-muted);
        }
        .dl-label small { text-transform: none; font-weight: 400; font-size: 11px; }
        .dl-input, .dl-textarea {
            width: 100%;
            border: 1.5px solid var(--dl-border);
            border-radius: 10px;
            padding: 10px 12px;
            font-family: inherit; font-size: 16px; color: var(--dl-text);
            background: #fff;
            transition: border-color 0.15s;
            -webkit-appearance: none;
        }
        .dl-input:focus, .dl-textarea:focus {
            outline: none; border-color: var(--dl-green);
        }
        .dl-textarea { resize: vertical; min-height: 80px; }

        /* ── Safe-to-drive ack ───────────────────────────────────── */
        .dl-ack-box {
            border: 2px solid var(--dl-border);
            border-radius: 12px;
            padding: 14px;
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
            -webkit-tap-highlight-color: transparent;
        }
        .dl-ack-box.checked {
            border-color: var(--dl-green);
            background: var(--dl-light);
        }
        .dl-ack-text {
            font-size: 13px; line-height: 1.5; color: var(--dl-muted); margin-bottom: 12px;
        }
        .dl-ack-confirm {
            display: flex; align-items: center; gap: 10px;
        }
        .dl-ack-indicator {
            width: 22px; height: 22px; flex-shrink: 0;
            border: 2px solid var(--dl-border);
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.15s, border-color 0.15s;
        }
        .dl-ack-box.checked .dl-ack-indicator {
            background: var(--dl-green); border-color: var(--dl-green);
        }
        .dl-ack-confirm-label {
            font-size: 14px; font-weight: 600;
            color: var(--dl-text);
        }

        /* ── MVA legal notice ────────────────────────────────────── */
        .dl-legal {
            background: #F9FAFB;
            border-left: 3px solid var(--dl-green);
            border-radius: 0 8px 8px 0;
            padding: 10px 12px;
            font-size: 11px; color: var(--dl-muted); line-height: 1.5;
        }
        .dl-legal strong { color: var(--dl-dark); display: block; margin-bottom: 3px; }

        /* ── Submit ──────────────────────────────────────────────── */
        .dl-submit-wrap {
            padding: 0 14px calc(16px + var(--dl-safe-bottom));
        }
        .dl-submit-btn {
            width: 100%;
            background: linear-gradient(135deg, var(--dl-green), var(--dl-dark));
            color: #fff;
            border: none; border-radius: 14px;
            padding: 16px 24px;
            font-family: inherit; font-size: 16px; font-weight: 700;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            transition: opacity 0.15s;
        }
        .dl-submit-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .dl-submit-btn svg { stroke: #fff; }

        /* ── Validation states ───────────────────────────────────── */
        .dl-card--invalid .dl-card-header {
            border-left: 4px solid var(--mw-color-danger);
        }
        .dl-card--invalid .dl-card-title { color: var(--mw-color-danger); }

        @keyframes dl-shake {
            0%,100% { transform: translateX(0); }
            20%      { transform: translateX(-6px); }
            40%      { transform: translateX(6px); }
            60%      { transform: translateX(-4px); }
            80%      { transform: translateX(4px); }
        }
        .dl-shake { animation: dl-shake 0.35s ease; }

        .dl-input--error {
            border-color: var(--mw-color-danger) !important;
            background: var(--mw-color-danger-bg);
        }
        .dl-field-error {
            font-size: 11px; color: var(--mw-color-danger); font-weight: 600;
            display: none; margin-top: 3px;
        }
        .dl-field-error.visible { display: block; }

        .dl-check-counter {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 46px; height: 22px; border-radius: 11px;
            font-size: 11px; font-weight: 700;
            background: var(--dl-light); color: var(--dl-muted);
            transition: background 0.2s, color 0.2s;
            float: right;
        }
        .dl-check-counter.done { background: #D1FAE5; color: #065F46; }
        .dl-check-counter.partial { background: #FEF3C7; color: #92400E; }

        .dl-submit-btn:disabled {
            background: #9CA3AF; cursor: not-allowed; opacity: 1;
        }
        .dl-submit-hint {
            text-align: center; font-size: 12px; color: var(--dl-muted);
            margin-top: 8px; min-height: 16px;
        }

        /* ── Toast ───────────────────────────────────────────────── */
        .dl-toast {
            position: fixed;
            bottom: calc(20px + var(--dl-safe-bottom));
            left: 50%; transform: translateX(-50%) translateY(20px);
            background: var(--dl-forest); color: #fff;
            padding: 10px 18px; border-radius: 20px;
            font-size: 13px; font-weight: 500;
            opacity: 0; transition: opacity 0.2s, transform 0.2s;
            pointer-events: none; z-index: 300; white-space: nowrap;
        }
        .dl-toast.show  { opacity: 1; transform: translateX(-50%) translateY(0); }
        .dl-toast.error { background: var(--mw-color-danger); }

        /* Required label badge — replaces inline style="color:#DC2626" */
        .dl-required-badge {
            color: var(--mw-color-danger);
            font-size: 12px;
            font-weight: 400;
            margin-left: 4px;
        }
    </style>
</head>
<body>
<div class="dl-page">

    <!-- ── Header ──────────────────────────────────────────────────────────── -->
    <div class="dl-header">
        <button class="dl-header-back" onclick="history.back()" aria-label="Back">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        </button>
        <div class="dl-header-info">
            <div class="dl-header-title">Pre-Trip Inspection</div>
            <div class="dl-header-sub">RAM 3500 PF8865 &bull; <?php echo htmlspecialchars(date('M j, Y')); ?></div>
        </div>
    </div>

    <div class="dl-scroll">
        <form id="dlPreTripForm" onsubmit="dlSubmit(event)">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="save_pre_trip">

            <!-- ── Running Order Checklist ─────────────────────── -->
            <div class="dl-card" id="dlCheckCard">
                <div class="dl-card-header">
                    <div class="dl-card-title">
                        Running Order Checklist
                        <span class="dl-check-counter" id="dlCheckCounter">0 / <?php echo count($checks); ?></span>
                    </div>
                    <div class="dl-card-sub">All items required before departure</div>
                </div>
                <?php
                $checks = [
                    'chk_leaks'          => 'Check beneath truck for leaks',
                    'chk_hitch'          => 'Hitch secure & light plug attached',
                    'chk_rear_gate'      => 'Rear truck gate secure',
                    'chk_loads_secure'   => 'Loads secure',
                    'chk_trailer_lights' => 'Trailer lights',
                    'chk_truck_brakes'   => 'Truck brakes',
                    'chk_truck_lights'   => 'Truck lights',
                    'chk_mirrors'        => 'Mirrors',
                    'chk_tire_pressure'  => 'Tire pressure',
                    'chk_washer_wipers'  => 'Washer fluids & wipers',
                ];
                foreach ($checks as $name => $label):
                    $checked = ($tripReport && !empty($tripReport[$name])) ? 'checked' : '';
                ?>
                <label class="dl-check-item">
                    <input type="checkbox" name="<?php echo htmlspecialchars($name); ?>" aria-label="<?php echo htmlspecialchars($label); ?>" <?php echo $checked; ?>>
                    <div class="dl-check-box" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <span class="dl-check-label"><?php echo htmlspecialchars($label); ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- ── Odometer ────────────────────────────────────── -->
            <div class="dl-card" id="dlOdomCard">
                <div class="dl-card-header">
                    <div class="dl-card-title">Odometer <span class="dl-required-badge">Required</span></div>
                </div>
                <div class="dl-card-body">
                    <div class="dl-field">
                        <label class="dl-label" for="dlOdomStart">Start of Day (km)</label>
                        <input class="dl-input" type="number" id="dlOdomStart" name="odometer_start"
                               min="1" max="999999" placeholder="e.g. 48250"
                               value="<?php echo htmlspecialchars((string)($tripReport['odometer_start'] ?? '')); ?>">
                        <div class="dl-field-error" id="dlOdomError">Odometer reading is required</div>
                    </div>
                </div>
            </div>

            <!-- ── Defects & Repairs ───────────────────────────── -->
            <div class="dl-card">
                <div class="dl-card-header">
                    <div class="dl-card-title">Defects & Repairs</div>
                </div>
                <div class="dl-card-body">
                    <div class="dl-field">
                        <label class="dl-label">
                            Must be fixed before safe to drive
                            <small>&nbsp;— contact Vital Auto Repair & office immediately</small>
                        </label>
                        <textarea class="dl-textarea" name="defects_critical"
                                  placeholder="Describe critical defects, or leave blank if none"><?php echo htmlspecialchars((string)($tripReport['defects_critical'] ?? '')); ?></textarea>
                    </div>

                    <label class="dl-check-item" style="border-radius:10px;border:1.5px solid var(--dl-border);margin-bottom:10px;">
                        <input type="checkbox" name="defect_unhitch"
                               <?php echo ($tripReport && !empty($tripReport['defect_unhitch'])) ? 'checked' : ''; ?>>
                        <div class="dl-check-box">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <span class="dl-check-label">Unhitch trailer</span>
                    </label>

                    <div class="dl-field">
                        <label class="dl-label">Non-urgent defects / repairs</label>
                        <textarea class="dl-textarea" name="defects_non_urgent" rows="2"
                                  placeholder="Describe non-urgent issues, or leave blank"><?php echo htmlspecialchars((string)($tripReport['defects_non_urgent'] ?? '')); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- ── Safe to Drive Declaration ─────────────────────── -->
            <div class="dl-card">
                <div class="dl-card-header">
                    <div class="dl-card-title">Safe to Drive Declaration</div>
                </div>
                <div class="dl-card-body">
                    <div class="dl-ack-box <?php echo ($tripReport && !empty($tripReport['safe_to_drive'])) ? 'checked' : ''; ?>"
                         id="dlAckBox" onclick="dlToggleAck()">
                        <div class="dl-ack-text">
                            By ticking this box and submitting you acknowledge that you take full legal
                            responsibility for the truck being safe to drive on Canadian highways.
                        </div>
                        <div class="dl-ack-confirm">
                            <div class="dl-ack-indicator" id="dlAckIndicator">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" id="dlAckCheck" style="display:none"><polyline points="20 6 9 17 4 12"/></svg>
                            </div>
                            <span class="dl-ack-confirm-label" id="dlAckLabel">Safe to Drive — I confirm</span>
                        </div>
                    </div>
                    <input type="hidden" name="safe_to_drive" id="dlSafeToDriveInput"
                           value="<?php echo ($tripReport && !empty($tripReport['safe_to_drive'])) ? '1' : '0'; ?>">
                </div>
            </div>

            <!-- ── MVA Legal ───────────────────────────────────── -->
            <div class="dl-legal">
                <strong>MVA 37.18.01b) — Cycle 1 — Within 160 Km</strong>
                (a) The driver operates within a radius of 160 km of the home terminal,
                (b) The driver returns to the home terminal each day to begin a minimum of 8 consecutive hours of off-duty time, and
                (c) The carrier maintains accurate and legible records for a minimum period of 6 months.
            </div>

        </form>
    </div><!-- /.dl-scroll -->

    <!-- ── Submit Button (outside scroll to stay visible) ──────────────────── -->
    <div class="dl-submit-wrap">
        <button class="dl-submit-btn" id="dlSubmitBtn" onclick="dlSubmit(event)" type="button" disabled>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Save & Continue to Home Base
        </button>
        <div class="dl-submit-hint" id="dlSubmitHint"></div>
    </div>

</div><!-- /.dl-page -->

<div class="dl-toast" id="dlToast" role="alert" aria-live="assertive" aria-atomic="true"></div>

<script>
(function () {
    'use strict';

    var TOTAL_CHECKS = <?php echo count($checks); ?>;

    // ── Validation state ───────────────────────────────────────────
    function dlValidate() {
        var issues = [];

        // 1. Checklist — all boxes must be checked
        var checkboxes = document.querySelectorAll('#dlCheckCard input[type="checkbox"]');
        var checked = 0;
        checkboxes.forEach(function (cb) { if (cb.checked) checked++; });
        var counter   = document.getElementById('dlCheckCounter');
        var checkCard = document.getElementById('dlCheckCard');
        counter.textContent = checked + ' / ' + TOTAL_CHECKS;
        counter.className = 'dl-check-counter' + (checked === TOTAL_CHECKS ? ' done' : (checked > 0 ? ' partial' : ''));
        if (checked < TOTAL_CHECKS) {
            checkCard.classList.add('dl-card--invalid');
            issues.push((TOTAL_CHECKS - checked) + ' checklist item' + (TOTAL_CHECKS - checked !== 1 ? 's' : '') + ' unchecked');
        } else {
            checkCard.classList.remove('dl-card--invalid');
        }

        // 2. Odometer — must be a positive number
        var odom     = document.getElementById('dlOdomStart');
        var odomCard = document.getElementById('dlOdomCard');
        var odomErr  = document.getElementById('dlOdomError');
        var odomVal  = parseInt(odom.value, 10);
        if (!odom.value.trim() || isNaN(odomVal) || odomVal < 1) {
            odomCard.classList.add('dl-card--invalid');
            odom.classList.add('dl-input--error');
            odomErr.classList.add('visible');
            issues.push('odometer reading missing');
        } else {
            odomCard.classList.remove('dl-card--invalid');
            odom.classList.remove('dl-input--error');
            odomErr.classList.remove('visible');
        }

        // 3. Safe to drive declaration
        var safe     = document.getElementById('dlSafeToDriveInput');
        var ackCard  = document.getElementById('dlAckBox').closest('.dl-card');
        if (!safe || safe.value !== '1') {
            ackCard.classList.add('dl-card--invalid');
            issues.push('safe to drive declaration');
        } else {
            ackCard.classList.remove('dl-card--invalid');
        }

        // Update button & hint
        var btn  = document.getElementById('dlSubmitBtn');
        var hint = document.getElementById('dlSubmitHint');
        if (issues.length === 0) {
            btn.disabled = false;
            hint.textContent = '';
        } else {
            btn.disabled = true;
            hint.textContent = 'Required: ' + issues.join(' · ');
        }

        return issues.length === 0;
    }

    // ── Safe-to-drive toggle ───────────────────────────────────────
    window.dlToggleAck = function () {
        var box   = document.getElementById('dlAckBox');
        var input = document.getElementById('dlSafeToDriveInput');
        var label = document.getElementById('dlAckLabel');
        var chk   = document.getElementById('dlAckCheck');
        var on    = box.classList.toggle('checked');
        input.value = on ? '1' : '0';
        label.textContent = on ? 'Safe to Drive — Confirmed ✓' : 'Safe to Drive — I confirm';
        if (chk) chk.style.display = on ? 'block' : 'none';
        dlValidate();
    };

    // Restore check icon on load if pre-confirmed
    (function () {
        var box = document.getElementById('dlAckBox');
        var chk = document.getElementById('dlAckCheck');
        if (box && box.classList.contains('checked') && chk) chk.style.display = 'block';
    }());

    // ── Live listeners ─────────────────────────────────────────────
    document.querySelectorAll('#dlCheckCard input[type="checkbox"]').forEach(function (cb) {
        cb.addEventListener('change', dlValidate);
    });
    document.getElementById('dlOdomStart').addEventListener('input', dlValidate);

    // ── Submit ─────────────────────────────────────────────────────
    window.dlSubmit = function (e) {
        if (e) e.preventDefault();

        if (!dlValidate()) {
            // Scroll to first invalid card and shake it
            var firstInvalid = document.querySelector('.dl-card--invalid');
            if (firstInvalid) {
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstInvalid.classList.remove('dl-shake');
                void firstInvalid.offsetWidth; // reflow to restart animation
                firstInvalid.classList.add('dl-shake');
                firstInvalid.addEventListener('animationend', function () {
                    firstInvalid.classList.remove('dl-shake');
                }, { once: true });
            }
            return;
        }

        var btn = document.getElementById('dlSubmitBtn');
        btn.disabled = true;
        btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Saving…';

        var form = document.getElementById('dlPreTripForm');
        var data = new FormData(form);

        fetch('/crm/api/trip-report.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                if (window.MwHaptics) window.MwHaptics.success();
                dlToast('Pre-trip saved — starting your day!');
                setTimeout(function () { window.location.href = '/crm/homebase.php'; }, 800);
            } else {
                dlToast(res.error || 'Save failed — try again', true);
                btn.disabled = false;
                btn.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Save & Continue to Home Base';
                dlValidate();
            }
        })
        .catch(function () {
            dlToast('Network error — check connection', true);
            btn.disabled = false;
            btn.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Save & Continue to Home Base';
            dlValidate();
        });
    };

    // ── Toast ──────────────────────────────────────────────────────
    window.dlToast = function (msg, isError) {
        var el = document.getElementById('dlToast');
        el.textContent = msg;
        el.className = 'dl-toast' + (isError ? ' error' : '');
        void el.offsetWidth;
        el.classList.add('show');
        setTimeout(function () { el.classList.remove('show'); }, 3500);
    };

    // ── Initial state ──────────────────────────────────────────────
    dlValidate();
}());
</script>
</body>
</html>
