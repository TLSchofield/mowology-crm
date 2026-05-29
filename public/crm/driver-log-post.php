<?php
/**
 * Driver Log (Post-Trip) — End-of-shift vehicle check for driver users.
 *
 * Flow: homebase.php [tap Clock Out] → driver-log-post.php → submit → clock out → app-launch.php
 *
 * If the post-trip is already completed today, redirect straight to homebase
 * (the homebase clock-out gate will then pass through to the normal clock-out API).
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

// Must be clocked in — no clock-out means no post-trip
$activeClock = getActiveClockEntry($user['id']);
if (!$activeClock) {
    header('Location: /crm/app-launch.php');
    exit;
}

$db    = getDB();
$today = date('Y-m-d');

// Multi-trip-per-day state machine. The post-trip form ALWAYS targets
// "the current open trip" — the most recent row where pre_trip_at is
// set but post_trip_at is not. If no such row exists, the driver
// either hasn't started any trip today (send them to pre-trip) or
// has already closed the latest one (back to homebase — they're clear
// to clock out).
$tripStmt = $db->prepare("
    SELECT * FROM vehicle_trip_reports
    WHERE driver_id = ?
      AND report_date = ?
      AND pre_trip_at IS NOT NULL
      AND post_trip_at IS NULL
    ORDER BY id DESC
    LIMIT 1
");
$tripStmt->execute([$user['id'], $today]);
$tripReport = $tripStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$tripReport) {
    // Either no trip is open, or the driver never filed a pre-trip
    // today. Check which: if no rows at all, send to pre-trip first.
    // Otherwise send home — the latest trip is already closed.
    $anyRow = $db->prepare("
        SELECT id FROM vehicle_trip_reports
        WHERE driver_id = ? AND report_date = ?
        ORDER BY id DESC LIMIT 1
    ");
    $anyRow->execute([$user['id'], $today]);
    if ($anyRow->fetchColumn()) {
        header('Location: /crm/homebase.php');
    } else {
        header('Location: /crm/driver-log.php');
    }
    exit;
}

$csrf      = generateCSRFToken();
$firstName = $user['first_name'] ?? explode(' ', trim($user['full_name'] ?? 'Driver'))[0];
session_write_close();
?>
<!-- driver-log-post v20260410 -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0D3B2E">
    <title>Post-Trip Log — Mowology</title>
    <link rel="manifest" href="/assets/favicon/site.webmanifest">
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

        /* ── Info banner ─────────────────────────────────────────── */
        .dl-info {
            background: var(--dl-light);
            border-left: 3px solid var(--dl-green);
            border-radius: 0 8px 8px 0;
            padding: 10px 12px;
            font-size: 12px; color: var(--dl-dark); line-height: 1.5;
        }
        .dl-info strong { display: block; margin-bottom: 3px; color: var(--dl-forest); }

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
            <div class="dl-header-title">Post-Trip Inspection</div>
            <div class="dl-header-sub">RAM 3500 PF8865 &bull; <?php echo htmlspecialchars(date('M j, Y')); ?></div>
        </div>
    </div>

    <div class="dl-scroll">
        <form id="dlPostTripForm" onsubmit="dlSubmit(event)">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="save_post_trip">

            <!-- ── Info ────────────────────────────────────────── -->
            <div class="dl-info">
                <strong>End of shift, <?php echo htmlspecialchars($firstName); ?></strong>
                Complete this form to clock out. Your truck stays logged until you finish.
            </div>

            <!-- ── Odometer ────────────────────────────────────── -->
            <div class="dl-card">
                <div class="dl-card-header">
                    <div class="dl-card-title">Odometer</div>
                </div>
                <div class="dl-card-body">
                    <div class="dl-field">
                        <label class="dl-label" for="dlOdomEnd">End of Day (km)</label>
                        <input class="dl-input" type="number" id="dlOdomEnd" name="odometer_end"
                               min="0" max="999999" placeholder="e.g. 48640"
                               value="<?php echo htmlspecialchars((string)($tripReport['odometer_end'] ?? '')); ?>">
                    </div>
                </div>
            </div>

            <!-- ── End of Day Remarks ──────────────────────────── -->
            <div class="dl-card">
                <div class="dl-card-header">
                    <div class="dl-card-title">End of Day Remarks &amp; Defect Report</div>
                </div>
                <div class="dl-card-body">
                    <div class="dl-field">
                        <label class="dl-label">
                            Remarks <small>(if none, leave blank)</small>
                        </label>
                        <textarea class="dl-textarea" name="end_of_day_remarks" rows="4"
                                  placeholder="Any issues, incidents, or observations from today"><?php echo htmlspecialchars((string)($tripReport['end_of_day_remarks'] ?? '')); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- ── Hours of Service ────────────────────────────── -->
            <div class="dl-card">
                <div class="dl-card-header">
                    <div class="dl-card-title">Hours of Service</div>
                    <div class="dl-card-sub">Bundled to equal 24 hrs (midnight to midnight)</div>
                </div>
                <div class="dl-card-body">
                    <div class="dl-field">
                        <label class="dl-label">On-Duty Driving Time — Start to Finish</label>
                        <input class="dl-input" type="text" name="hos_on_duty_driving"
                               placeholder="e.g. 7:00am – 4:30pm (9.5 hrs)"
                               value="<?php echo htmlspecialchars((string)($tripReport['hos_on_duty_driving'] ?? '')); ?>">
                    </div>
                    <div class="dl-field">
                        <label class="dl-label">On-Duty Other</label>
                        <input class="dl-input" type="text" name="hos_on_duty_other"
                               placeholder="e.g. 0.5 hrs (loading/unloading)"
                               value="<?php echo htmlspecialchars((string)($tripReport['hos_on_duty_other'] ?? '')); ?>">
                    </div>
                    <div class="dl-field">
                        <label class="dl-label">Off-Duty</label>
                        <input class="dl-input" type="text" name="hos_off_duty"
                               placeholder="e.g. 14 hrs"
                               value="<?php echo htmlspecialchars((string)($tripReport['hos_off_duty'] ?? '')); ?>">
                    </div>
                </div>
            </div>

        </form>
    </div><!-- /.dl-scroll -->

    <!-- ── Submit Button (outside scroll to stay visible) ──────────────────── -->
    <div class="dl-submit-wrap">
        <button class="dl-submit-btn" id="dlSubmitBtn" onclick="dlSubmit(event)" type="button">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Submit Post-Trip &amp; Clock Out
        </button>
    </div>

