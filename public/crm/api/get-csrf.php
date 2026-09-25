<?php
/**
 * GET /crm/api/get-csrf.php
 *
 * Returns the current session's CSRF token as JSON.
 * Used by the PWA to refresh a stale token when the service worker
 * serves a cached page (stale-while-revalidate) with an old token.
 *
 * Requires authentication. No side effects.
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

header('Content-Type: application/json');
header('Cache-Control: no-store');

requireLogin();
session_write_close();

echo json_encode(['token' => generateCSRFToken()]);
