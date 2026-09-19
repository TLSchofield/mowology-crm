<?php
/**
 * Crew Location Heartbeat — lightweight live-position update
 *
 * POST /crm/api/location-ping.php
 *   Body: { lat, lng, accuracy?, device_label? }
 *   Auth: JWT Bearer header (mobile/iOS), or MOWOSESS session cookie (Capacitor WebView)
 *
 * Inserts one row into crew_location_history so the Crew Map's ?action=live
 * query (which reads the latest row per user) picks it up. No proximity
 * auto-start, no SMS failsafes, no offline-queue handling — just a fast write.
 *
 * Response 200: { "success": true, "id": int }
 * Response 200: { "success": true, "skipped": true, "reason": "rate_limited" }
 * Response 401: { "error": "Unauthorized", "code": "no_auth" }
 * Response 400: { "error": "<reason>" }
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

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

try {
    require_once APP_ROOT . '/Core/config.php';
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';

    // Auth — try JWT Bearer first, then fall back to session cookie.
    // Either path resolves to $userId; missing both → 401.
    // Auth runs BEFORE the method check so any unauthenticated probe gets a
    // uniform 401 (verify step in the deploy spec relies on this).
    $userId = 0;
    $jwtUser = getJwtUser();
    if ($jwtUser && !empty($jwtUser['id'])) {
        $userId = (int)$jwtUser['id'];
    } else {
        require_once PUBLIC_ROOT . '/loginAuth/auth.php';
        if (isLoggedIn()) {
            $sessionUser = getCurrentUser();
            $userId = (int)($sessionUser['id'] ?? 0);
        }
    }

    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'code' => 'no_auth']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required']);
        exit;
    }

    // Release the session lock as early as possible — this endpoint never writes
    // to $_SESSION, and we don't want the heartbeat to block page navigation.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Same gate as every other ingest path (TrackingIngestService): nothing is stored
    // unless the user is active, opted in, consented (when required) and the fix falls
    // inside one of their clock entries. This endpoint previously had NO gate at all.
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/timeclock-functions.php';
    require_once APP_ROOT . '/Modules/Team/Services/GeofenceService.php';
    require_once APP_ROOT . '/Modules/Team/Services/TrackingIngestService.php';

    $input  = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $nowTs  = time();
    $db     = getDB();
    $ingest = new TrackingIngestService($db);
    $points = TrackingIngestService::normalizePoints($input, $nowTs);
    if (!$points) {
        http_response_code(400);
        echo json_encode(['error' => 'lat and lng are required']);
        exit;
    }

    $flags     = $ingest->userFlags($userId);
    $consentOk = $ingest->consentOk($userId);
    $result    = ['stored' => 0, 'last_id' => 0, 'accepted' => [], 'rejected' => []];
    if ($flags['active'] && $flags['tracking'] && $consentOk) {
        $geofence = new GeofenceService($db);
        $result   = $ingest->ingest(
            $userId, $points, $nowTs,
            static fn (int $visitId): bool => userIsCrewOnVisit($visitId, $userId),
            static fn (float $lat, float $lng): bool => $geofence->isOfficePing($userId, $lat, $lng)
        );
    }

    $timer = getLiveJobTimer($userId);
    echo json_encode([
        'success'  => true,
        'id'       => $result['last_id'],
        'skipped'  => $result['stored'] === 0,
        'stored'   => $result['stored'],
        'accepted' => $result['accepted'],
        'rejected' => $result['rejected'],
        'policy'   => TrackingIngestService::policy(
            $flags['active'], $flags['tracking'], $consentOk,
            (bool)getActiveClockEntry($userId), $timer ? (int)$timer['visit_id'] : null
        ),
    ]);

} catch (Throwable $e) {
    error_log('[location-ping] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
