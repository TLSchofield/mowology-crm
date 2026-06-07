<?php
/**
 * GET /crm/api/truck-location.php
 * Returns the most recent ping for the configured truck.
 *
 * Reads the JSON cache file written by the polling cron — typically ~1ms.
 * Falls back to a single DB SELECT if the cache file is missing.
 *
 * Response:
 *   { ok: true, location: { lat, lng, speed_kph, heading, battery_pct,
 *                            recorded_at, age_seconds, source } }
 *   or { ok: true, location: null } when no ping has been recorded yet.
 */
declare(strict_types=1);

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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Tracking/Services/TruckLocationService.php';

requireLogin();
header('Content-Type: application/json');

$deviceId = defined('TRACKIMO_DEVICE_ID') ? (string)TRACKIMO_DEVICE_ID : '';
if ($deviceId === '') {
    echo json_encode(['ok' => false, 'error' => 'Tracker not configured']);
    exit;
}

try {
    $service  = new TruckLocationService(getDB());
    $location = $service->getLastKnownPosition($deviceId);
    echo json_encode(['ok' => true, 'location' => $location]);
} catch (Throwable $e) {
    error_log('[truck-location] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Lookup failed']);
}
