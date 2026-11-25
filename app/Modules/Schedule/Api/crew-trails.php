<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/crew-trails.php
 *
 * Mobile Crew Trails API — JWT-authenticated GPS trail + live position feed
 *
 * GET /api/schedule/crew-trails?date=YYYY-MM-DD
 * Authorization: Bearer <jwt>
 *
 * Crew (role=user): returns the caller's own trail + live position only.
 * Admin/manager:    returns trails + live positions for every tracked crew member.
 *
 * Response 200:
 * {
 *   "success": true,
 *   "date": "2026-05-04",
 *   "is_admin": true|false,
 *   "current_user_id": 7,
 *   "routes": [
 *     {
 *       "user_id": 7,
 *       "full_name": "Jane Crew",
 *       "device_type": "personal"|"truck",
 *       "points": [
 *         { "lat": 49.123, "lng": -122.456, "accuracy": 12, "time": "2026-05-04 09:14:21" },
 *         ...
 *       ]
 *     }
 *   ],
 *   "live": [
 *     {
 *       "user_id": 7,
 *       "full_name": "Jane Crew",
 *       "device_type": "personal"|"truck",
 *       "lat": 49.123,
 *       "lng": -122.456,
 *       "accuracy_meters": 12,
 *       "seconds_ago": 34,
 *       "is_clocked_in": 1
 *     }
 *   ]
 * }
 *
 * The endpoint mirrors the web /api/team/crew-location?action=day_routes + ?action=live
 * shape but is JWT-authed (no $_SESSION) and respects per-user visibility.
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

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];
    $role    = (string)($jwtUser['role'] ?? 'user');
    $isAdmin = in_array($role, ['admin', 'manager'], true);

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'GET required']);
        exit;
    }

    // Date in Pacific time (matches the rest of the schedule API).
    $tz   = new DateTimeZone('America/Vancouver');
    $date = isset($_GET['date']) ? trim((string)$_GET['date']) : (new DateTime('now', $tz))->format('Y-m-d');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD.']);
        exit;
    }

    $dayStart = (new DateTime($date . ' 00:00:00', $tz))->getTimestamp();
    $dayEnd   = (new DateTime($date . ' 23:59:59', $tz))->getTimestamp();

    $db = getDB();

    // ── Routes (trail polylines) ──────────────────────────────────────────────
    // Filter by caller for non-admins so crew can only see their own trail.
    $routesSql = "
        SELECT
            clh.crew_id            AS user_id,
            u.full_name            AS full_name,
            IFNULL(u.device_type, 'personal') AS device_type,
            clh.latitude           AS lat,
            clh.longitude          AS lng,
            clh.accuracy_meters    AS accuracy_meters,
            UNIX_TIMESTAMP(clh.timestamp) AS epoch
        FROM crew_location_history clh
        INNER JOIN users u ON u.id = clh.crew_id
        WHERE u.is_active = 1
          AND u.location_tracking_enabled = 1
          AND UNIX_TIMESTAMP(clh.timestamp) >= ?
          AND UNIX_TIMESTAMP(clh.timestamp) <= ?
    ";
    $params = [$dayStart, $dayEnd];

    if (!$isAdmin) {
        $routesSql .= " AND clh.crew_id = ?";
        $params[] = $userId;
    }

    $routesSql .= " ORDER BY clh.crew_id ASC, clh.timestamp ASC";

    $stmt = $db->prepare($routesSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $routes = [];
    foreach ($rows as $row) {
        $uid = (int)$row['user_id'];
        if (!isset($routes[$uid])) {
            $routes[$uid] = [
                'user_id'     => $uid,
                'full_name'   => (string)$row['full_name'],
                'device_type' => (string)$row['device_type'],
                'points'      => [],
            ];
        }
        $routes[$uid]['points'][] = [
            'lat'      => (float)$row['lat'],
            'lng'      => (float)$row['lng'],
            'accuracy' => $row['accuracy_meters'] !== null ? (int)$row['accuracy_meters'] : null,
            'time'     => (new DateTime('@' . (int)$row['epoch']))
                            ->setTimezone($tz)
                            ->format('Y-m-d H:i:s'),
        ];
    }

    // ── Live positions ────────────────────────────────────────────────────────
    // Latest point per user within the last 24 hours. Mirrors the web "live" feed
    // but scopes to the caller for non-admins.
    $liveSql = "
        SELECT
            u.id                   AS user_id,
            u.full_name            AS full_name,
            IFNULL(u.device_type, 'personal') AS device_type,
            clh.latitude           AS lat,
            clh.longitude          AS lng,
            clh.accuracy_meters    AS accuracy_meters,
            UNIX_TIMESTAMP(clh.timestamp) AS last_epoch,
            CASE WHEN tce.id IS NOT NULL THEN 1 ELSE 0 END AS is_clocked_in
        FROM users u
        INNER JOIN (
            SELECT crew_id, MAX(UNIX_TIMESTAMP(timestamp)) AS max_epoch
            FROM crew_location_history
            WHERE UNIX_TIMESTAMP(timestamp) > UNIX_TIMESTAMP() - 86400
            GROUP BY crew_id
        ) latest ON latest.crew_id = u.id
        INNER JOIN crew_location_history clh
            ON clh.crew_id = latest.crew_id
            AND UNIX_TIMESTAMP(clh.timestamp) = latest.max_epoch
        LEFT JOIN time_clock_entries tce
            ON tce.user_id = u.id AND tce.status = 'active' AND tce.clock_out IS NULL
        WHERE u.is_active = 1
          AND u.location_tracking_enabled = 1
    ";
    $liveParams = [];
    if (!$isAdmin) {
        $liveSql .= " AND u.id = ?";
        $liveParams[] = $userId;
    }
    $liveSql .= " ORDER BY u.full_name ASC";

    $stmt = $db->prepare($liveSql);
    $stmt->execute($liveParams);
    $liveRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $nowEpoch = time();
    $live = [];
    foreach ($liveRows as $row) {
        $live[] = [
            'user_id'         => (int)$row['user_id'],
            'full_name'       => (string)$row['full_name'],
            'device_type'     => (string)$row['device_type'],
            'lat'             => (float)$row['lat'],
            'lng'             => (float)$row['lng'],
            'accuracy_meters' => $row['accuracy_meters'] !== null ? (int)$row['accuracy_meters'] : null,
            'seconds_ago'     => max(0, $nowEpoch - (int)$row['last_epoch']),
            'is_clocked_in'   => (int)$row['is_clocked_in'],
        ];
    }

    echo json_encode([
        'success'         => true,
        'date'            => $date,
        'is_admin'        => $isAdmin,
        'current_user_id' => $userId,
        'routes'          => array_values($routes),
        'live'            => $live,
    ]);

} catch (Throwable $e) {
    error_log('[schedule/crew-trails] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
