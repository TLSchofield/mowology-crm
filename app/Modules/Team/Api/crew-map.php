<?php
declare(strict_types=1);

/**
 * app/Modules/Team/Api/crew-map.php
 *
 * Mobile Crew Map API — JWT authenticated, admin only.
 *
 * GET /api/team/crew-map
 *
 * Returns the latest GPS position for every tracked, active crew member
 * seen in the past 24 hours, plus their clock-in state.
 *
 * Response:
 *   {success, crew: [{
 *     user_id, full_name, role, device_type,
 *     lat, lng, accuracy_meters,
 *     last_update, seconds_ago, is_clocked_in
 *   }]}
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

if ($jwtUser['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'GET only']);
    exit;
}

$db = getDB();

// Latest location per crew member seen in the past 24 h.
// MySQL 5.7-compatible (subquery for max epoch, no window functions).
$stmt = $db->query("
    SELECT
        u.id          AS user_id,
        u.full_name,
        u.role,
        IFNULL(u.device_type, 'personal') AS device_type,
        clh.latitude  AS lat,
        clh.longitude AS lng,
        clh.accuracy_meters,
        clh.timestamp AS last_update,
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
    ORDER BY u.full_name ASC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nowEpoch = time();
$crew = [];
foreach ($rows as $row) {
    $crew[] = [
        'user_id'        => (int)$row['user_id'],
        'full_name'      => $row['full_name'],
        'role'           => $row['role'],
        'device_type'    => $row['device_type'],
        'lat'            => (float)$row['lat'],
        'lng'            => (float)$row['lng'],
        'accuracy_meters' => $row['accuracy_meters'] !== null ? (int)$row['accuracy_meters'] : null,
        'last_update'    => $row['last_update'],
        'seconds_ago'    => max(0, $nowEpoch - (int)$row['last_epoch']),
        'is_clocked_in'  => (bool)(int)$row['is_clocked_in'],
    ];
}

echo json_encode(['success' => true, 'crew' => $crew]);
