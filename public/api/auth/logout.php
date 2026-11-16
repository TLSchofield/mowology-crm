<?php
/**
 * POST /api/auth/logout.php — JWT Bearer. Ends this one app sign-in (Fable audit H2, Tim 2026-11-16).
 *
 * The iPhone app calls it before it clears its token. The call is best-effort: if the phone is offline the token
 * stays usable until it expires (Tim's decision D2). Other sign-ins of the same person are not touched.
 * Response 200: { "success": true }   (also when the sign-in was already ended)
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

try {
    require_once APP_ROOT . '/Core/config.php';
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once APP_ROOT . '/Modules/Auth/Services/AppTokenService.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required']);
        exit;
    }

    // requireJwt() refuses a revoked or unrecorded token, so only a live sign-in gets this far.
    $jwtUser = requireJwt();
    $payload = validateJwtToken(extractBearerToken());
    $jti     = is_array($payload) ? (string)($payload['jti'] ?? '') : '';

    AppTokenService::revoke(getDB(), $jti, (int)$jwtUser['id'], time());
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('Mobile logout: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Sign-out is unavailable right now']);
}
