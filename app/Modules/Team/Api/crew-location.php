<?php
/**
 * Crew Location API — continuous GPS tracking
 * POST: Report current position (employee's browser sends every 30s)
 * GET ?action=live: Get latest position for all tracked, clocked-in employees (admin/manager)
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
    require_once CRM_INCLUDES . '/timeclock-functions.php';

    requireLogin();
    $user = getCurrentUser();
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';

        if ($action === 'live') {
            // Admin/manager only — get latest position for all tracked employees
            if (!in_array($user['role'], ['admin', 'manager'])) {
                throw new Exception('Access denied');
            }

            // Get latest location per tracked, clocked-in user
            // MySQL 5.7 compatible: use subquery for max timestamp per user
            $stmt = $db->query("
                SELECT
                    u.id as user_id,
                    u.full_name,
                    u.role,
                    clh.latitude as lat,
                    clh.longitude as lng,
                    clh.accuracy_meters,
                    clh.timestamp as last_update,
                    TIMESTAMPDIFF(SECOND, clh.timestamp, NOW()) as seconds_ago,
                    CASE WHEN tce.id IS NOT NULL THEN 1 ELSE 0 END as is_clocked_in
                FROM users u
                INNER JOIN (
                    SELECT crew_id, MAX(timestamp) as max_ts
                    FROM crew_location_history
                    WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    GROUP BY crew_id
                ) latest ON latest.crew_id = u.id
                INNER JOIN crew_location_history clh
                    ON clh.crew_id = latest.crew_id AND clh.timestamp = latest.max_ts
                LEFT JOIN time_clock_entries tce
                    ON tce.user_id = u.id AND tce.status = 'active' AND tce.clock_out IS NULL
                WHERE u.is_active = 1
                  AND u.location_tracking_enabled = 1
                ORDER BY u.full_name ASC
            ");
            $crew = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'crew' => $crew]);

        } elseif ($action === 'day_routes') {
            // Admin/manager only — get all location points for a given date, grouped by crew
            if (!in_array($user['role'], ['admin', 'manager'])) {
                throw new Exception('Access denied');
            }

            $date = $_GET['date'] ?? date('Y-m-d');
            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new Exception('Invalid date format. Use YYYY-MM-DD');
            }

            // Get all location points for tracked users on the given date.
            // PHP runs in America/Vancouver but MySQL NOW() is in a different timezone
            // (currently ~3h ahead). We detect the offset dynamically and convert
            // the requested Pacific day boundaries to MySQL server timestamps.
            $mysqlOffsetRow = $db->query("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) as offset_sec")->fetch(PDO::FETCH_ASSOC);
            $mysqlFromUtc = (int)$mysqlOffsetRow['offset_sec']; // e.g., -18000 for EST
            $phpFromUtc   = (int)date('Z');                      // e.g., -28800 for PST
            $shift = $mysqlFromUtc - $phpFromUtc;                // seconds to add to Pacific → MySQL

            // Convert Pacific day boundaries to MySQL server time
            $dayStartEpoch = strtotime($date . ' 00:00:00');
            $dayEndEpoch   = strtotime($date . ' 23:59:59');
            $mysqlStart = date('Y-m-d H:i:s', $dayStartEpoch + $shift);
            $mysqlEnd   = date('Y-m-d H:i:s', $dayEndEpoch + $shift);

            $stmt = $db->prepare("
                SELECT
                    clh.crew_id as user_id,
                    u.full_name,
                    clh.latitude as lat,
                    clh.longitude as lng,
                    clh.accuracy_meters,
                    clh.timestamp
                FROM crew_location_history clh
                INNER JOIN users u ON u.id = clh.crew_id
                WHERE u.is_active = 1
                  AND u.location_tracking_enabled = 1
                  AND clh.timestamp >= ?
                  AND clh.timestamp <= ?
                ORDER BY clh.crew_id ASC, clh.timestamp ASC
            ");
            $stmt->execute([$mysqlStart, $mysqlEnd]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group by user
            $routes = [];
            foreach ($rows as $row) {
                $uid = $row['user_id'];
                if (!isset($routes[$uid])) {
                    $routes[$uid] = [
                        'user_id' => (int)$uid,
                        'full_name' => $row['full_name'],
                        'points' => []
                    ];
                }
                $routes[$uid]['points'][] = [
                    'lat' => (float)$row['lat'],
                    'lng' => (float)$row['lng'],
                    'accuracy' => $row['accuracy_meters'] ? (int)$row['accuracy_meters'] : null,
                    'time' => $row['timestamp']
                ];
            }

            echo json_encode([
                'success' => true,
                'date' => $date,
                'routes' => array_values($routes)
            ]);

        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: live, day_routes']);
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Report position — employee sends this every 30 seconds
        $input = json_decode(file_get_contents('php://input'), true);

        $lat = isset($input['lat']) ? (float)$input['lat'] : null;
        $lng = isset($input['lng']) ? (float)$input['lng'] : null;
        $accuracy = isset($input['accuracy']) ? (int)round((float)$input['accuracy']) : null;

        if (!$lat || !$lng) {
            throw new Exception('Latitude and longitude required');
        }

        // Verify user is clocked in
        $clockEntry = getActiveClockEntry($user['id']);
        if (!$clockEntry) {
            throw new Exception('Not clocked in');
        }

        // Verify user has tracking enabled
        $trackStmt = $db->prepare("SELECT location_tracking_enabled FROM users WHERE id = ?");
        $trackStmt->execute([$user['id']]);
        $trackRow = $trackStmt->fetch(PDO::FETCH_ASSOC);
        if (!$trackRow || !$trackRow['location_tracking_enabled']) {
            throw new Exception('Tracking not enabled');
        }

        // Rate limit: reject if last entry < 10 seconds ago (use MySQL time to avoid timezone mismatch)
        $rateStmt = $db->prepare("
            SELECT TIMESTAMPDIFF(SECOND, timestamp, NOW()) as seconds_ago
            FROM crew_location_history
            WHERE crew_id = ?
            ORDER BY timestamp DESC LIMIT 1
        ");
        $rateStmt->execute([$user['id']]);
        $lastRow = $rateStmt->fetch(PDO::FETCH_ASSOC);
        if ($lastRow && (int)$lastRow['seconds_ago'] < 10) {
            echo json_encode(['success' => true, 'skipped' => true, 'reason' => 'rate_limited']);
            exit;
        }

        // Insert location
        $stmt = $db->prepare("
            INSERT INTO crew_location_history (crew_id, latitude, longitude, accuracy_meters, visit_id, timestamp)
            VALUES (?, ?, ?, ?, NULL, NOW())
        ");
        $stmt->execute([$user['id'], $lat, $lng, $accuracy]);

        echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }

} catch (PDOException $e) {
    error_log('Crew Location API DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
