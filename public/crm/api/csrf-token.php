<?php
/**
 * CSRF Token Refresh Endpoint
 *
 * Returns a fresh CSRF token for the current session.
 * Used by JS when a save fails due to a stale token (e.g., session
 * was regenerated on another tab while the page was still open).
 *
 * GET /crm/api/csrf-token.php → {"csrf_token": "..."}
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();

    // Release session lock immediately — we only need to read/generate the token
    $token = generateCSRFToken();
    session_write_close();

    echo json_encode(['csrf_token' => $token]);
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
}
