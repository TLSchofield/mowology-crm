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

// ── 5. Daily plant care memory ───────────────────────────────────────
// Pull a seasonal plant care tip from the quiz question bank.
// Categories: Plant & Grass ID, Weed ID, Turf & Soil, Pest & Disease ID.
// Seeded daily (deterministic per user+date so it doesn't flicker on reload).
$memory = [];
try {
    $month = (int)date('n');

    // Seasonal tag mapping: which category slugs are most relevant this month.
    // Spring = plant/turf heavy, Summer = pest/weed heavy, Fall/Winter = turf/soil.
    if ($month >= 3 && $month <= 5) {
        $seasonLabel = 'Spring tip';
        $seasonIcon  = '🌱';
        $catPriority = ['Plant & Grass ID', 'Turf & Soil', 'Weed ID'];
    } elseif ($month >= 6 && $month <= 8) {
        $seasonLabel = 'Summer tip';
        $seasonIcon  = '☀️';
        $catPriority = ['Pest & Disease ID', 'Weed ID', 'Turf & Soil'];
    } elseif ($month >= 9 && $month <= 11) {
        $seasonLabel = 'Fall tip';
        $seasonIcon  = '🍂';
        $catPriority = ['Turf & Soil', 'Plant & Grass ID', 'Weed ID'];
    } else {
        $seasonLabel = 'Winter tip';
        $seasonIcon  = '❄️';
        $catPriority = ['Turf & Soil', 'Plant & Grass ID', 'Pest & Disease ID'];
    }

    // Deterministic seed: user_id + day-of-year → stable within a day, rotates daily.
    $daySeed = (int)$user['id'] + (int)date('z');

    // Try categories in priority order; fall back to any plant-care category.
    $allCats = array_unique(array_merge($catPriority, [
        'Plant & Grass ID', 'Weed ID', 'Turf & Soil', 'Pest & Disease ID'
    ]));
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
           AND (q.learn_notes IS NOT NULL AND q.learn_notes != '')
         ORDER BY (q.id + ?) % 10000
         LIMIT 1"
    );
    $memStmt->execute(array_merge($allCats, [$daySeed]));
    $memRow = $memStmt->fetch(PDO::FETCH_ASSOC);

    if ($memRow) {
        $memory = [
            'question'        => $memRow['question_text'],
            'answer'          => $memRow['correct_answer'],
            'learn_notes'     => $memRow['learn_notes'],
            'category'        => $memRow['category_name'],
            'category_colour' => $memRow['category_colour'] ?: '#2D8659',
            'image_path'      => $memRow['image_path'],
            'season_label'    => $seasonLabel,
            'season_icon'     => $seasonIcon,
        ];
    }
} catch (Throwable $e) {
    // Memory card is non-critical
    error_log('[app-home] Memory tip fetch failed: ' . $e->getMessage());
}

// ── 6. User greeting ─────────────────────────────────────────────────
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
    'memory'  => $memory,
]);
