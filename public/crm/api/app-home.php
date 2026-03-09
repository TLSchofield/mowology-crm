<?php
/**
 * App Home Screen API — single endpoint for launch data.
 *
 * GET ?action=data  →  today's revenue, weather, clock status, quiz status, stop count
 *
 * Consolidates multiple API calls into one fast response for the app launch screen.
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

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
require_once CRM_INCLUDES . '/plan-functions.php';
require_once CRM_INCLUDES . '/timeclock-functions.php';
require_once CRM_INCLUDES . '/weather-service.php';

requireLogin();
$user = getCurrentUser();
session_write_close();

$db = getDB();
$today = date('Y-m-d');

// ── 1. Clock status ──────────────────────────────────────────────────
$clockEntry = getActiveClockEntry($user['id']);
$clockedIn = !empty($clockEntry);
$clockData = [
    'clocked_in'      => $clockedIn,
    'clock_in_time'   => $clockEntry['clock_in'] ?? null,
    'elapsed_seconds' => $clockEntry ? (int)(time() - strtotime($clockEntry['clock_in'])) : 0,
];

// ── 2. Quiz status ───────────────────────────────────────────────────
$enabledRow = $db->query(
    "SELECT setting_value FROM ops_settings WHERE setting_key='quiz_preshift_enabled'"
)->fetch(PDO::FETCH_ASSOC);
$quizEnabled = ($enabledRow && $enabledRow['setting_value'] == '1');

$quizDone = false;
$quizSessionLength = 3;
if ($quizEnabled) {
    $lenRow = $db->query(
        "SELECT setting_value FROM ops_settings WHERE setting_key='quiz_preshift_session_length'"
    )->fetch(PDO::FETCH_ASSOC);
    $quizSessionLength = max(3, (int)($lenRow['setting_value'] ?? 3));

    $doneStmt = $db->prepare(
        "SELECT id FROM quiz_preshift_log WHERE user_id=? AND log_date=CURDATE()"
    );
    $doneStmt->execute([$user['id']]);
    $quizDone = (bool)$doneStmt->fetch();
}

// ── 3. Today's revenue + stops ───────────────────────────────────────
$stops = getCalendarStops($today, $today);
$todayStops = $stops[$today] ?? [];
$todayRevenue = 0.0;
$todayDuration = 0;
$stopCount = count($todayStops);
$completedStops = 0;

foreach ($todayStops as $stop) {
    if (($stop['stop_status'] ?? '') === 'completed') $completedStops++;
    foreach (($stop['visits'] ?? []) as $v) {
        $todayRevenue += (float)($v['price_per_visit'] ?? 0);
        $todayDuration += (int)($v['estimated_duration'] ?? 0);
    }
}

$dailyTarget = 1200.00;

// ── 4. Weather ───────────────────────────────────────────────────────
$weather = [];
try {
    $forecast = getWeekForecast('Vancouver', 'BC');
    if (isset($forecast[$today])) {
        $w = $forecast[$today];
        $weather = [
            'high'          => $w['high'] ?? null,
            'low'           => $w['low'] ?? null,
            'condition'     => $w['condition'] ?? 'Unknown',
            'icon'          => getWeatherIcon($w['condition'] ?? ''),
            'precipitation' => $w['precipitation'] ?? 0,
            'wind'          => $w['wind'] ?? 0,
        ];
    }
} catch (Throwable $e) {
    // Weather is non-critical
    error_log('[app-home] Weather fetch failed: ' . $e->getMessage());
}

// ── 5. User greeting ─────────────────────────────────────────────────
$firstName = $user['first_name'] ?? explode(' ', $user['full_name'] ?? 'Team')[0];
$hour = (int)date('G');
if ($hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour < 17) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}

// ── Response ─────────────────────────────────────────────────────────
echo json_encode([
    'success' => true,
    'user'    => [
        'first_name' => $firstName,
        'greeting'   => $greeting,
    ],
    'clock'   => $clockData,
    'quiz'    => [
        'enabled'        => $quizEnabled,
        'done'           => $quizDone,
        'session_length' => $quizSessionLength,
    ],
    'today'   => [
        'date'            => $today,
        'day_name'        => date('l'),
        'revenue'         => round($todayRevenue, 2),
        'target'          => $dailyTarget,
        'target_pct'      => $dailyTarget > 0 ? (int)round(min(100, ($todayRevenue / $dailyTarget) * 100)) : 0,
        'stops'           => $stopCount,
        'completed_stops' => $completedStops,
        'duration_min'    => $todayDuration,
    ],
    'weather' => $weather,
]);
