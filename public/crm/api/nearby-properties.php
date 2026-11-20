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
    require_once APP_ROOT . '/Modules/Jobs/Services/FieldJobService.php';
    $radius = FieldJobService::resolveRadius($_GET['radius'] ?? 0);

    $service = new FieldJobService(getDB());
    // `staff` + `me` feed the "who is on this job" picker; sent with every reply so the form
    // has them even when GPS was skipped (no lat/lng).
    $extra = ['staff' => $service->staff(), 'me' => (int)getCurrentUser()['id']];

    if ($lat === null || $lng === null) {
        echo json_encode(['success' => true, 'results' => []] + $extra);
        exit;
    }

    echo json_encode(['success' => true, 'results' => $service->nearby($lat, $lng, $radius)] + $extra);

} catch (Throwable $e) {
    error_log('nearby-properties.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
