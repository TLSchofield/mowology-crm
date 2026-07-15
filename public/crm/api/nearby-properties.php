<?php
/**
 * Nearby Properties API — proximity detection for the field "add a job" overlay.
 *
 * GET: { lat, lng, radius? }  (radius in metres, default 250)
 * Returns: { success: bool, results: [...] }
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
    requireAnyPermission(['jobs.create_field', 'jobs.edit']);
    session_write_close(); // release session lock — read-only endpoint, no session writes

    $lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float)$_GET['lng'] : null;
    $radius = isset($_GET['radius']) && $_GET['radius'] !== '' ? (int)$_GET['radius'] : 250;
    if ($radius <= 0 || $radius > 5000) $radius = 250;

    if ($lat === null || $lng === null) {
        echo json_encode(['success' => true, 'results' => []]);
        exit;
    }

    $results = findNearbyProperties($lat, $lng, $radius, 15);

    echo json_encode(['success' => true, 'results' => $results]);

} catch (Throwable $e) {
    error_log('nearby-properties.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
