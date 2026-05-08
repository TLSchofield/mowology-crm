<?php
/**
 * Dashboard Map Data API
 * Returns combined payload for the live operations map widget:
 * - Crew current positions + day route trails
 * - Today's scheduled/active jobs with property locations
 * - Office location
 * - Derived crew status (at_job, at_office, in_transit, etc.)
 *
 * GET /crm/api/dashboard-map-data.php
 */
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../../loginAuth/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$user = getCurrentUser();

// Admin/manager only
if (!in_array($user['role'], ['admin', 'manager'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Release session lock immediately — this is a read-only endpoint
session_write_close();

$db = getDB();

try {
    $tz = new DateTimeZone('America/Vancouver');
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $nowEpoch = time();

    // ── 1. Crew live positions ──────────────────────────────────────────
    $crewStmt = $db->query("
        SELECT
            u.id AS user_id,
            u.full_name,
            IFNULL(u.device_type, 'personal') AS device_type,
            clh.latitude AS lat,
            clh.longitude AS lng,
            clh.accuracy_meters,
            UNIX_TIMESTAMP(clh.timestamp) AS last_epoch,
            CASE WHEN tce.id IS NOT NULL THEN 1 ELSE 0 END AS is_clocked_in,
            DATE_FORMAT(tce.clock_in, '%H:%i') AS clock_in_time
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
        ORDER BY u.full_name ASC
    ");
    $crewRows = $crewStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 2. Crew day routes (today only) ─────────────────────────────────
    $dayStart = (new DateTime($today . ' 00:00:00', $tz))->getTimestamp();
    $dayEnd   = (new DateTime($today . ' 23:59:59', $tz))->getTimestamp();

    $routeStmt = $db->prepare("
        SELECT
            clh.crew_id AS user_id,
            clh.latitude AS lat,
            clh.longitude AS lng,
            DATE_FORMAT(clh.timestamp, '%H:%i') AS time_short
        FROM crew_location_history clh
        INNER JOIN users u ON u.id = clh.crew_id
        WHERE u.is_active = 1
          AND u.location_tracking_enabled = 1
          AND UNIX_TIMESTAMP(clh.timestamp) >= ?
          AND UNIX_TIMESTAMP(clh.timestamp) <= ?
        ORDER BY clh.crew_id ASC, clh.timestamp ASC
    ");
    $routeStmt->execute([$dayStart, $dayEnd]);
    $routeRows = $routeStmt->fetchAll(PDO::FETCH_ASSOC);

    // Group route points by user
    $routes = [];
    foreach ($routeRows as $rp) {
        $uid = (int)$rp['user_id'];
        if (!isset($routes[$uid])) $routes[$uid] = [];
        $routes[$uid][] = [(float)$rp['lat'], (float)$rp['lng'], $rp['time_short']];
    }

    // ── 3. Today's jobs with property locations ─────────────────────────
    $jobStmt = $db->prepare("
        SELECT
            cs.id AS stop_id,
            cs.status AS stop_status,
            cs.crew_id,
            p.address,
            p.city,
            p.latitude AS lat,
            p.longitude AS lng,
            u.full_name AS crew_name,
            GROUP_CONCAT(DISTINCT jp.service_type SEPARATOR ', ') AS services,
            MIN(jv.scheduled_time_start) AS scheduled_time,
            GROUP_CONCAT(DISTINCT jv.status SEPARATOR ',') AS visit_statuses
        FROM calendar_stops cs
        JOIN properties p ON cs.property_id = p.id
        LEFT JOIN job_visits jv ON jv.stop_id = cs.id
        LEFT JOIN job_plans jp ON jv.plan_id = jp.id
        LEFT JOIN users u ON cs.crew_id = u.id
        WHERE cs.stop_date = ?
          AND p.latitude IS NOT NULL
          AND p.longitude IS NOT NULL
        GROUP BY cs.id
        ORDER BY cs.route_order ASC
    ");
    $jobStmt->execute([$today]);
    $jobRows = $jobStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 4. Office location (from ops_settings) ─────────────────────────
    $officeLat = null;
    $officeLng = null;
    try {
        $offStmt = $db->query("
            SELECT setting_key, setting_value
            FROM ops_settings
            WHERE setting_key IN ('office_latitude', 'office_longitude')
        ");
        while ($row = $offStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['setting_key'] === 'office_latitude')  $officeLat = (float)$row['setting_value'];
            if ($row['setting_key'] === 'office_longitude') $officeLng = (float)$row['setting_value'];
        }
    } catch (PDOException $e) {
        // ops_settings may not have these keys yet
    }

    // ── 5. Derive crew status ───────────────────────────────────────────
    $jobs = [];
    foreach ($jobRows as $j) {
        // Determine best visit status for this stop
        $statuses = explode(',', $j['visit_statuses'] ?? '');
        $bestStatus = 'scheduled';
        if (in_array('in_progress', $statuses)) $bestStatus = 'in_progress';
        elseif (in_array('completed', $statuses)) $bestStatus = 'completed';

        $jobs[] = [
            'stop_id'        => (int)$j['stop_id'],
            'address'        => $j['address'] . ($j['city'] ? ', ' . $j['city'] : ''),
            'lat'            => (float)$j['lat'],
            'lng'            => (float)$j['lng'],
            'status'         => $bestStatus,
            'crew_name'      => $j['crew_name'] ?? 'Unassigned',
            'crew_id'        => $j['crew_id'] ? (int)$j['crew_id'] : null,
            'services'       => formatServiceLabel($j['services'] ?? ''),
            'scheduled_time' => $j['scheduled_time'] ? substr($j['scheduled_time'], 0, 5) : null,
        ];
    }

    $crew = [];
    foreach ($crewRows as $c) {
        $uid = (int)$c['user_id'];
        $lat = (float)$c['lat'];
        $lng = (float)$c['lng'];
        $secondsAgo = max(0, $nowEpoch - (int)$c['last_epoch']);
        $clockedIn = (int)$c['is_clocked_in'] === 1;

        // Derive status
        $status = 'off_duty';
        $statusLabel = 'Off duty';

        if ($clockedIn || $c['device_type'] === 'truck') {
            $status = 'in_transit';
            $statusLabel = 'In transit';

            // Check proximity to job sites (150m threshold)
            foreach ($jobs as $job) {
                if ($job['lat'] && $job['lng']) {
                    $dist = haversineMeters($lat, $lng, $job['lat'], $job['lng']);
                    if ($dist <= 150) {
                        $status = 'at_job';
                        $shortAddr = strlen($job['address']) > 30
                            ? substr($job['address'], 0, 27) . '...'
                            : $job['address'];
                        $statusLabel = 'At ' . $shortAddr;
                        break;
                    }
                }
            }

            // Check proximity to office (150m)
            if ($status === 'in_transit' && $officeLat && $officeLng) {
                $dist = haversineMeters($lat, $lng, $officeLat, $officeLng);
                if ($dist <= 150) {
                    $status = 'at_office';
                    $statusLabel = 'At office';
                }
            }

            // Stale GPS (>5 minutes)
            if ($secondsAgo > 300) {
                $status = 'stale';
                $statusLabel = 'Last seen ' . formatSecondsAgo($secondsAgo);
            }
        }

        $crew[] = [
            'user_id'       => $uid,
            'name'          => $c['full_name'],
            'device_type'   => $c['device_type'],
            'is_clocked_in' => $clockedIn,
            'clock_in_time' => $c['clock_in_time'],
            'last_lat'      => $lat,
            'last_lng'      => $lng,
            'seconds_ago'   => $secondsAgo,
            'status'        => $status,
            'status_label'  => $statusLabel,
            'route'         => $routes[$uid] ?? [],
        ];
    }

    // ── 6. Vendor locations (static reference layer) ────────────────────
    $vendors = $db->query("
        SELECT vl.id, vl.vendor_id, vl.label, vl.address, vl.lat, vl.lng,
               vl.city, vl.phone, vl.hours_weekday, vl.is_preferred,
               v.name AS vendor_name
        FROM vendor_locations vl
        JOIN vendors v ON vl.vendor_id = v.id
        WHERE v.is_active = 1
          AND vl.lat IS NOT NULL AND vl.lat != 0
          AND vl.lng IS NOT NULL AND vl.lng != 0
        ORDER BY v.name, vl.is_preferred DESC
    ")->fetchAll();

    // ── 7. Build response ───────────────────────────────────────────────
    $response = [
        'success' => true,
        'crew'    => $crew,
        'jobs'    => $jobs,
        'office'  => ($officeLat && $officeLng)
            ? ['lat' => $officeLat, 'lng' => $officeLng, 'label' => 'Office']
            : null,
        'vendors' => $vendors,
        'date'    => $today,
    ];

    echo json_encode($response);

} catch (PDOException $e) {
    error_log('Dashboard map data API DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (Exception $e) {
    error_log('Dashboard map data API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ── Helper functions ────────────────────────────────────────────────────

/**
 * Haversine distance in meters between two lat/lng points.
 */
function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $R = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLng / 2) * sin($dLng / 2);
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Format service_type values for display.
 */
function formatServiceLabel(string $raw): string
{
    if (!$raw) return 'Service';
    $labels = [];
    foreach (explode(', ', $raw) as $s) {
        $labels[] = ucwords(str_replace('_', ' ', trim($s)));
    }
    return implode(', ', array_unique($labels));
}

/**
 * Human-readable "X ago" for seconds.
 */
function formatSecondsAgo(int $sec): string
{
    if ($sec < 60) return $sec . 's ago';
    if ($sec < 3600) return floor($sec / 60) . 'm ago';
    return floor($sec / 3600) . 'h ago';
}
