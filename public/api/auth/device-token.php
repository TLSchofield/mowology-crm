<?php
declare(strict_types=1);

/**
 * POST /api/auth/device-token
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

// Auth — JWT validation
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (strpos($authHeader, 'Bearer ') !== 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$jwt = substr($authHeader, 7);
require_once APP_ROOT . '/Core/auth.php';
$payload = validateJwt($jwt);
if (!$payload || empty($payload['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$userId = (int) $payload['user_id'];

// Release session lock (no session writes needed)
session_write_close();

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

    // Upsert: one row per user+token combo, update platform + last_seen
    $db->prepare("
        INSERT INTO device_tokens (user_id, device_token, platform, created_at, last_seen_at)
        VALUES (?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            platform     = VALUES(platform),
            last_seen_at = NOW(),
            is_active    = 1
    ")->execute([$userId, $deviceToken, $platform]);

    // Deactivate old tokens for this user on the same platform that differ
    $db->prepare("
        UPDATE device_tokens
           SET is_active = 0
         WHERE user_id   = ?
           AND platform  = ?
           AND device_token != ?
    ")->execute([$userId, $platform, $deviceToken]);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    // Table might not exist yet — return success anyway so the app isn't blocked
    error_log('[device-token] DB error: ' . $e->getMessage());
    echo json_encode(['success' => true, 'note' => 'token_table_pending']);
}
