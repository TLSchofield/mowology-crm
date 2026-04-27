<?php
/**
 * Homebase — Post-clock-in landing screen for all users.
 *
 * Shows: day summary, weather (AM/PM), memory card (seasonal plant tip), bottom nav.
 * Only accessible when clocked in — redirects to app-launch.php if not clocked in.
 *
 * Flow:
 *   Regular crew  →  app-launch → [clock in] → homebase.php
 *   Driver users  →  app-launch → [clock in] → driver-log.php → homebase.php
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
require_once CRM_INCLUDES . '/weather-service.php';

requireLogin();
$user = getCurrentUser();

// Must be clocked in — redirect to launch if not
$activeClock = getActiveClockEntry($user['id']);
if (!$activeClock) {
    header('Location: /crm/app-launch.php');
    exit;
}

$db    = getDB();
$today = date('Y-m-d');

// Release the session file lock before the page's DB queries (weather,
// memory card, revenue, jobs, post-trip check). Generate CSRF first so
// session_start's token survives. See schedule.php comment for the
// WorkManager race we're avoiding.
$csrf = generateCSRFToken();
session_write_close();

// ── Clock elapsed ─────────────────────────────────────────────────────────────
$clockInTs    = strtotime($activeClock['clock_in']);
$clockElapsed = (int)(time() - $clockInTs);
$clockHours   = (int)floor($clockElapsed / 3600);
$clockMins    = (int)floor(($clockElapsed % 3600) / 60);
$clockDisplay = $clockHours > 0
    ? sprintf('%dh %02dm', $clockHours, $clockMins)
    : sprintf('%dm', $clockMins);

// ── Greeting ──────────────────────────────────────────────────────────────────
$hour        = (int)date('G');
$dayGreeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName   = $user['first_name'] ?? explode(' ', trim($user['full_name'] ?? 'Team'))[0];

// ── Day summary — this user's assigned stops ──────────────────────────────────
$jobs           = getUserJobsForDate($user['id'], $today);
$totalStops     = count($jobs);
$completedStops = 0;
$summaryMinutes = 0;
foreach ($jobs as $j) {
    if (($j['status'] ?? '') === 'completed') $completedStops++;
    $summaryMinutes += (int)($j['estimated_duration_minutes'] ?? 0);
}
// Add travel buffer between stops
$summaryMinutes += $totalStops > 0 ? (max(0, $totalStops - 1) * 8 + 10) : 0;

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

// ── Weather — hourly AM/PM split, daily fallback ──────────────────────────────
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
} catch (Throwable $t) { /* fall through */ }

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

// ── Memory card — seasonal plant tip from quiz bank ───────────────────────────
$memory = null;
try {
    $month = (int)date('n');
    if ($month >= 3 && $month <= 5) {
        $seasonLabel = 'Spring tip';
        $seasonIcon  = '🌱';
        $allCats     = ['Plant & Grass ID', 'Turf & Soil', 'Weed ID', 'Pest & Disease ID'];
    } elseif ($month >= 6 && $month <= 8) {
        $seasonLabel = 'Summer tip';
        $seasonIcon  = '☀️';
        $allCats     = ['Pest & Disease ID', 'Weed ID', 'Turf & Soil', 'Plant & Grass ID'];
    } elseif ($month >= 9 && $month <= 11) {
        $seasonLabel = 'Fall tip';
        $seasonIcon  = '🍂';
        $allCats     = ['Turf & Soil', 'Plant & Grass ID', 'Weed ID', 'Pest & Disease ID'];
    } else {
        $seasonLabel = 'Winter tip';
        $seasonIcon  = '❄️';
        $allCats     = ['Turf & Soil', 'Plant & Grass ID', 'Pest & Disease ID', 'Weed ID'];
    }
    // Deterministic per user+day so it doesn't change on reload
    $daySeed      = (int)$user['id'] + (int)date('z');
    $placeholders = implode(',', array_fill(0, count($allCats), '?'));
    $memStmt = $db->prepare(
        "SELECT q.id, q.question_text, q.learn_notes,
                o.option_text AS correct_answer,
                c.name AS category_name, c.colour AS category_colour,
                (SELECT qi.image_path FROM quiz_question_images qi
                 WHERE qi.question_id = q.id ORDER BY qi.sort_order LIMIT 1) AS image_path
         FROM quiz_questions q
         JOIN quiz_categories c ON c.id = q.category_id
         JOIN quiz_options o    ON o.question_id = q.id AND o.is_correct = 1
         WHERE q.is_active = 1
           AND c.name IN ($placeholders)
           AND q.learn_notes IS NOT NULL AND q.learn_notes != ''
         ORDER BY (q.id + ?) % 10000
         LIMIT 1"
    );
    $memStmt->execute(array_merge($allCats, [$daySeed]));
    $memRow = $memStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($memRow) {
        $memory = $memRow;
        $memory['season_label'] = $seasonLabel;
        $memory['season_icon']  = $seasonIcon;
    }
} catch (Throwable $e) {
    // Memory card is non-critical
}

