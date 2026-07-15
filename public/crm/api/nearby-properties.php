<?php
/**
 * Nearby Properties API — field "add a job" property picker.
 *
 * GET or POST: { lat?, lng?, q? }
 * Returns: { success: bool, properties: [...] }
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

    $input = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (json_decode(file_get_contents('php://input'), true) ?? [])
        : $_GET;

    $lat = isset($input['lat']) && $input['lat'] !== '' ? (float)$input['lat'] : null;
    $lng = isset($input['lng']) && $input['lng'] !== '' ? (float)$input['lng'] : null;
    $search = trim((string)($input['q'] ?? ''));

    if ($lat === null && $search === '') {
        echo json_encode(['success' => true, 'properties' => []]);
        exit;
    }

    $properties = findNearbyProperties($lat, $lng, $search, 15);

    echo json_encode(['success' => true, 'properties' => $properties]);

} catch (Throwable $e) {
    error_log('nearby-properties.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
