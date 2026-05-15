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

    $input = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $lat = isset($input['lat']) ? (float)$input['lat'] : null;
    $lng = isset($input['lng']) ? (float)$input['lng'] : null;
    $accuracy = isset($input['accuracy']) ? (float)$input['accuracy'] : null;

    if ($lat === null || $lng === null) {
        http_response_code(400);
        echo json_encode(['error' => 'lat and lng are required']);
        exit;
    }
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        http_response_code(400);
        echo json_encode(['error' => 'Coordinates out of range']);
        exit;
    }

    $db = getDB();

    // Rate limit: reject if last entry < 10 seconds ago.
    // Mirrors the rule in crew-location.php and schedule/Api/location.php so
    // history rows stay consistent across the three ingest paths.
    $rateStmt = $db->prepare("
        SELECT (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(timestamp)) AS seconds_ago
        FROM crew_location_history
        WHERE crew_id = ?
        ORDER BY timestamp DESC LIMIT 1
    ");
    $rateStmt->execute([$userId]);
    $lastRow = $rateStmt->fetch(PDO::FETCH_ASSOC);
    if ($lastRow && (int)$lastRow['seconds_ago'] < 10) {
        echo json_encode(['success' => true, 'skipped' => true, 'reason' => 'rate_limited']);
        exit;
    }

    $stmt = $db->prepare("
        INSERT INTO crew_location_history (crew_id, latitude, longitude, accuracy_meters, visit_id, timestamp)
        VALUES (?, ?, ?, ?, NULL, NOW())
    ");
    $stmt->execute([
        $userId,
        $lat,
        $lng,
        $accuracy !== null ? (int)round($accuracy) : null,
    ]);

    echo json_encode([
        'success' => true,
        'id' => (int)$db->lastInsertId(),
    ]);

} catch (Throwable $e) {
    error_log('[location-ping] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
