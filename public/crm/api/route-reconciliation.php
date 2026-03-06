<?php
/**
 * Route Reconciliation API — Truck GPS vs Visit Clock-In Conflict Detection
 *
 * Compares truck GPS dwell stops against scheduled visit records to detect:
 *   - truck_at_site_no_clockin: Truck stopped at property but visit not clocked in
 *   - clockin_no_truck: Visit completed but no truck GPS presence detected
 *
 * GET ?date=YYYY-MM-DD  (defaults to today Pacific)
 * Auth: admin/manager only
 */
declare(strict_types=1);
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();

// Release session lock immediately — this is a read-only endpoint
session_write_close();

if (!in_array($user['role'], ['admin', 'manager'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

try {
    $db = getDB();
    $tz = new DateTimeZone('America/Vancouver');

    // --- Parse date ---
    $date = $_GET['date'] ?? (new DateTime('now', $tz))->format('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid date format. Use YYYY-MM-DD');
    }

    $dayStart = (new DateTime($date . ' 00:00:00', $tz))->getTimestamp();
    $dayEnd   = (new DateTime($date . ' 23:59:59', $tz))->getTimestamp();

    // --- Config ---
    $proximityMeters = 150;  // Match auto-arrival radius
    $minDwellSeconds = 180;  // 3 minutes minimum to count as a "stop"

    // --- 1. Get all trucks ---
    $truckStmt = $db->query("
        SELECT id, full_name
        FROM users
        WHERE device_type = 'truck'
          AND location_tracking_enabled = 1
          AND is_active = 1
    ");
    $trucks = $truckStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($trucks)) {
        echo json_encode(['success' => true, 'date' => $date, 'conflicts' => [], 'trucks' => 0]);
        exit;
    }

    $truckIds   = array_column($trucks, 'id');
    $truckNames = array_column($trucks, 'full_name', 'id');

    // --- 2. Get all scheduled/completed visits for the date with property coords ---
    $visitStmt = $db->prepare("
        SELECT
            jv.id AS visit_id,
            jv.visit_number,
            jv.status,
            jv.assigned_crew_id,
            u.full_name AS crew_name,
            jp.title AS plan_title,
            jp.service_type,
            jp.property_id,
            p.address AS property_address,
            p.city AS property_city,
            p.latitude AS prop_lat,
            p.longitude AS prop_lng
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        LEFT JOIN properties p ON jp.property_id = p.id
        LEFT JOIN users u ON jv.assigned_crew_id = u.id
        WHERE jv.scheduled_date = ?
          AND jv.status IN ('scheduled', 'in_progress', 'completed')
          AND p.latitude IS NOT NULL
          AND p.longitude IS NOT NULL
        ORDER BY jv.scheduled_time_start ASC
    ");
    $visitStmt->execute([$date]);
    $visits = $visitStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($visits)) {
        echo json_encode(['success' => true, 'date' => $date, 'conflicts' => [], 'visits' => 0]);
        exit;
    }

    // --- 3. Get truck GPS pings for the date ---
    $placeholders = implode(',', array_fill(0, count($truckIds), '?'));
    $pingStmt = $db->prepare("
        SELECT
            clh.crew_id AS truck_id,
            clh.latitude AS lat,
            clh.longitude AS lng,
            UNIX_TIMESTAMP(clh.timestamp) AS epoch
        FROM crew_location_history clh
        WHERE clh.crew_id IN ($placeholders)
          AND UNIX_TIMESTAMP(clh.timestamp) >= ?
          AND UNIX_TIMESTAMP(clh.timestamp) <= ?
        ORDER BY clh.crew_id ASC, clh.timestamp ASC
    ");
    $pingStmt->execute(array_merge($truckIds, [$dayStart, $dayEnd]));
    $allPings = $pingStmt->fetchAll(PDO::FETCH_ASSOC);

    // Group pings by truck
    $truckPings = [];
    foreach ($allPings as $p) {
        $truckPings[(int)$p['truck_id']][] = $p;
    }

    // --- 4. For each visit, check truck dwell ---
    $conflicts = [];

    // Deduplicate by property_id — multiple visits at same property share one dwell check
    $propertyVisits = [];
    foreach ($visits as $v) {
        $pid = (int)$v['property_id'];
        $propertyVisits[$pid][] = $v;
    }

    foreach ($propertyVisits as $propertyId => $propVisits) {
        $propLat = (float)$propVisits[0]['prop_lat'];
        $propLng = (float)$propVisits[0]['prop_lng'];

        // Check each truck for dwell at this property
        $truckDwells = [];  // truckId => ['duration_seconds' => N, 'first_seen' => epoch, 'last_seen' => epoch]

        foreach ($truckPings as $truckId => $pings) {
            $nearbyPings = [];
            foreach ($pings as $ping) {
                $dist = haversineDistance($propLat, $propLng, (float)$ping['lat'], (float)$ping['lng']);
                if ($dist <= $proximityMeters) {
                    $nearbyPings[] = (int)$ping['epoch'];
                }
            }

            if (count($nearbyPings) >= 2) {
                $firstSeen = min($nearbyPings);
                $lastSeen  = max($nearbyPings);
                $dwellSec  = $lastSeen - $firstSeen;

                if ($dwellSec >= $minDwellSeconds) {
                    $truckDwells[$truckId] = [
                        'duration_seconds' => $dwellSec,
                        'first_seen'       => $firstSeen,
                        'last_seen'        => $lastSeen,
                        'ping_count'       => count($nearbyPings),
                    ];
                }
            }
        }

        $hasTruckPresence = !empty($truckDwells);

        // Check each visit at this property
        foreach ($propVisits as $v) {
            $visitStatus = $v['status'];

            if ($hasTruckPresence && $visitStatus === 'scheduled') {
                // Truck was at the property but visit never started
                $bestTruck = null;
                $bestDwell = 0;
                foreach ($truckDwells as $tid => $dwell) {
                    if ($dwell['duration_seconds'] > $bestDwell) {
                        $bestDwell = $dwell['duration_seconds'];
                        $bestTruck = $tid;
                    }
                }

                $dwell = $truckDwells[$bestTruck];
                $conflicts[] = [
                    'type'             => 'truck_at_site_no_clockin',
                    'severity'         => 'warning',
                    'visit_id'         => (int)$v['visit_id'],
                    'visit_number'     => $v['visit_number'],
                    'property_address' => $v['property_address'],
                    'property_city'    => $v['property_city'],
                    'plan_title'       => $v['plan_title'],
                    'service_type'     => $v['service_type'],
                    'crew_name'        => $v['crew_name'],
                    'truck_name'       => $truckNames[$bestTruck] ?? 'Unknown',
                    'truck_id'         => $bestTruck,
                    'dwell_minutes'    => (int)round($dwell['duration_seconds'] / 60),
                    'first_seen'       => (new DateTime('@' . $dwell['first_seen']))->setTimezone($tz)->format('g:i a'),
                    'last_seen'        => (new DateTime('@' . $dwell['last_seen']))->setTimezone($tz)->format('g:i a'),
                    'ping_count'       => $dwell['ping_count'],
                    'message'          => 'Truck detected at site but visit not clocked in',
                ];
            } elseif (!$hasTruckPresence && $visitStatus === 'completed') {
                // Visit was completed but no truck was detected nearby
                $conflicts[] = [
                    'type'             => 'clockin_no_truck',
                    'severity'         => 'info',
                    'visit_id'         => (int)$v['visit_id'],
                    'visit_number'     => $v['visit_number'],
                    'property_address' => $v['property_address'],
                    'property_city'    => $v['property_city'],
                    'plan_title'       => $v['plan_title'],
                    'service_type'     => $v['service_type'],
                    'crew_name'        => $v['crew_name'],
                    'truck_name'       => null,
                    'truck_id'         => null,
                    'dwell_minutes'    => 0,
                    'first_seen'       => null,
                    'last_seen'        => null,
                    'ping_count'       => 0,
                    'message'          => 'Visit completed but no truck GPS detected at site',
                ];
            }
        }
    }

    // Sort: warnings first, then info
    usort($conflicts, function ($a, $b) {
        $sevOrder = ['warning' => 0, 'info' => 1];
        return ($sevOrder[$a['severity']] ?? 2) - ($sevOrder[$b['severity']] ?? 2);
    });

    echo json_encode([
        'success'   => true,
        'date'      => $date,
        'conflicts' => $conflicts,
        'summary'   => [
            'total'              => count($conflicts),
            'warnings'           => count(array_filter($conflicts, fn($c) => $c['severity'] === 'warning')),
            'info'               => count(array_filter($conflicts, fn($c) => $c['severity'] === 'info')),
            'visits_checked'     => count($visits),
            'trucks_checked'     => count($trucks),
            'total_truck_pings'  => count($allPings),
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
