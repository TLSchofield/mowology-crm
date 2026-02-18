<?php
/**
 * Proximity Stops API — today's scheduled visits with coordinates
 * GET: Returns lightweight stop data for client-side proximity detection
 *
 * Used by time-clock-widget.js to run local haversine checks on every CRM page.
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

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $today = date('Y-m-d');
    $proximityMeters = (int)getTimeClockSetting('gps_proximity_meters', '150');
    $autoArrivalEnabled = getTimeClockSetting('auto_arrival_enabled', '1') === '1';
    $autoArrivalServiceTypesStr = getTimeClockSetting('auto_arrival_service_types', '');
    $autoArrivalServiceTypes = array_filter(array_map('trim', explode(',', $autoArrivalServiceTypesStr)));

    $allVisits = getAllJobsForDate($today);

    $stops = [];
    foreach ($allVisits as $v) {
        $vLat = $v['property_lat'] ?? null;
        $vLng = $v['property_lng'] ?? null;
        if (empty($vLat) || empty($vLng)) continue;

        $stops[] = [
            'visit_id'        => (int)$v['id'],
            'lat'             => (float)$vLat,
            'lng'             => (float)$vLng,
            'status'          => $v['status'],
            'service_type'    => $v['service_type'] ?? '',
            'job_title'       => $v['title'] ?? '',
            'job_number'      => $v['job_number'] ?? '',
            'address'         => $v['property_address'] ?? '',
            'assigned_crew_id' => $v['assigned_crew_id'] ? (int)$v['assigned_crew_id'] : null,
        ];
    }

    echo json_encode([
        'success'                    => true,
        'stops'                      => $stops,
        'proximity_meters'           => $proximityMeters,
        'auto_arrival_enabled'       => $autoArrivalEnabled,
        'auto_arrival_service_types' => $autoArrivalServiceTypes,
    ]);

} catch (PDOException $e) {
    error_log('Proximity Stops API DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
