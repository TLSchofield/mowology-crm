<?php
/**
 * Calendar Stops API — fetch day's stops for map/sidebar display
 *
 * GET ?date=YYYY-MM-DD&crew=N (optional crew filter)
 * Returns: { success: true, date: "...", stops: [...] }
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
    require_once CRM_INCLUDES . '/plan-functions.php';

    requireLogin();
    $user = getCurrentUser();
    session_write_close(); // read-only — release session lock immediately

    $date = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid date format');
    }

    $crewId = isset($_GET['crew']) && $_GET['crew'] !== '' ? (int)$_GET['crew'] : null;

    $calendarData = getCalendarStops($date, $date, $crewId);
    $dayStops = $calendarData[$date] ?? [];

    // Sort by route_order then estimated arrival
    uasort($dayStops, function ($a, $b) {
        $aTime = $a['estimated_arrival'] ?? ($a['visits'][0]['scheduled_time_start'] ?? '23:59:59');
        $bTime = $b['estimated_arrival'] ?? ($b['visits'][0]['scheduled_time_start'] ?? '23:59:59');
        $timeCmp = strcmp($aTime, $bTime);
        if ($timeCmp !== 0) return $timeCmp;
        return ($a['route_order'] ?? 999) - ($b['route_order'] ?? 999);
    });

    $stops = [];
    foreach ($dayStops as $stop) {
        $visits = [];
        foreach (($stop['visits'] ?? []) as $v) {
            $visits[] = [
                'visit_id'            => (int)$v['visit_id'],
                'service_type'        => $v['service_type'] ?? '',
                'plan_title'          => $v['plan_title'] ?? '',
                'price_per_visit'     => (float)($v['price_per_visit'] ?? 0),
                'estimated_duration'  => (int)($v['estimated_duration'] ?? 0),
                'visit_status'        => $v['visit_status'] ?? 'scheduled',
                'scheduled_time_start'=> $v['scheduled_time_start'] ?? null,
            ];
        }
        $stops[] = [
            'stop_id'           => (int)$stop['stop_id'],
            'stop_date'         => $stop['stop_date'],
            'route_order'       => (int)($stop['route_order'] ?? 0),
            'crew_id'           => $stop['crew_id'] ? (int)$stop['crew_id'] : null,
            'crew_name'         => $stop['crew_name'] ?? null,
            'crew_ids'          => $stop['crew_ids'] ?? ($stop['crew_id'] ? [(int)$stop['crew_id']] : []),
            'crew_names'        => $stop['crew_names'] ?? ($stop['crew_name'] ? [$stop['crew_name']] : []),
            'stop_status'       => $stop['stop_status'] ?? 'scheduled',
            'estimated_arrival' => $stop['estimated_arrival'] ?? null,
            'property_id'       => (int)$stop['property_id'],
            'property_address'  => $stop['property_address'] ?? '',
            'property_city'     => $stop['property_city'] ?? '',
            'latitude'          => $stop['latitude'] ? (float)$stop['latitude'] : null,
            'longitude'         => $stop['longitude'] ? (float)$stop['longitude'] : null,
            'company_name'      => $stop['company_name'] ?? null,
            'contact_name'      => $stop['contact_name'] ?? null,
            'lawn_sqft'         => isset($stop['lawn_sqft']) && $stop['lawn_sqft'] > 0 ? (float)$stop['lawn_sqft'] : null,
            'visits'            => $visits,
        ];
    }

    echo json_encode(['success' => true, 'date' => $date, 'stops' => $stops]);

} catch (PDOException $e) {
    error_log('Calendar Stops API DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
