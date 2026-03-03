<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/week.php
 *
 * Mobile Schedule API — Week Summary
 *
 * GET /api/schedule/week?start=YYYY-MM-DD
 * Authorization: Bearer <jwt>
 *
 * Returns a 7-day summary used to populate the calendar week strip
 * in the iOS app. Each day shows stop/visit counts so the week
 * header can render dot indicators without loading full stop detail.
 *
 * Response 200:
 * {
 *   "success": true,
 *   "start": "2026-03-02",
 *   "end":   "2026-03-08",
 *   "role":  "admin",
 *   "days": [
 *     {
 *       "date": "2026-03-02",
 *       "day_name": "Mon",
 *       "stop_count": 3,
 *       "visit_count": 5,
 *       "has_incomplete": true
 *     },
 *     ...
 *   ]
 * }
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────
if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Auth & config ────────────────────────────────────────────────────────────
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';

$jwtUser = requireJwt();

// ── Load schedule functions ──────────────────────────────────────────────────
require_once CRM_INCLUDES . '/plan-functions.php';

// ── Input validation ─────────────────────────────────────────────────────────
// Default: Monday of current week
$startInput = isset($_GET['start']) ? trim($_GET['start']) : '';

if ($startInput === '') {
    // Default to Monday of current week
    $today = new DateTimeImmutable('today');
    $dow   = (int)$today->format('N'); // 1=Mon, 7=Sun
    $start = $today->modify('-' . ($dow - 1) . ' days');
} else {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startInput)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid start date format. Use YYYY-MM-DD.']);
        exit;
    }
    $start = DateTimeImmutable::createFromFormat('Y-m-d', $startInput);
    if (!$start || $start->format('Y-m-d') !== $startInput) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid start date value.']);
        exit;
    }
}

$end       = $start->modify('+6 days');
$startStr  = $start->format('Y-m-d');
$endStr    = $end->format('Y-m-d');

// ── Role-based crew filter ────────────────────────────────────────────────────
$crewFilter = jwtIsAdmin($jwtUser['role']) ? null : $jwtUser['id'];

// ── Fetch week stops ──────────────────────────────────────────────────────────
try {
    $calendarData = getCalendarStops($startStr, $endStr, $crewFilter);
} catch (Throwable $e) {
    error_log('[schedule/week] getCalendarStops error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load schedule data.']);
    exit;
}

// ── Build per-day summary ─────────────────────────────────────────────────────
$days = [];
$cursor = $start;
for ($i = 0; $i < 7; $i++) {
    $dateStr  = $cursor->format('Y-m-d');
    $dayStops = $calendarData[$dateStr] ?? [];

    $stopCount    = count($dayStops);
    $visitCount   = 0;
    $hasIncomplete = false;

    foreach ($dayStops as $stop) {
        $visits = $stop['visits'] ?? [];
        $visitCount += count($visits);
        foreach ($visits as $v) {
            $status = $v['visit_status'] ?? 'scheduled';
            if (!in_array($status, ['completed', 'skipped', 'cancelled'], true)) {
                $hasIncomplete = true;
            }
        }
    }

    $days[] = [
        'date'           => $dateStr,
        'day_name'       => $cursor->format('D'),   // Mon, Tue, ...
        'day_number'     => (int)$cursor->format('j'),
        'is_today'       => $dateStr === date('Y-m-d'),
        'stop_count'     => $stopCount,
        'visit_count'    => $visitCount,
        'has_incomplete' => $hasIncomplete,
    ];

    $cursor = $cursor->modify('+1 day');
}

// ── Respond ───────────────────────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'success' => true,
    'start'   => $startStr,
    'end'     => $endStr,
    'role'    => $jwtUser['role'],
    'days'    => $days,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
