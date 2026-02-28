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
        // GET never writes to $_SESSION — release lock immediately so concurrent page
        // navigations aren't blocked waiting for this query to complete.
        session_write_close();
        $action = $_GET['action'] ?? '';

        if ($action === 'live') {
            // Admin/manager only — get latest position for all tracked employees
            if (!in_array($user['role'], ['admin', 'manager'])) {
                throw new Exception('Access denied');
            }

            // Get latest location per tracked, clocked-in user.
            // MySQL 5.7 compatible: use subquery for max timestamp per user.
            // Use UNIX_TIMESTAMP() for seconds_ago and window filter — this is
            // timezone-agnostic and works whether pings are stored in MySQL time
            // (legacy) or PHP/Pacific time (after the NOW() fix).
            $stmt = $db->query("
                SELECT
                    u.id as user_id,
                    u.full_name,
                    u.role,
                    IFNULL(u.device_type, 'personal') AS device_type,
                    clh.latitude as lat,
                    clh.longitude as lng,
                    clh.accuracy_meters,
                    clh.timestamp as last_update,
                    UNIX_TIMESTAMP(clh.timestamp) as last_epoch,
                    (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(clh.timestamp)) as seconds_ago,
                    CASE WHEN tce.id IS NOT NULL THEN 1 ELSE 0 END as is_clocked_in
                FROM users u
                INNER JOIN (
                    SELECT crew_id, MAX(UNIX_TIMESTAMP(timestamp)) as max_epoch
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
                ORDER BY u.full_name ASC
            ");
            $crew = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Recalculate seconds_ago using PHP time() vs the true UTC epoch from MySQL.
            // This is reliable regardless of how the ping timestamp was stored (EST or Pacific).
            $nowEpoch = time();
            foreach ($crew as &$c) {
                $c['seconds_ago'] = max(0, $nowEpoch - (int)$c['last_epoch']);
            }
            unset($c);

            echo json_encode(['success' => true, 'crew' => $crew]);

        } elseif ($action === 'day_routes') {
            // Admin/manager only — get all location points for a given date, grouped by crew
            if (!in_array($user['role'], ['admin', 'manager'])) {
                throw new Exception('Access denied');
            }

            $date = $_GET['date'] ?? (new DateTime('now', new DateTimeZone('America/Vancouver')))->format('Y-m-d');
            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new Exception('Invalid date format. Use YYYY-MM-DD');
            }

            // Get all location points for tracked users on the given date.
            // Pings are stored in Pacific time (America/Vancouver). Use PHP's strtotime
            // with explicit Pacific day boundaries for the filter; compare via UNIX_TIMESTAMP()
            // so the filter is timezone-agnostic against whatever is stored.
            $tz = new DateTimeZone('America/Vancouver');
            $dayStart = (new DateTime($date . ' 00:00:00', $tz))->getTimestamp();
            $dayEnd   = (new DateTime($date . ' 23:59:59', $tz))->getTimestamp();

            $stmt = $db->prepare("
                SELECT
                    clh.crew_id as user_id,
                    u.full_name,
                    clh.latitude as lat,
                    clh.longitude as lng,
                    clh.accuracy_meters,
                    clh.timestamp,
                    UNIX_TIMESTAMP(clh.timestamp) as epoch
                FROM crew_location_history clh
                INNER JOIN users u ON u.id = clh.crew_id
                WHERE u.is_active = 1
                  AND u.location_tracking_enabled = 1
                  AND UNIX_TIMESTAMP(clh.timestamp) >= ?
                  AND UNIX_TIMESTAMP(clh.timestamp) <= ?
                ORDER BY clh.crew_id ASC, clh.timestamp ASC
            ");
            $stmt->execute([$dayStart, $dayEnd]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // UNIX_TIMESTAMP() returns true UTC epoch — convert directly to Pacific.
            $toPacific = function(string $ts, int $epoch) use ($tz): string {
                return (new DateTime('@' . $epoch))->setTimezone($tz)->format('Y-m-d H:i:s');
            };

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
                    'time' => $toPacific($row['timestamp'], (int)$row['epoch'])
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
        $isProximityOnly = !empty($input['proximity_check']); // One-shot check from widget
        $isQueued = !empty($input['queued_at']); // Replayed offline ping

        if (!$lat || !$lng) {
            throw new Exception('Latitude and longitude required');
        }

        // Check user's tracking settings and device type
        $trackStmt = $db->prepare("SELECT location_tracking_enabled, IFNULL(device_type, 'personal') AS device_type FROM users WHERE id = ?");
        $trackStmt->execute([$user['id']]);
        $trackRow = $trackStmt->fetch(PDO::FETCH_ASSOC);
        if (!$trackRow || !$trackRow['location_tracking_enabled']) {
            throw new Exception('Tracking not enabled');
        }

        $isTruck = ($trackRow['device_type'] === 'truck');

        // Truck devices can report GPS without being clocked in.
        // Personal devices must be clocked in — UNLESS this is a proximity-only check
        // (the proximity engine handles auto-clock-in itself).
        if (!$isTruck && !$isProximityOnly) {
            $clockEntry = getActiveClockEntry($user['id']);
            if (!$clockEntry) {
                throw new Exception('Not clocked in');
            }
        }

        // For proximity-only checks, skip location storage and rate limiting — just run the check
        if ($isProximityOnly) {
            require_once CRM_INCLUDES . '/plan-functions.php';
            $autoStartResult = checkProximityAutoStart(
                (int)$user['id'], $lat, $lng, (float)($accuracy ?? 50)
            );
            echo json_encode([
                'success' => true,
                'proximity_only' => true,
                'auto_started' => $autoStartResult
            ]);
            exit;
        }

        // Rate limit: reject if last entry < 10 seconds ago (use UNIX_TIMESTAMP for tz-agnostic comparison)
        $rateStmt = $db->prepare("
            SELECT (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(timestamp)) as seconds_ago
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

        // Insert location — MySQL NOW() is Pacific (session TZ set in Database::pdo()).
        $stmt = $db->prepare("
            INSERT INTO crew_location_history (crew_id, latitude, longitude, accuracy_meters, visit_id, timestamp)
            VALUES (?, ?, ?, ?, NULL, NOW())
        ");
        $stmt->execute([$user['id'], $lat, $lng, $accuracy]);
        $insertId = (int)$db->lastInsertId();

        // Proximity auto-start check (skip for stale offline-queued pings)
        $autoStartResult = null;
        if (!$isQueued) {
            // Rate-limit the proximity check — only run every 3rd ping (~90s)
            if (!isset($_SESSION['proximity_check_counter'])) {
                $_SESSION['proximity_check_counter'] = 0;
            }
            $_SESSION['proximity_check_counter']++;
            $runProximityNow = ($_SESSION['proximity_check_counter'] >= 3);
            if ($runProximityNow) {
                $_SESSION['proximity_check_counter'] = 0;
            }
            // All session writes are done — release the lock BEFORE the heavy proximity
            // check (multiple DB queries + haversine calcs). Without this, the session
            // file lock is held across checkProximityAutoStart() and blocks concurrent
            // page navigations at session_start(), causing ERR_FAILED on Android.
            session_write_close();

            if ($runProximityNow) {
                require_once CRM_INCLUDES . '/plan-functions.php';
                $autoStartResult = checkProximityAutoStart(
                    (int)$user['id'], $lat, $lng, (float)($accuracy ?? 50)
                );
            }
        } else {
            session_write_close(); // Queued pings — no session writes needed
        }

        echo json_encode([
            'success' => true,
            'id' => $insertId,
            'auto_started' => $autoStartResult
        ]);

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
