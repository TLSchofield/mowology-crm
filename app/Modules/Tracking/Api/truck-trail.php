<?php
/**
 * GET /crm/api/truck-trail.php[?date=YYYY-MM-DD]
 * Returns all pings for the configured truck on the given date (default: today).
 *
 * Hit on demand when the user toggles "show trail" on the day map. The DB
 * query is indexed and bounded so even a full business day (~500 pings)
 * returns in a few ms.
 *
 * Response:
 *   { ok: true, date: 'YYYY-MM-DD', count: int,
 *     trail: [ { lat, lng, speed_kph, heading, recorded_at }, ... ] }
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

$date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid date (expected YYYY-MM-DD)']);
    exit;
}

try {
    $service = new TruckLocationService(getDB());
    $trail   = $service->getTrailForDate($deviceId, $date);
    echo json_encode([
        'ok'    => true,
        'date'  => $date,
        'count' => count($trail),
        'trail' => $trail,
    ]);
} catch (Throwable $e) {
    error_log('[truck-trail] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Lookup failed']);
}
