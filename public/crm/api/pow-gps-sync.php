<?php
/**
 * Proof of Work — GPS Batch Sync Endpoint
 * ─────────────────────────────────────────
 * Receives GPS breadcrumb points for a specific visit from the mobile app
 * or browser-based fallback. Stores in visit_gps_points.
 *
 * POST JSON body:
 *   {
 *     "action": "sync_points",
 *     "visit_id": 42,
 *     "points": [
 *       {"lat":49.2827,"lng":-123.1207,"accuracy":5.2,"speed":0.8,
 *        "heading":270,"altitude":12.5,"source":"fused","timestamp":1740000000000},
 *       ...
 *     ]
 *   }
 *
 * Returns: { "success":true, "inserted":N, "skipped":M }
 *
 * Auth: session cookie (MOWOSESS). Crew can only sync their own assigned visits.
 * CSRF: not required for API endpoints called by native app WorkManager.
 *       Origin header checked for browser callers.
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    requireLogin();
    $user = getCurrentUser();
    // Release session lock immediately — this endpoint never writes to $_SESSION.
    // Without this, batch GPS syncs hold the session file lock while inserting
    // many DB rows, blocking concurrent WebView navigations at session_start()
    // and causing Android ERR_FAILED timeouts that look like logouts.
    session_write_close();
    $db   = getDB();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid JSON body');
    }

    $action  = $input['action'] ?? '';
    $visitId = isset($input['visit_id']) ? (int)$input['visit_id'] : 0;

    if ($action !== 'sync_points') {
        throw new InvalidArgumentException('Unknown action. Use: sync_points');
    }
    if ($visitId < 1) {
        throw new InvalidArgumentException('visit_id is required');
    }

    // AuthZ: verify this crew member is assigned to the visit (or is admin)
    $stmt = $db->prepare("
        SELECT v.id, v.assigned_crew_id, v.status, v.locked_at
        FROM job_visits v
        WHERE v.id = ?
    ");
    $stmt->execute([$visitId]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$visit) {
        http_response_code(404);
        echo json_encode(['error' => 'Visit not found']);
        exit;
    }

    $isAdmin = ($user['role'] ?? '') === 'admin' || userHasPermission('jobs.edit');
    if (!$isAdmin && (int)$visit['assigned_crew_id'] !== (int)$user['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized for this visit']);
        exit;
    }

    if ($visit['locked_at'] !== null) {
        http_response_code(409);
        echo json_encode(['error' => 'Visit is locked. Unlock before syncing new GPS data.']);
        exit;
    }

    $points  = $input['points'] ?? [];
    $result  = insertGpsPoints($db, $visitId, $points);

    // Update the cached count on the visit
    $db->prepare("
        UPDATE job_visits
        SET gps_points_count = (SELECT COUNT(*) FROM visit_gps_points WHERE visit_id = ?)
        WHERE id = ?
    ")->execute([$visitId, $visitId]);

    echo json_encode([
        'success'  => true,
        'inserted' => $result['inserted'],
        'skipped'  => $result['skipped'],
    ]);

} catch (PDOException $e) {
    error_log('PoW GPS Sync DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('PoW GPS Sync error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

/**
 * Batch-insert GPS points into visit_gps_points.
 * Deduplicates by (visit_id, ts) within a 1-second window.
 * Validates lat/lng ranges and accuracy threshold (>200m rejected).
 */
function insertGpsPoints(PDO $db, int $visitId, array $points): array
{
    if (empty($points)) {
        return ['inserted' => 0, 'skipped' => 0];
    }

    $inserted = 0;
    $skipped  = 0;

    $stmtCheck = $db->prepare("
        SELECT COUNT(*) FROM visit_gps_points
        WHERE visit_id = ?
          AND ABS(TIMESTAMPDIFF(SECOND, ts, ?)) < 2
          AND ABS(lat - ?) < 0.000015
          AND ABS(lng - ?) < 0.000015
    ");

    $stmtInsert = $db->prepare("
        INSERT INTO visit_gps_points
            (visit_id, ts, lat, lng, accuracy_m, speed_mps, heading, altitude_m, source)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($points as $pt) {
        $lat       = isset($pt['lat'])       ? (float)$pt['lat']       : null;
        $lng       = isset($pt['lng'])       ? (float)$pt['lng']       : null;
        $accuracy  = isset($pt['accuracy'])  ? (float)$pt['accuracy']  : null;
        $speed     = isset($pt['speed'])     ? (float)$pt['speed']     : null;
        $heading   = isset($pt['heading'])   ? (float)$pt['heading']   : null;
        $altitude  = isset($pt['altitude'])  ? (float)$pt['altitude']  : null;
        $source    = isset($pt['source'])    ? substr((string)$pt['source'], 0, 30) : 'gps';
        $tsRaw     = isset($pt['timestamp']) ? (int)$pt['timestamp']   : 0;

        // Validate
        if ($lat === null || $lng === null || $tsRaw === 0) { $skipped++; continue; }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) { $skipped++; continue; }
        if ($accuracy !== null && $accuracy > 200) { $skipped++; continue; } // too inaccurate

        // Convert epoch ms → MySQL datetime
        $ts = date('Y-m-d H:i:s', (int)($tsRaw / 1000));

        // Dedup check
        $stmtCheck->execute([$visitId, $ts, $lat, $lng]);
        if ((int)$stmtCheck->fetchColumn() > 0) { $skipped++; continue; }

        $stmtInsert->execute([
            $visitId, $ts, $lat, $lng,
            $accuracy, $speed, $heading, $altitude, $source
        ]);
        $inserted++;
    }

    return ['inserted' => $inserted, 'skipped' => $skipped];
}
