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

    require_once APP_ROOT . '/Modules/Team/Services/TrackingIngestService.php';

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $nowTs  = time();
    $db     = getDB();
    $ingest = new TrackingIngestService($db);

    $points = TrackingIngestService::normalizePoints($input, $nowTs);
    $events = TrackingIngestService::normalizeEvents($input, $nowTs);
    if (!$points && !$events) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'lat and lng are required']);
        exit;
    }

    // The work-hours boundary is enforced HERE, not trusted to the client. A user who
    // is inactive, opted out, without consent, or off the clock has nothing stored —
    // and is told so, which is how a server-side clock-out reaches the phone.
    $flags      = $ingest->userFlags($userId);
    $consentOk  = $ingest->consentOk($userId);
    $clockedIn  = (bool)getActiveClockEntry($userId);
    $mayCollect = $flags['active'] && $flags['tracking'] && $consentOk;

    $result = ['accepted' => [], 'rejected' => [], 'stored' => 0, 'newest' => null, 'last_id' => 0];
    if ($mayCollect) {
        // Not gated on $clockedIn: a queue replayed after clock-out is still judged
        // point-by-point against the shifts it was recorded in.
        $geofence = new GeofenceService($db);
        $result   = $ingest->ingest(
            $userId, $points, $nowTs,
            static fn (int $visitId): bool => userIsCrewOnVisit($visitId, $userId),
            static fn (float $lat, float $lng): bool => $geofence->isOfficePing($userId, $lat, $lng)
        );
    } else {
        foreach ($points as $p) {
            $result['rejected'][] = ['id' => $p['id'], 'reason' => 'tracking_not_allowed', 'retryable' => false];
        }
    }

    // Proximity auto-start — only from a FRESH fix. A replayed queue describes where
    // the crew was hours ago; acting on it started jobs they had already left.
    $autoStartResult = null;
    $activeTimer     = $mayCollect ? getLiveJobTimer($userId) : null;
    $newest          = $result['newest'];
    if ($mayCollect && $clockedIn && !$activeTimer && $newest
        && ($nowTs - $newest['ts']) <= TrackingIngestService::FRESH_SECONDS) {
        $autoStartResult = checkProximityAutoStart(
            $userId, $newest['lat'], $newest['lng'], (float)($newest['acc'] ?? 50.0),
            getAllJobsForDate(date('Y-m-d'))
        );
        if ($autoStartResult) {
            $activeTimer = ['visit_id' => $autoStartResult['visit_id']];
        }
    }

    // Departure: a live auto-started timer + clearly away from the site for a few minutes →
    // stop the TIMER at the moment they left (the visit is NOT completed by a GPS guess).
    $autoStopResult = null;
    if ($mayCollect && $activeTimer && !$autoStartResult && $newest
        && ($nowTs - $newest['ts']) <= TrackingIngestService::FRESH_SECONDS) {
        require_once APP_ROOT . '/Modules/Team/Services/DepartureAutoStopService.php';
        $autoStopResult = DepartureAutoStopService::check($db, $userId, $nowTs);
        if ($autoStopResult) {
            $activeTimer = null;        // off the job → policy drops back to the baseline tier
        }
    }

    if (isset($input['device']) && is_array($input['device'])) {
        $ingest->recordHealth($userId, $input['device'], $newest['ts'] ?? null);
    }

    // Compliance events ride along with the fixes (setup gate skipped, overrides, …). They
    // are about the device, not the shift, so they are stored whether or not fixes may be.
    $eventResult = $ingest->ingestComplianceEvents($userId, $events);

    echo json_encode([
        'success'      => true,
        'id'           => $result['last_id'],
        // Legacy single-ping clients read `skipped`; they must NOT treat it as delivered-and-done
        // when nothing was stored for a reason other than being a duplicate.
        'skipped'      => $result['stored'] === 0,
        'stored'       => $result['stored'],
        'accepted'     => $result['accepted'],
        'rejected'     => $result['rejected'],
        'auto_started' => $autoStartResult,
        'auto_stopped' => $autoStopResult,
        'events_stored' => $eventResult['stored'],
        'events_done'   => $eventResult['done'],
        'policy'       => TrackingIngestService::policy(
            $flags['active'], $flags['tracking'], $consentOk, $clockedIn,
            $activeTimer ? (int)$activeTimer['visit_id'] : null
        ),
    ]);

} catch (Throwable $e) {
    error_log('[schedule/location] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
