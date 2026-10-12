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

    require_once APP_ROOT . '/Services/Push/DeviceTokenService.php';
    $deviceToken = DeviceTokenService::clean($deviceToken, $platform);
    if ($deviceToken === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid device token']);
        exit;
    }

    $db = getDB();

    // Sign-out on this phone (iOS sends remove:true before it drops its JWT): stop all pushes to it.
    if (!empty($input['remove'])) {
        DeviceTokenService::forget($db, $deviceToken);
        echo json_encode(['success' => true]);
        exit;
    }

    // The phone belongs to whoever registered it last (Fable review 2026-10-12).
    DeviceTokenService::register($db, $userId, $deviceToken, $platform);

    // Web/Android (session) sign-out retires this token too: logoutUser() reads it from the session.
    $viaJwt = str_starts_with((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), 'Bearer ');
    if (!$viaJwt && !headers_sent()) {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['push_device_token'] = $deviceToken;
            session_write_close();
        }
    }

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Device token registration failed: ' . $e->getMessage()]);
}
