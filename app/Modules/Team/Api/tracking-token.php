<?php
declare(strict_types=1);

/**
 * app/Modules/Team/Api/tracking-token.php   →  POST /api/team/tracking-token
 *
 * Hands the Capacitor app's NATIVE layer a short-lived JWT for the logged-in user.
 *
 * Why: the Android app authenticates with a PHP session cookie that is httponly and
 * lives in the WebView. Native code (the tracking foreground service, WorkManager)
 * cannot read it, so native upload could never authenticate — which forced location
 * capture AND upload to run in page JavaScript, where they die when the screen
 * turns off or the app is swiped away. With this token the native service talks to
 * the same JWT tracking API as iOS (/api/schedule/location, /api/schedule/tracking).
 *
 * Session auth + CSRF. 16 h TTL: long enough for any shift, short enough that a
 * demotion or deactivation bites the same day (the JWT carries the role).
 */

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

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    $input = json_decode((string)file_get_contents('php://input'), true) ?? [];
    // CSRF before session_write_close() — closing the session empties $_SESSION.
    if (!verifyCSRFToken($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Session expired — reload the page', 'code' => 'CSRF_INVALID']);
        exit;
    }

    $user = getCurrentUser();
    session_write_close();

    $stmt = getDB()->prepare("SELECT id, email, full_name, role, is_active, location_tracking_enabled FROM users WHERE id = ?");
    $stmt->execute([(int)($user['id'] ?? 0)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['is_active'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Account inactive']);
        exit;
    }
    if (empty($row['location_tracking_enabled'])) {
        // No token for someone who is not tracked — native has nothing to do for them.
        echo json_encode(['success' => true, 'tracking_enabled' => false, 'token' => null]);
        exit;
    }

    require_once APP_ROOT . '/Core/Auth/JwtService.php';
    $ttl = 16 * 3600;

    echo json_encode([
        'success'          => true,
        'tracking_enabled' => true,
        'token'            => generateMowologyJwt((int)$row['id'], (string)$row['email'], (string)$row['full_name'], (string)$row['role'], $ttl),
        'expires_at'       => time() + $ttl,
        // From config, never the Host header. The native side pins its own host anyway.
        'api_base'         => (defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : 'https://mowology.ca') . '/api',
        'user_id'          => (int)$row['id'],
    ]);

} catch (Throwable $e) {
    error_log('[team/tracking-token] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
