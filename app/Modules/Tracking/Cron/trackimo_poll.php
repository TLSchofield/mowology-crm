<?php
/**
 * Trackimo Truck Poll — Cron Job
 * ──────────────────────────────────
 * Polls the Trackimo API for the configured truck's latest location and
 * persists each ping (with dedup) into vehicle_location_pings.
 *
 * Schedule: run every minute via cron. The script itself decides whether to
 * actually hit the API based on time of day:
 *   - 06:00–20:00 local: poll every minute
 *   - Outside business hours: poll every 5 minutes
 *
 * Both CLI and web-POST entry points are supported. CLI is the normal cron
 * execution path; the web POST is an admin-only manual trigger for testing.
 *
 * Logs to STORAGE_ROOT/logs/trackimo.log. Always exits 0 so a failed cron
 * doesn't spam the cPanel mail relay — failures are visible in the log file
 * and in the lateness of the most recent ping on the day map.
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

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST method required']);
        exit;
    }
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    $user = getCurrentUser();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }
    header('Content-Type: application/json');
} else {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
}

require_once APP_ROOT . '/Modules/Tracking/Services/TrackimoService.php';
require_once APP_ROOT . '/Modules/Tracking/Services/TruckLocationService.php';

// ─── Config: hardcoded for the single-truck phase ───
$deviceId = defined('TRACKIMO_DEVICE_ID') ? (string)TRACKIMO_DEVICE_ID : '';
if ($deviceId === '') {
    trackimoLog('ERROR', 'TRACKIMO_DEVICE_ID not defined in secrets.php');
    if (!$isCli) echo json_encode(['success' => false, 'error' => 'Device not configured']);
    exit($isCli ? 0 : 0);
}

// ─── Time-of-day gate ───
// Default to America/Vancouver — matches the Mowology operating timezone.
date_default_timezone_set('America/Vancouver');
$hour = (int)date('G');
$minute = (int)date('i');
$inBusinessHours = ($hour >= 6 && $hour < 20);
$shouldPoll = $inBusinessHours || ($minute % 5 === 0);

// Web POST always polls (manual trigger).
if (!$isCli) $shouldPoll = true;

if (!$shouldPoll) {
    exit(0);
}

try {
    $db = getDB();
    $trackimo = new TrackimoService();
    $store    = new TruckLocationService($db);

    $ping = $trackimo->getLastLocation($deviceId);
    if ($ping === null) {
        trackimoLog('WARN', "No location returned for device {$deviceId}");
        if (!$isCli) echo json_encode(['success' => true, 'inserted' => null, 'note' => 'no location']);
        exit(0);
    }

    $id = $store->savePing($deviceId, $ping);
    if ($id > 0) {
        trackimoLog('INFO', "Saved ping #{$id} for {$deviceId} at {$ping['recorded_at']}");
    }
    if (!$isCli) {
        echo json_encode(['success' => true, 'inserted' => $id, 'ping' => $ping]);
    }
} catch (Throwable $e) {
    trackimoLog('ERROR', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!$isCli) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit(0);
}

function trackimoLog(string $level, string $message): void
{
    $logDir = (defined('STORAGE_ROOT') ? STORAGE_ROOT : sys_get_temp_dir()) . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
    $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $level, $message);
    @file_put_contents($logDir . '/trackimo.log', $line, FILE_APPEND | LOCK_EX);
}