// ── Meta ──────────────────────────────────────────────────────────────────────
// (CSRF + session_write_close already called at top of file, before DB work.)

$isDriver  = !empty($user['is_driver']);

// ── Post-trip status (drivers only) — gates the clock-out button ──────────────
// Drivers must fill out the end-of-shift vehicle check before clocking out.
// Non-drivers always pass through.
// Multi-trip-aware: $postComplete here means "no open trip to close".
// Drivers can clock out when there's NO row for today (they never did a
// pre-trip), OR when every row for today already has a post_trip_at set.
// They CANNOT clock out while any row is in the open state
// (pre_trip_at IS NOT NULL AND post_trip_at IS NULL) — that's the
// current trip's post-trip form blocking the flow.
$postComplete = true;
if ($isDriver) {
    $ptStmt = $db->prepare("
        SELECT COUNT(*) FROM vehicle_trip_reports
        WHERE driver_id = ?
          AND report_date = ?
          AND pre_trip_at IS NOT NULL
          AND post_trip_at IS NULL
    ");
    $ptStmt->execute([$user['id'], $today]);
    $openTripCount = (int)$ptStmt->fetchColumn();
    $postComplete = ($openTripCount === 0);
}

$userName  = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if (!$userName) $userName = $user['full_name'] ?? 'Crew';
$userParts = explode(' ', $userName);
$initials  = strtoupper(substr($userParts[0] ?? 'U', 0, 1) . substr($userParts[1] ?? '', 0, 1));
?>
<!-- homebase v20260313 -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0D3B2E">
    <title>Home Base — Mowology</title>
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
    <script src="/crm/js/capacitor-bridge.js" defer></script>
    <style>
        :root {
            /* Brand colour + shared sizing tokens come from
               /crm/css/tokens.css loaded in <head>. Only keep the
               homebase-specific values here. */
            --hb-nav-h:        68px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        /* Accessibility — visible focus rings for keyboard + TalkBack */
        :where(a, button, input, textarea, select, [role="button"], [tabindex]):focus-visible {
            outline: 2px solid var(--hb-green);
            outline-offset: 2px;
            border-radius: 4px;
        }

        html, body {
            height: 100%;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--hb-bg);
            color: var(--hb-text);
            -webkit-font-smoothing: antialiased;
            overscroll-behavior: none;
        }

        /* ── Scrollable content ──────────────────────────────────── */
        .hb-scroll {
            height: 100dvh;
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
            padding-bottom: calc(var(--hb-nav-h) + var(--hb-safe-bottom) + 16px);
        }

        /* ── Header bar ─────────────────────────────────────────── */
        .hb-header {
            background: var(--hb-forest);
            padding: 14px 20px;
            padding-top: calc(14px + var(--hb-safe-top));
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .hb-header-left   { display: flex; align-items: center; gap: 10px; }
        .hb-logo-mark {
            width: 34px; height: 34px;
            background: var(--hb-lime);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 16px; color: var(--hb-forest);
            flex-shrink: 0;
        }
        .hb-header-title { color: #fff; font-weight: 700; font-size: 17px; letter-spacing: -0.3px; }
        .hb-header-sub   { color: rgba(255,255,255,0.5); font-size: 12px; margin-top: 1px; }
        .hb-clock-chip {
            display: flex; align-items: center; gap: 5px;
            background: rgba(127,216,88,0.12);
            border: 1px solid rgba(127,216,88,0.25);
            color: var(--hb-lime);
            padding: 5px 11px; border-radius: 20px;
            font-size: 12px; font-weight: 600;
        }
        .hb-clock-dot {
            width: 6px; height: 6px;
            background: var(--hb-lime); border-radius: 50%;
            animation: hb-pulse 2s ease-in-out infinite;
        }
        @keyframes hb-pulse {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0.3; }
        }

        /* ── Content ─────────────────────────────────────────────── */
        .hb-content { padding: 14px 14px 0; display: flex; flex-direction: column; gap: 12px; }

        /* ── D8 Tracking health card (drivers only) ──────────────── */
        .hb-health { padding: 12px 14px; }
        .hb-health-row { display: flex; align-items: center; gap: 10px; }
        .hb-health-dot {
            width: 10px; height: 10px; border-radius: 50%;
            background: var(--hb-muted); flex-shrink: 0;
            box-shadow: 0 0 0 0 rgba(127, 216, 88, 0);
            transition: background 200ms, box-shadow 200ms;
        }
        .hb-health.is-active .hb-health-dot {
            background: var(--hb-lime);
            box-shadow: 0 0 0 4px rgba(127, 216, 88, 0.2);
            animation: hb-health-pulse 1.6s ease-in-out infinite;
        }
        .hb-health.is-stale .hb-health-dot { background: var(--mw-color-warning, #D97706); }
        .hb-health.is-error  .hb-health-dot { background: var(--mw-color-danger,  #DC2626); }
        @keyframes hb-health-pulse {
            0%,100% { box-shadow: 0 0 0 4px rgba(127, 216, 88, 0.2); }
            50%     { box-shadow: 0 0 0 8px rgba(127, 216, 88, 0.0); }
        }
        .hb-health-main { flex: 1; min-width: 0; }
        .hb-health-title {
            font-size: 12px; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.5px; color: var(--hb-muted);
        }
        .hb-health-sub {
            font-size: 13px; font-weight: 600; color: var(--hb-text);
            margin-top: 2px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .hb-health-queue {
            display: inline-flex; flex-direction: column; align-items: flex-end;
            padding: 4px 8px;
            background: var(--mw-color-warning-bg, #FEF3C7);
            color: var(--mw-color-warning, #D97706);
            border-radius: 10px;
            font-size: 11px; font-weight: 800;
            line-height: 1;
        }
        .hb-health-queue-label {
            font-size: 9px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.5px;
            margin-top: 2px;
        }

        /* ── Base card ───────────────────────────────────────────── */
        .hb-card {
            background: var(--hb-card);
            border-radius: var(--hb-radius);
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .hb-card-lbl {
            font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px;
            color: var(--hb-muted);
        }

        /* ── Day summary card ────────────────────────────────────── */
        .hb-greeting { padding: 16px 16px 12px; }
        .hb-greeting-name  { font-size: 20px; font-weight: 800; color: var(--hb-text); line-height: 1.2; }
        .hb-greeting-sub   { font-size: 13px; color: var(--hb-muted); margin-top: 3px; }
        .hb-metrics {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            border-top: 1px solid var(--hb-border);
        }
        .hb-metric {
            padding: 13px 0;
            text-align: center;
            border-right: 1px solid var(--hb-border);
        }
        .hb-metric:last-child { border-right: none; }
        .hb-metric-val { font-size: 21px; font-weight: 800; color: var(--hb-text); line-height: 1; }
        .hb-metric-lbl { font-size: 11px; color: var(--hb-muted); margin-top: 3px; font-weight: 500; }
        .hb-metric-done .hb-metric-val { color: var(--hb-green); }
        .hb-no-stops {
            padding: 14px 16px; border-top: 1px solid var(--hb-border);
            font-size: 13px; color: var(--hb-muted); text-align: center;
        }

        /* ── Weather card ────────────────────────────────────────── */
        .hb-weather-header {
            padding: 12px 16px 10px;
            border-bottom: 1px solid var(--hb-border);
            display: flex; align-items: center; justify-content: space-between;
        }
        .hb-weather-loc { font-size: 11px; color: var(--hb-muted); }
        .hb-weather-row { display: grid; grid-template-columns: 1fr 1fr; }
        .hb-wx {
            padding: 14px 16px;
            display: flex; flex-direction: column; gap: 1px;
        }
        .hb-wx:first-child { border-right: 1px solid var(--hb-border); }
        .hb-wx-period {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; color: var(--hb-muted);
        }
        .hb-wx-main { display: flex; align-items: baseline; gap: 6px; margin-top: 5px; }
        .hb-wx-icon { font-size: 24px; }
        .hb-wx-temp { font-size: 28px; font-weight: 800; line-height: 1; }
        .hb-wx-cond { font-size: 12px; color: var(--hb-muted); margin-top: 3px; }
        .hb-wx-precip { font-size: 11px; color: var(--mw-color-info); font-weight: 600; margin-top: 2px; }

        /* ── Memory card ─────────────────────────────────────────── */
        .hb-mem-header {
            padding: 12px 16px 10px;
            border-bottom: 1px solid var(--hb-border);
            display: flex; align-items: center; justify-content: space-between;
        }
        .hb-mem-season { display: flex; align-items: center; gap: 6px; }
        .hb-mem-season-icon  { font-size: 16px; }
        .hb-mem-season-label {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.6px; color: var(--hb-muted);
        }
        .hb-mem-cat {
            display: inline-flex; align-items: center;
            padding: 3px 9px; border-radius: 20px;
            font-size: 11px; font-weight: 600;
        }
        .hb-mem-body { padding: 14px 16px; }
        .hb-mem-img {
            width: 100%; max-height: 170px; object-fit: cover;
            border-radius: 10px; margin-bottom: 12px; display: block;
        }
        .hb-mem-q {
            font-size: 14px; font-weight: 600; color: var(--hb-text);
            line-height: 1.45; margin-bottom: 10px;
        }
        .hb-mem-answer {
            background: var(--hb-light); border-radius: 10px; padding: 10px 14px;
            margin-bottom: 10px;
        }
        .hb-mem-answer-label {
            font-size: 9px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1px; color: var(--hb-green); margin-bottom: 3px;
        }
        .hb-mem-answer-text { font-size: 15px; font-weight: 700; color: var(--hb-dark); }
        .hb-mem-notes { font-size: 13px; color: var(--hb-muted); line-height: 1.55; font-style: italic; }

        /* ── Bottom nav ──────────────────────────────────────────── */
        .hb-nav {
            position: fixed; bottom: 0; left: 0; right: 0;
            height: calc(var(--hb-nav-h) + var(--hb-safe-bottom));
            padding-bottom: var(--hb-safe-bottom);
            background: #fff;
            border-top: 1px solid var(--hb-border);
            display: grid; grid-template-columns: repeat(4, 1fr);
            z-index: 100;
        }
        .hb-nav-btn {
            display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 3px;
            text-decoration: none; color: var(--hb-muted);
            font-size: 11px; font-weight: 500;
            border: none; background: none; cursor: pointer;
            -webkit-tap-highlight-color: transparent;
            transition: color 0.15s;
        }
        .hb-nav-btn svg { stroke: currentColor; }
        .hb-nav-btn.active  { color: var(--hb-green); }
        .hb-nav-btn:active  { color: var(--hb-dark); }

        /* ── Slide-up menu ───────────────────────────────────────── */
        .hb-menu-overlay {
            position: fixed; inset: 0; z-index: 200; pointer-events: none;
        }
        .hb-menu-overlay.open { pointer-events: all; }
        .hb-menu-scrim {
            position: absolute; inset: 0;
            background: rgba(0,0,0,0); transition: background 0.3s;
        }
        .hb-menu-overlay.open .hb-menu-scrim { background: rgba(0,0,0,0.45); }
        .hb-menu-sheet {
            position: absolute; bottom: 0; left: 0; right: 0;
            background: #fff; border-radius: 20px 20px 0 0;
            padding-bottom: calc(16px + var(--hb-safe-bottom));
            transform: translateY(100%);
            transition: transform 0.3s cubic-bezier(0.32, 0.72, 0, 1);
            max-height: 88dvh; overflow-y: auto;
        }
        .hb-menu-overlay.open .hb-menu-sheet { transform: translateY(0); }
        .hb-menu-handle {
            width: 36px; height: 4px; background: #E5E7EB;
            border-radius: 2px; margin: 12px auto 14px;
        }
        .hb-menu-user {
            display: flex; align-items: center; gap: 12px;
            padding: 0 20px 16px; border-bottom: 1px solid var(--hb-border);
        }
        .hb-menu-avatar {
            width: 44px; height: 44px; border-radius: 50%;
            background: var(--hb-forest);
            display: flex; align-items: center; justify-content: center;
            color: var(--hb-lime); font-weight: 700; font-size: 15px; flex-shrink: 0;
        }
        .hb-menu-name { font-size: 15px; font-weight: 700; }
        .hb-menu-role { font-size: 12px; color: var(--hb-muted); text-transform: capitalize; }
        /* Clock status row */
        .hb-menu-clock-row {
            padding: 12px 20px; border-bottom: 1px solid var(--hb-border);
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
        }
        .hb-menu-clock-status {
            display: flex; align-items: center; gap: 7px;
            font-size: 13px; font-weight: 600; color: var(--hb-green);
        }
        .hb-menu-clock-dot {
            width: 8px; height: 8px; background: var(--hb-lime);
            border-radius: 50%; animation: hb-pulse 2s infinite;
        }
        .hb-menu-clock-since { font-size: 11px; color: var(--hb-muted); margin-top: 2px; }
        .hb-menu-clock-out-btn {
            background: var(--mw-color-danger-bg); color: var(--mw-color-danger);
            border: 1px solid var(--mw-color-danger); border-radius: 8px;
            padding: 7px 14px; font-size: 13px; font-weight: 600;
            cursor: pointer; white-space: nowrap; flex-shrink: 0;
        }
        /* Nav items */
        .hb-menu-nav { padding: 8px 12px; }
        .hb-menu-nav-item {
            display: flex; align-items: center; gap: 14px;
            padding: 12px 10px; border-radius: 10px;
            text-decoration: none; color: var(--hb-text);
            font-size: 15px; font-weight: 500;
            transition: background 0.15s;
        }
        .hb-menu-nav-item:active { background: #F3F4F6; }
        .hb-menu-nav-item svg { stroke: var(--hb-muted); flex-shrink: 0; }
        .hb-menu-divider { height: 1px; background: var(--hb-border); margin: 4px 20px; }
        .hb-menu-danger { color: var(--mw-color-danger) !important; }
        .hb-menu-danger svg { stroke: var(--mw-color-danger) !important; }

        /* ── Toast ───────────────────────────────────────────────── */
        .hb-toast {
            position: fixed;
            bottom: calc(var(--hb-nav-h) + var(--hb-safe-bottom) + 12px);
            left: 50%; transform: translateX(-50%) translateY(20px);
            background: var(--hb-forest); color: #fff;
            padding: 10px 18px; border-radius: 20px;
            font-size: 13px; font-weight: 500;
            opacity: 0; transition: opacity 0.2s, transform 0.2s;
            pointer-events: none; z-index: 300; white-space: nowrap;
        }
        .hb-toast.show  { opacity: 1; transform: translateX(-50%) translateY(0); }
        .hb-toast.error { background: var(--mw-color-danger); }
    </style>
</head>
<body>

<div class="hb-scroll">

    <!-- ── Header ──────────────────────────────────────────────────────────── -->
    <div class="hb-header">
        <div class="hb-header-left">
            <div class="hb-logo-mark">M</div>
            <div>
                <div class="hb-header-title">Home Base</div>
                <div class="hb-header-sub"><?php echo htmlspecialchars(date('l, F j')); ?></div>
            </div>
        </div>
        <div class="hb-clock-chip">
            <div class="hb-clock-dot"></div>
            <span id="hbClockDisplay"><?php echo htmlspecialchars($clockDisplay); ?></span>
        </div>
    </div>

    <div class="hb-content">

        <!-- ── Day Summary ───────────────────────────────────────────────── -->
        <div class="hb-card">
            <div class="hb-greeting">
                <div class="hb-greeting-name"><?php echo htmlspecialchars($dayGreeting . ', ' . $firstName); ?></div>
                <div class="hb-greeting-sub">
                    <?php if ($totalStops > 0): ?>
                        <?php echo $totalStops; ?> stop<?php echo $totalStops === 1 ? '' : 's'; ?> scheduled today
                        <?php if ($completedStops > 0): ?>&nbsp;&bull;&nbsp;<?php echo $completedStops; ?>/<?php echo $totalStops; ?> done<?php endif; ?>
                    <?php else: ?>
                        No stops scheduled — enjoy the day
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($totalStops > 0): ?>
            <div class="hb-metrics">
                <div class="hb-metric">
                    <div class="hb-metric-val"><?php echo $totalStops; ?></div>
                    <div class="hb-metric-lbl"><?php echo $totalStops === 1 ? 'Stop' : 'Stops'; ?></div>
                </div>
                <div class="hb-metric">
                    <div class="hb-metric-val"><?php echo $summaryMinutes >= 60 ? round($summaryMinutes / 60, 1) . 'h' : $summaryMinutes . 'm'; ?></div>
                    <div class="hb-metric-lbl">Est. Time</div>
                </div>
                <?php if ($summaryRevenue > 0): ?>
                <div class="hb-metric">
                    <div class="hb-metric-val">$<?php echo number_format($summaryRevenue, 0); ?></div>
                    <div class="hb-metric-lbl">Revenue</div>
                </div>
                <?php else: ?>
                <div class="hb-metric <?php echo $completedStops > 0 ? 'hb-metric-done' : ''; ?>">
                    <div class="hb-metric-val"><?php echo $completedStops; ?>/<?php echo $totalStops; ?></div>
                    <div class="hb-metric-lbl">Done</div>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="hb-no-stops">No stops assigned yet</div>
            <?php endif; ?>
        </div>

        <!-- ── Weather ───────────────────────────────────────────────────── -->
        <div class="hb-card">
            <div class="hb-weather-header">
                <span class="hb-card-lbl">Weather Today</span>
                <span class="hb-weather-loc">Vancouver, BC</span>
            </div>
            <div class="hb-weather-row">
                <div class="hb-wx">
                    <div class="hb-wx-period">Morning</div>
                    <div class="hb-wx-main">
                        <span class="hb-wx-icon"><?php echo $weatherAM['icon'] ?? '☀️'; ?></span>
                        <span class="hb-wx-temp"><?php echo round((float)($weatherAM['temp_c'] ?? 8)); ?>&deg;</span>
                    </div>
                    <div class="hb-wx-cond"><?php echo htmlspecialchars(ucfirst(strtolower($weatherAM['condition'] ?? 'Clear'))); ?></div>
                    <?php if (!empty($weatherAM['precip_chance_pct']) && (int)$weatherAM['precip_chance_pct'] > 10): ?>
                    <div class="hb-wx-precip">💧 <?php echo (int)$weatherAM['precip_chance_pct']; ?>% rain</div>
                    <?php endif; ?>
                </div>
                <div class="hb-wx">
                    <div class="hb-wx-period">Afternoon</div>
                    <div class="hb-wx-main">
                        <span class="hb-wx-icon"><?php echo $weatherPM['icon'] ?? '⛅'; ?></span>
                        <span class="hb-wx-temp"><?php echo round((float)($weatherPM['temp_c'] ?? 12)); ?>&deg;</span>
                    </div>
                    <div class="hb-wx-cond"><?php echo htmlspecialchars(ucfirst(strtolower($weatherPM['condition'] ?? 'Clear'))); ?></div>
                    <?php if (!empty($weatherPM['precip_chance_pct']) && (int)$weatherPM['precip_chance_pct'] > 10): ?>
                    <div class="hb-wx-precip">💧 <?php echo (int)$weatherPM['precip_chance_pct']; ?>% rain</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Memory Card ───────────────────────────────────────────────── -->
        <?php if ($memory): ?>
        <div class="hb-card">
            <div class="hb-mem-header">
                <div class="hb-mem-season">
                    <span class="hb-mem-season-icon"><?php echo $memory['season_icon']; ?></span>
                    <span class="hb-mem-season-label"><?php echo htmlspecialchars($memory['season_label']); ?></span>
                </div>
                <span class="hb-mem-cat"
                      style="background:<?php echo htmlspecialchars($memory['category_colour'] ?? '#2D8659'); ?>1A;color:<?php echo htmlspecialchars($memory['category_colour'] ?? '#2D8659'); ?>">
                    <?php echo htmlspecialchars($memory['category_name'] ?? ''); ?>
                </span>
            </div>
            <div class="hb-mem-body">
                <?php if (!empty($memory['image_path'])): ?>
                <img class="hb-mem-img"
                     src="<?php echo htmlspecialchars($memory['image_path']); ?>"
                     alt="<?php echo htmlspecialchars($memory['correct_answer'] ?? ''); ?>"
                     loading="lazy">
                <?php endif; ?>
                <div class="hb-mem-q"><?php echo htmlspecialchars($memory['question_text'] ?? ''); ?></div>
                <div class="hb-mem-answer">
                    <div class="hb-mem-answer-label">Answer</div>
                    <div class="hb-mem-answer-text"><?php echo htmlspecialchars($memory['correct_answer'] ?? ''); ?></div>
                </div>
                <?php if (!empty($memory['learn_notes'])): ?>
                <div class="hb-mem-notes"><?php echo htmlspecialchars($memory['learn_notes']); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isDriver): ?>
        <!-- ── Driver tracking health card (D8) ────────────────── -->
        <div class="hb-card hb-health" id="hbHealthCard" hidden>
            <div class="hb-health-row">
                <div class="hb-health-dot" id="hbHealthDot"></div>
                <div class="hb-health-main">
                    <div class="hb-health-title" id="hbHealthTitle">Tracking</div>
                    <div class="hb-health-sub" id="hbHealthSub">Checking status…</div>
                </div>
                <div class="hb-health-queue" id="hbHealthQueue" hidden>
                    <span id="hbHealthQueueCount">0</span>
                    <span class="hb-health-queue-label">pending</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.hb-content -->
</div><!-- /.hb-scroll -->

<!-- ── Bottom Nav ────────────────────────────────────────────────────────────── -->
<nav class="hb-nav">
    <button class="hb-nav-btn active" aria-label="Home Base">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        <span>Home</span>
    </button>
    <a href="/crm/jobs/schedule.php" class="hb-nav-btn" aria-label="Schedule">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span>Schedule</span>
    </a>
    <a href="/crm/expenses_appstack.php?mode=quick&return=<?php echo urlencode('/crm/homebase.php'); ?>" class="hb-nav-btn" aria-label="Receipt">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        <span>Receipt</span>
    </a>
    <button class="hb-nav-btn" id="hbMenuBtn" aria-label="Menu">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        <span>Menu</span>
    </button>
</nav>

<!-- ── Slide-up Menu ─────────────────────────────────────────────────────────── -->
<div class="hb-menu-overlay" id="hbMenuOverlay">
    <div class="hb-menu-scrim" id="hbMenuScrim"></div>
    <div class="hb-menu-sheet">
        <div class="hb-menu-handle"></div>

        <!-- User -->
        <div class="hb-menu-user">
            <div class="hb-menu-avatar"><?php echo htmlspecialchars($initials); ?></div>
            <div>
                <div class="hb-menu-name"><?php echo htmlspecialchars($firstName); ?></div>
                <div class="hb-menu-role"><?php echo htmlspecialchars(ucfirst($user['role'] ?? 'crew')); ?></div>
            </div>
        </div>

        <!-- Clock status + out -->
        <div class="hb-menu-clock-row">
            <div>
                <div class="hb-menu-clock-status">
                    <div class="hb-menu-clock-dot"></div>
                    Clocked in
                </div>
                <div class="hb-menu-clock-since">
                    Since <?php echo date('g:i a', $clockInTs); ?> &bull;
                    <span id="hbMenuElapsed"><?php echo htmlspecialchars($clockDisplay); ?></span>
                </div>
            </div>
            <button class="hb-menu-clock-out-btn" id="hbClockOutBtn" onclick="hbClockOut()">Clock Out</button>
        </div>

        <!-- Nav items -->
        <div class="hb-menu-nav">
            <a href="/crm/jobs/schedule.php" class="hb-menu-nav-item">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Today's Schedule
            </a>
            <?php if ($isDriver): ?>
            <a href="/crm/driver-log.php" class="hb-menu-nav-item">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                Pre-Trip Log
            </a>
            <?php if (!$postComplete): ?>
            <a href="/crm/driver-log-post.php" class="hb-menu-nav-item">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                Post-Trip Log
            </a>
            <?php endif; ?>
            <?php endif; ?>
            <a href="/crm/map_appstack.php" class="hb-menu-nav-item">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
                Map
            </a>
            <a href="/crm/expenses_appstack.php" class="hb-menu-nav-item">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                Expenses
            </a>
            <div class="hb-menu-divider"></div>
            <a href="/crm/profile.php" class="hb-menu-nav-item">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Profile
            </a>
            <a href="/crm/logout_secure.php" class="hb-menu-nav-item hb-menu-danger">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
            </a>
        </div>
    </div>
</div>

<div class="hb-toast" id="hbToast" role="alert" aria-live="assertive" aria-atomic="true"></div>

<script>
(function () {
    'use strict';

    var CSRF          = <?php echo json_encode($csrf); ?>;
    var clockIn       = <?php echo (int)$clockInTs; ?>; // unix timestamp
    var IS_DRIVER     = <?php echo $isDriver ? 'true' : 'false'; ?>;
    var POST_COMPLETE = <?php echo $postComplete ? 'true' : 'false'; ?>;

    // ── Running clock ──────────────────────────────────────────────────
    function formatElapsed(secs) {
        var h = Math.floor(secs / 3600);
        var m = Math.floor((secs % 3600) / 60);
        return h > 0 ? h + 'h ' + (m < 10 ? '0' : '') + m + 'm' : m + 'm';
    }
    function updateClock() {
        var secs = Math.floor(Date.now() / 1000) - clockIn;
        var txt  = formatElapsed(secs);
        var el1  = document.getElementById('hbClockDisplay');
        var el2  = document.getElementById('hbMenuElapsed');
        if (el1) el1.textContent = txt;
        if (el2) el2.textContent = txt;
    }
    updateClock();
    setInterval(updateClock, 30000);

    // ── Slide-up menu ──────────────────────────────────────────────────
    var overlay = document.getElementById('hbMenuOverlay');
    var scrim   = document.getElementById('hbMenuScrim');
    var menuBtn = document.getElementById('hbMenuBtn');

    function openMenu()  { overlay.classList.add('open');    document.body.style.overflow = 'hidden'; }
    function closeMenu() { overlay.classList.remove('open'); document.body.style.overflow = ''; }

    if (menuBtn) menuBtn.addEventListener('click', openMenu);
    if (scrim)   scrim.addEventListener('click', closeMenu);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });

    // Back gesture closes menu
    window.addEventListener('popstate', closeMenu);

    // ── Clock Out ─────────────────────────────────────────────────────
    window.hbClockOut = function () {
        // Drivers must complete the post-trip vehicle check before clocking out.
        // Send them to the dedicated form — it saves post-trip, then clocks out.
        if (IS_DRIVER && !POST_COMPLETE) {
            window.location.href = '/crm/driver-log-post.php';
            return;
        }

        var btn = document.getElementById('hbClockOutBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'Clocking out…'; }

        var lat = null, lng = null;
        function doOut() {
            fetch('/crm/api/time-clock.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'clock_out', csrf_token: CSRF, lat: lat, lng: lng })
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success === true) {
                    // Release the native foreground GPS service so the
                    // "Mowology GPS Tracking" notification disappears and
                    // battery drain stops. Safe in browser (MwNative is
                    // undefined there).
                    try {
                        if (window.MwNative && window.MwNative.geo) {
                            window.MwNative.geo.stopBackgroundTracking();
                        }
                    } catch (e) { /* non-critical */ }
                    if (window.MwHaptics) window.MwHaptics.success();
                    hbToast('Clocked out. Great work today! 👊');
                    setTimeout(function () { window.location.href = '/crm/app-launch.php'; }, 1200);
                } else {
                    if (d.post_trip_required) {
                        window.location.href = '/crm/driver-portal.php?open=post';
                        return;
                    }
                    hbToast(d.error || 'Clock out failed', true);
                    if (btn) { btn.disabled = false; btn.textContent = 'Clock Out'; }
                }
            })
            .catch(function () {
                hbToast('Network error — try again', true);
                if (btn) { btn.disabled = false; btn.textContent = 'Clock Out'; }
            });
        }

        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function (p) { lat = p.coords.latitude; lng = p.coords.longitude; doOut(); },
                function ()  { doOut(); },
                { timeout: 5000 }
            );
        } else {
            doOut();
        }
    };

    // ── D8 — Driver tracking health card ─────────────────────────────
    // Polls MwTracking.getHealth() every 15 s and updates the small
    // status card. No-op on non-driver pages (the PHP template gated
    // on $isDriver so the element only exists for drivers).
    (function () {
        var card = document.getElementById('hbHealthCard');
        if (!card) return;
        if (!IS_DRIVER) return;
        if (!window.MwNative || !window.MwNative.tracking) {
            // Not in native app — leave the card hidden.
            return;
        }

        card.hidden = false;
        var dot    = document.getElementById('hbHealthDot');
        var sub    = document.getElementById('hbHealthSub');
        var queue  = document.getElementById('hbHealthQueue');
        var qCount = document.getElementById('hbHealthQueueCount');

        function fmtAge(ms) {
            if (!ms || ms < 0) return '—';
            var s = Math.floor(ms / 1000);
            if (s < 60)  return s + 's ago';
            if (s < 3600) return Math.floor(s / 60) + 'm ago';
            return Math.floor(s / 3600) + 'h ago';
        }

        function render(h) {
            if (!h) {
                card.className = 'hb-card hb-health is-error';
                sub.textContent = 'Tracking unavailable';
                queue.hidden = true;
                return;
            }
            var active = !!h.isTrackingActive;
            var lastFixMs = h.lastFixTime ? (Date.now() - h.lastFixTime) : null;
            var stale = !active || (lastFixMs !== null && lastFixMs > 5 * 60 * 1000);

            card.className = 'hb-card hb-health ' +
                (active && !stale ? 'is-active' : stale ? 'is-stale' : 'is-error');

            var parts = [];
            if (h.currentActivity && h.currentActivity !== 'UNKNOWN') {
                parts.push(h.currentActivity.toLowerCase().replace('_', ' '));
            }
            if (lastFixMs !== null) parts.push('fix ' + fmtAge(lastFixMs));
            if (!parts.length) parts.push(active ? 'Running' : 'Stopped');
            sub.textContent = parts.join(' · ');

            var pending = (h.pointsUnsyncedCount || 0);
            if (pending > 0) {
                queue.hidden = false;
                qCount.textContent = String(pending);
            } else {
                queue.hidden = true;
            }
        }

        function refresh() {
            if (!window.MwNative || !window.MwNative.tracking) return;
            window.MwNative.tracking.getHealth().then(render).catch(function () {
                render(null);
            });
        }

        refresh();
        var timer = setInterval(refresh, 15000);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') refresh();
        });
        // Clean up on pagehide so the setInterval doesn't leak when the
        // WebView navigates away. (Part of D5 pattern.)
        window.addEventListener('pagehide', function () { clearInterval(timer); }, { once: true });
    }());

    // ── Toast ─────────────────────────────────────────────────────────
    window.hbToast = function (msg, isError) {
        var el = document.getElementById('hbToast');
        el.textContent = msg;
        el.className = 'hb-toast' + (isError ? ' error' : '');
        void el.offsetWidth;
        el.classList.add('show');
        setTimeout(function () { el.classList.remove('show'); }, 3000);
    };
}());
</script>
</body>
</html>
