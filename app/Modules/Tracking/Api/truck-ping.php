<?php
/**
 * POST /crm/api/truck-ping.php
 * Asks the Trackimo device to wake up and try a fresh GPS read.
 *
 * Useful when the last cached position is stale — the cron only fetches
 * what Trackimo already has, this nudges the device to actually report.
 * Whether it succeeds depends on signal: indoors with no sky view, the
 * device will still report its last known position.
 *
 * Rate-limited to one ping per 60s (per process) to prevent UI mash spam
 * from racking up Trackimo API calls.
 *
 * Response:
 *   { ok: true }                — request accepted by Trackimo
 *   { ok: false, error: '...' } — config missing, rate-limited, or upstream failure
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
require_once APP_ROOT . '/Modules/Tracking/Services/TrackimoService.php';

requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$deviceId = defined('TRACKIMO_DEVICE_ID') ? (string)TRACKIMO_DEVICE_ID : '';
if ($deviceId === '') {
    echo json_encode(['ok' => false, 'error' => 'Tracker not configured']);
    exit;
}

// Rate-limit via a touch file. 60s window matches the cron cadence — no
// point asking faster than we read.
$throttlePath = (defined('STORAGE_ROOT') ? STORAGE_ROOT : sys_get_temp_dir())
              . '/cache/trackimo_ping_throttle';
if (is_file($throttlePath)) {
    $lastPing = (int)@filemtime($throttlePath);
    if ($lastPing > 0 && (time() - $lastPing) < 60) {
        $waitSec = 60 - (time() - $lastPing);
        echo json_encode(['ok' => false, 'error' => "Already pinged — try again in {$waitSec}s"]);
        exit;
    }
}

try {
    $svc = new TrackimoService();
    $svc->forceLocationPush($deviceId);

    // Stamp the throttle file so the next attempt waits.
    @mkdir(dirname($throttlePath), 0775, true);
    @touch($throttlePath);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[truck-ping] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Trackimo refused the request']);
}
