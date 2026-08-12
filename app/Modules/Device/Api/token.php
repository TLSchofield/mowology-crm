<?php
/**
 * POST /api/device/token — JWT Bearer OR session-authenticated
 *
 * Registers or updates a push-notification device token for the authenticated
 * user. Called by the iOS app (JWT) on every launch when the token changes
 * (see APIEndpoints.swift's `deviceTokenRegister` case), and by the Android
 * Capacitor app (session cookie, via MwNative.push in capacitor-bridge.js) on
 * the same trigger — Android has no JWT to send, so this endpoint accepts
 * either auth scheme via requireLoginOrJwt().
 *
 * Body (JSON): { "device_token": "<hex or fcm token>", "platform": "ios"|"android" }
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
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    $authedUser = requireLoginOrJwt(); // JWT Bearer (iOS) or session cookie (Android/web)
    $userId     = (int)$authedUser['id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $deviceToken = trim($input['device_token'] ?? '');
    $platform    = trim($input['platform'] ?? 'ios');
    $platform    = in_array($platform, ['ios', 'android'], true) ? $platform : 'ios';

    if (strlen($deviceToken) < 32) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid device token']);
        exit;
    }

    // Sanitize — token format differs by platform. APNs tokens are hex, so
    // strip anything else outright (unchanged iOS behavior). FCM registration
    // tokens are much longer and use a broader charset (letters, digits, and
    // -_.: separators) — hex-stripping would silently mangle every Android
    // token, so it gets its own charset + a length floor instead.
    if ($platform === 'ios') {
        $deviceToken = preg_replace('/[^a-f0-9]/i', '', $deviceToken);
    } else {
        $deviceToken = preg_replace('/[^A-Za-z0-9_.:-]/', '', $deviceToken);
        if (strlen($deviceToken) < 100) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid device token']);
            exit;
        }
    }

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