</div><!-- /.dl-page -->

<div class="dl-toast" id="dlToast" role="alert" aria-live="assertive" aria-atomic="true"></div>

<script>
(function () {
    'use strict';

    var CSRF = <?php echo json_encode($csrf); ?>;

    // ── Toast ──────────────────────────────────────────────────────
    window.dlToast = function (msg, isError) {
        var el = document.getElementById('dlToast');
        el.textContent = msg;
        el.className = 'dl-toast' + (isError ? ' error' : '');
        void el.offsetWidth;
        el.classList.add('show');
        setTimeout(function () { el.classList.remove('show'); }, 3500);
    };

    function resetBtn() {
        var btn = document.getElementById('dlSubmitBtn');
        btn.disabled = false;
        btn.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Submit Post-Trip &amp; Clock Out';
    }

    // ── Clock out after post-trip is saved ────────────────────────
    function clockOut(lat, lng) {
        fetch('/crm/api/time-clock.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'clock_out', csrf_token: CSRF, lat: lat, lng: lng })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success || !d.clocked_in) {
                // Stop the native foreground GPS service on clock-out.
                try {
                    if (window.MwNative && window.MwNative.geo) {
                        window.MwNative.geo.stopBackgroundTracking();
                    }
                } catch (e) { /* non-critical */ }
                if (window.MwHaptics) window.MwHaptics.success();
                dlToast('Clocked out. Good work today! 👊');
                setTimeout(function () { window.location.href = '/crm/app-launch.php'; }, 1000);
            } else {
                dlToast(d.error || 'Clock out failed — try again', true);
                resetBtn();
            }
        })
        .catch(function () {
            dlToast('Network error on clock-out — try again', true);
            resetBtn();
        });
    }

    function clockOutWithGeo() {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function (p) { clockOut(p.coords.latitude, p.coords.longitude); },
                function ()  { clockOut(null, null); },
                { timeout: 5000 }
            );
        } else {
            clockOut(null, null);
        }
    }

    // ── Submit post-trip, then clock out ──────────────────────────
    window.dlSubmit = function (e) {
        if (e) e.preventDefault();

        var btn = document.getElementById('dlSubmitBtn');
        btn.disabled = true;
        btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Saving…';

        var form = document.getElementById('dlPostTripForm');
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
                dlToast('Post-trip saved — clocking out…');
                clockOutWithGeo();
            } else {
                dlToast(res.error || 'Save failed — try again', true);
                resetBtn();
            }
        })
        .catch(function () {
            dlToast('Network error — check connection', true);
            resetBtn();
        });
    };
}());
</script>
</body>
</html>
