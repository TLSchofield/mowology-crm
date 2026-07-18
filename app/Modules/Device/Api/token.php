<?php
/**
 * POST /api/device/token — JWT-authenticated
 *
 * Registers or updates a push-notification device token for the authenticated
 * user. Called by the iOS app on every launch when the token changes (see
 * APIEndpoints.swift's `deviceTokenRegister` case).
 *
 * Body (JSON): { "device_token": "<hex>", "platform": "ios" }
 * Response 200: { "success": true }
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

header('Content-Type: application/json');

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $deviceToken = trim($input['device_token'] ?? '');
    $platform    = trim($input['platform'] ?? 'ios');

    if (strlen($deviceToken) < 32) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid device token']);
        exit;
    }

    // Sanitize — APNs tokens are hex; reject anything else outright
    $deviceToken = preg_replace('/[^a-f0-9]/i', '', $deviceToken);
    $platform    = in_array($platform, ['ios', 'android'], true) ? $platform : 'ios';

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

    // Deactivate this user's other tokens on the same platform — one active
    // token per user per platform (a re-registered token means the old one
    // on this device is stale, e.g. after a reinstall).
    $db->prepare("
        UPDATE device_tokens
           SET is_active = 0
         WHERE user_id   = ?
           AND platform  = ?
           AND device_token != ?
    ")->execute([$userId, $platform, $deviceToken]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Device token registration failed: ' . $e->getMessage()]);
}
