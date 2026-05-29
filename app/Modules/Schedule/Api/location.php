<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/location.php
 *
 * Mobile Location Ping API — JWT-authenticated crew GPS pings
 *
 * POST /api/schedule/location
 * Authorization: Bearer <jwt>
 * Body: { "lat": float, "lng": float, "accuracy": float?, "visit_id": int? }
 *
 * Stores a ping in crew_location_history and runs proximity auto-start check
 * (same logic as crew-location.php POST, but session-free for JWT/iOS clients).
 *
 * Response 200: { "success": true, "id": int, "auto_started": null|{...} }
 * Response 200: { "success": true, "skipped": true, "reason": "rate_limited" }
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

try {
    require_once APP_ROOT . '/Core/config.php';
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/timeclock-functions.php';
    require_once CRM_INCLUDES . '/plan-functions.php';
    require_once APP_ROOT . '/Modules/Team/Services/GeofenceService.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required']);
        exit;
    }

    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $lat      = isset($input['lat'])      ? (float)$input['lat']      : null;
    $lng      = isset($input['lng'])      ? (float)$input['lng']      : null;
    $accuracy = isset($input['accuracy']) ? (float)$input['accuracy'] : 50.0;
    $visitId  = isset($input['visit_id']) ? (int)$input['visit_id']   : null;

    if ($lat === null || $lng === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'lat and lng are required']);
        exit;
    }

    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Coordinates out of range']);
        exit;
    }

    $db = getDB();

    // Rate limit: reject if last entry < 10 seconds ago
    $rateStmt = $db->prepare("
        SELECT (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(timestamp)) AS seconds_ago
        FROM crew_location_history
        WHERE crew_id = ?
        ORDER BY timestamp DESC
        LIMIT 1
    ");
    $rateStmt->execute([$userId]);
    $lastRow = $rateStmt->fetch(PDO::FETCH_ASSOC);
    if ($lastRow && (int)$lastRow['seconds_ago'] < 10) {
        echo json_encode(['success' => true, 'skipped' => true, 'reason' => 'rate_limited']);
        exit;
    }

    // Office classification: a home-geofence heartbeat with no active job (no
    // visit_id) is kept but flagged is_office=1 so route tracing can exclude it.
    // A ping with a visit_id is an active job → always route.
    $isOffice = 0;
    if ($visitId === null) {
        $geofence = new GeofenceService($db);
        $isOffice = $geofence->isOfficePing($userId, $lat, $lng) ? 1 : 0;
    }

    // Store the ping
    $stmt = $db->prepare("
        INSERT INTO crew_location_history (crew_id, latitude, longitude, accuracy_meters, visit_id, is_office, timestamp)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$userId, $lat, $lng, (int)round($accuracy), $visitId ?: null, $isOffice]);
    $insertId = (int)$db->lastInsertId();

    // Proximity auto-start — uses existing CRM logic, session-free via $preloadedVisits.
    // Only runs when there is no active job timer (guard is inside the function).
    // We load today's visits fresh (no session cache) — acceptable at 30s mobile ping rate.
    $autoStartResult = null;
    $activeTimer = getActiveJobTimer($userId);
    if (!$activeTimer) {
        $todayVisits    = getAllJobsForDate(date('Y-m-d'));
        $autoStartResult = checkProximityAutoStart($userId, $lat, $lng, $accuracy, $todayVisits);
    }

    echo json_encode([
        'success'      => true,
        'id'           => $insertId,
        'auto_started' => $autoStartResult,
    ]);

} catch (Throwable $e) {
    error_log('[schedule/location] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
