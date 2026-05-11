<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/location.php
 *
 * Mobile GPS Ping API — JWT authenticated
 *
 * POST /api/schedule/location
 *   Body: {"lat": N, "lng": N, "accuracy": N, "activity": "...", "visit_id": N?}
 *
 * Logs the ping to crew_location_history. Runs a lightweight proximity
 * auto-start check if the crew member is clocked in with no active timer.
 *
 * Response matches LocationPingResponse in GPSTrackingService.swift:
 *   {success, skipped?, auto_started?: {visit_id, job_title, property_address,
 *                                       distance_meters, clock_in_created}}
 */

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

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';

$jwtUser = requireJwt();

require_once APP_ROOT . '/Modules/Team/Services/TimeclockFunctions.php';

$userId = (int)$jwtUser['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$lat      = isset($body['lat'])      ? (float)$body['lat']      : null;
$lng      = isset($body['lng'])      ? (float)$body['lng']      : null;
$accuracy = isset($body['accuracy']) ? (float)$body['accuracy'] : 50.0;
$visitId  = isset($body['visit_id']) ? (int)$body['visit_id']   : null;

if ($lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'lat and lng are required']);
    exit;
}

// Skip pings with poor GPS accuracy — iOS reports accuracy in metres
if ($accuracy > 200) {
    echo json_encode(['success' => true, 'skipped' => true, 'auto_started' => null]);
    exit;
}

try {
    $db = getDB();

    // Log to crew_location_history
    $db->prepare(
        "INSERT INTO crew_location_history (crew_id, latitude, longitude, accuracy_meters, visit_id, timestamp)
         VALUES (?, ?, ?, ?, ?, NOW())"
    )->execute([$userId, $lat, $lng, $accuracy, $visitId ?: null]);

    // ── Proximity auto-start (no session; use DB only) ────────────────────────
    // Only attempt if: clocked in + no active timer already running
    $autoStarted = null;
    $clockEntry  = getActiveClockEntry($userId);

    if ($clockEntry && !getActiveJobTimer($userId)) {
        $autoStarted = runProximityAutoStart($db, $userId, $lat, $lng, $accuracy);
    }

    echo json_encode([
        'success'      => true,
        'skipped'      => false,
        'auto_started' => $autoStarted,
    ]);
} catch (Throwable $e) {
    error_log('[schedule/location] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to record location']);
}

// ── Proximity check (DB-only, no session cache) ───────────────────────────────

/**
 * Find the nearest scheduled visit within the configured proximity radius.
 * Returns an auto_started payload on success, null otherwise.
 *
 * Intentionally avoids $_SESSION so it works from a stateless JWT context.
 * A 5-minute cooldown is enforced via job_time_entries to prevent double-starts.
 */
function runProximityAutoStart(PDO $db, int $userId, float $lat, float $lng, float $accuracy): ?array
{
    $proximityMeters = 150;

    // Skip very inaccurate fixes (accuracy worse than 1.5× the proximity radius)
    if ($accuracy > $proximityMeters * 1.5) {
        return null;
    }

    // Cooldown: skip if auto-started in the last 5 minutes
    $cooldown = $db->prepare(
        "SELECT id FROM job_time_entries
         WHERE user_id = ? AND auto_started = 1
           AND start_time > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
         LIMIT 1"
    );
    $cooldown->execute([$userId]);
    if ($cooldown->fetch()) {
        return null;
    }

    // Fetch today's scheduled visits with GPS coordinates
    $today   = date('Y-m-d');
    $visits  = $db->prepare(
        "SELECT jv.id, jv.status, jv.service_type,
                p.address AS property_address,
                p.latitude AS property_lat, p.longitude AS property_lng
         FROM job_visits jv
         JOIN job_plans jp ON jp.id = jv.plan_id
         JOIN properties p ON p.id = jp.property_id
         WHERE jv.scheduled_date = ?
           AND jv.status = 'scheduled'
           AND p.latitude IS NOT NULL
           AND p.longitude IS NOT NULL"
    );
    $visits->execute([$today]);
    $allVisits = $visits->fetchAll(PDO::FETCH_ASSOC);

    if (!$allVisits) {
        return null;
    }

    // Find nearest visit within proximity radius using haversine
    $nearest     = null;
    $nearestDist = PHP_FLOAT_MAX;

    foreach ($allVisits as $visit) {
        $vLat = (float)$visit['property_lat'];
        $vLng = (float)$visit['property_lng'];
        $dist = haversineDistance($lat, $lng, $vLat, $vLng);

        if ($dist < $nearestDist) {
            $nearestDist = $dist;
            $nearest     = $visit;
        }
    }

    if (!$nearest || $nearestDist > $proximityMeters) {
        return null;
    }

    $visitId = (int)$nearest['id'];

    // Guard: visit not already auto-started today
    $alreadyStmt = $db->prepare(
        "SELECT id FROM job_time_entries
         WHERE visit_id = ? AND auto_started = 1 AND DATE(start_time) = CURDATE()
         LIMIT 1"
    );
    $alreadyStmt->execute([$visitId]);
    if ($alreadyStmt->fetch()) {
        return null;
    }

    // Start the visit timer
    try {
        startVisitTimer($visitId, $userId, null, null, true);
    } catch (Exception $e) {
        return null; // timer already running (race condition)
    }

    return [
        'visit_id'         => $visitId,
        'job_title'        => null,
        'property_address' => $nearest['property_address'] ?? null,
        'distance_meters'  => (int)round($nearestDist),
        'clock_in_created' => false,
    ];
}
