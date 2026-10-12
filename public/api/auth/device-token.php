<?php
declare(strict_types=1);

/**
 * POST /api/auth/device-token
 *
 * NOT LIVE (absent on production 2026-10-12): the iPhone uses /api/device/token
 * (app/Modules/Device/Api/token.php). Kept in step via DeviceTokenService.
 *
 * Registers or updates an APNs device token for the authenticated user.
 * Called by the iOS app on every launch when the token changes.
 *
 * Body (JSON): { "device_token": "<hex>", "platform": "ios" }
 * Response 200: { "success": true }
 * Response 400: { "error": "..." }
 * Response 401: { "error": "Unauthorized" }
 * Response 405: { "error": "Method not allowed" }
 */

// Bootstrap
if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}
require_once APP_ROOT . '/Core/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Auth — JWT Bearer token (no session used)
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
$jwtUser = requireJwt(); // exits 401 if missing or invalid
$userId  = $jwtUser['id'];

// Parse body
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$deviceToken = trim($body['device_token'] ?? '');
$platform    = trim($body['platform'] ?? 'ios');

if (strlen($deviceToken) < 32) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid device token']);
    exit;
}

// Sanitise
$deviceToken = preg_replace('/[^a-f0-9]/i', '', $deviceToken);
$platform    = in_array($platform, ['ios', 'android'], true) ? $platform : 'ios';

try {
    $db = getDB();

    // One place for the rules (the phone belongs to whoever registered it last).
    require_once APP_ROOT . '/Services/Push/DeviceTokenService.php';
    DeviceTokenService::register($db, (int)$userId, $deviceToken, $platform);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    // Table might not exist yet — return success anyway so the app isn't blocked
    error_log('[device-token] DB error: ' . $e->getMessage());
    echo json_encode(['success' => true, 'note' => 'token_table_pending']);
}
