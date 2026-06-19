<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/day.php
 *
 * Mobile Schedule API — Day View
 *
 * GET /api/schedule/day?date=YYYY-MM-DD
 * Authorization: Bearer <jwt>
 *
 * Returns all calendar stops for a given date, role-filtered:
 *   admin/manager → all stops for the day
 *   user (crew)   → only stops where they are assigned
 *
 * Response 200:
 * {
 *   "success": true,
 *   "date": "2026-03-05",
 *   "role": "user",
 *   "stop_count": 3,
 *   "stops": [ { stop fields + visits[] } ]
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
$date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD.']);
    exit;
}

// Sanity-check the date is actually valid (e.g. not 2026-02-31)
$dt = date_create_from_format('Y-m-d', $date);
if (!$dt || $dt->format('Y-m-d') !== $date) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date value.']);
    exit;
}

// ── Role-based crew filter ────────────────────────────────────────────────────
// Admin/manager sees all stops; crew sees only their own
$crewFilter = jwtIsAdmin($jwtUser['role']) ? null : $jwtUser['id'];

// ── Fetch stops ───────────────────────────────────────────────────────────────
try {
    $calendarData = getCalendarStops($date, $date, $crewFilter);
} catch (Throwable $e) {
    error_log('[schedule/day] getCalendarStops error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load schedule data.']);
    exit;
}

$dayStops = $calendarData[$date] ?? [];

// ── Sort: estimated_arrival first, then route_order ───────────────────────────
uasort($dayStops, static function (array $a, array $b): int {
    $aTime = $a['estimated_arrival'] ?? ($a['visits'][0]['scheduled_time_start'] ?? '23:59:59');
    $bTime = $b['estimated_arrival'] ?? ($b['visits'][0]['scheduled_time_start'] ?? '23:59:59');
    $cmp = strcmp($aTime ?? '', $bTime ?? '');
    if ($cmp !== 0) return $cmp;
    return ($a['route_order'] ?? 999) - ($b['route_order'] ?? 999);
});

// ── Shape response ────────────────────────────────────────────────────────────
$stops = [];
foreach ($dayStops as $stop) {
    // Format estimated_arrival as HH:MM (drop seconds, null if not set)
    $arrival = null;
    if (!empty($stop['estimated_arrival'])) {
        $arrival = substr((string)$stop['estimated_arrival'], 0, 5);
    }

    // Shape visits
    $visits = [];
    foreach (($stop['visits'] ?? []) as $v) {
        $visits[] = [
            'visit_id'           => (int)($v['visit_id'] ?? 0),
            'visit_number'       => (string)($v['visit_number'] ?? ''),
            'service_type'       => (string)($v['service_type'] ?? ''),
            'plan_title'         => (string)($v['plan_title'] ?? ''),
            'plan_number'        => (string)($v['plan_number'] ?? ''),
            'visit_status'       => (string)($v['visit_status'] ?? 'scheduled'),
            'estimated_duration' => (int)($v['estimated_duration'] ?? 0),
            'price_per_visit'    => round((float)($v['price_per_visit'] ?? 0), 2),
            'scheduled_start'    => isset($v['scheduled_time_start'])
                ? substr((string)$v['scheduled_time_start'], 0, 5)
                : null,
            'is_flagged'          => (bool)($v['is_flagged'] ?? false),
            'contact_has_reviewed'=> (bool)($v['contact_has_reviewed'] ?? false),
        ];
    }

    $stops[] = [
        'stop_id'          => (int)($stop['stop_id'] ?? 0),
        'stop_date'        => $date,
        'stop_status'      => (string)($stop['stop_status'] ?? 'scheduled'),
        'route_order'      => (int)($stop['route_order'] ?? 0),
        'estimated_arrival'=> $arrival,
        'property_id'      => (int)($stop['property_id'] ?? 0),
        'property_address' => (string)($stop['property_address'] ?? ''),
        'property_city'    => (string)($stop['property_city'] ?? ''),
        'property_name'    => isset($stop['property_name']) ? (string)$stop['property_name'] : null,
        'latitude'         => isset($stop['latitude'])  ? (float)$stop['latitude']  : null,
        'longitude'        => isset($stop['longitude']) ? (float)$stop['longitude'] : null,
        'contact_id'       => isset($stop['contact_id']) ? (int)$stop['contact_id'] : null,
        'contact_name'     => isset($stop['contact_name']) ? (string)$stop['contact_name'] : null,
        'company_name'     => isset($stop['company_name']) ? (string)$stop['company_name'] : null,
        'lawn_sqft'        => isset($stop['lawn_sqft']) ? (float)$stop['lawn_sqft'] : null,
        'crew_names'       => $stop['crew_names'] ?? ($stop['crew_name'] ? [(string)$stop['crew_name']] : []),
        'visit_count'      => count($visits),
        'visits'           => $visits,
    ];
}

// Timed-extras rate per 5-minute block — lets the app show a live dollar total
// while crew accrue extra-work minutes on the completion sheet (default $5.00).
$extrasRate = 5.00;
try {
    $erRow = getDB()->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'extras_rate_per_5min' LIMIT 1")
                ->fetch(PDO::FETCH_ASSOC);
    $extrasRate = round(floatval($erRow['setting_value'] ?? 5.00), 2);
} catch (Throwable $e) { /* default 5.00 */ }

// ── Respond ───────────────────────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'success'              => true,
    'date'                 => $date,
    'role'                 => $jwtUser['role'],
    'stop_count'           => count($stops),
    'extras_rate_per_5min' => $extrasRate,
    'stops'                => $stops,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
