<?php
/**
 * Shared admin gate for /crm/cron/ web shims.
 *
 * Include this at the TOP of a shim, before it requires its cron target:
 *     <?php require_once __DIR__ . '/_guard.php';
 *
 * Behaviour:
 *  - CLI (the real cPanel-scheduled cron, which runs the app/ path directly
 *    OR a legacy crontab entry pointing at the shim) bypasses auth entirely.
 *  - Web (HTTP) requests must be a logged-in admin, otherwise 403 JSON.
 *
 * Returns 403 for anonymous AND non-admin web requests (we check the session
 * directly rather than requireLogin(), which would 302-redirect to the login
 * page — these endpoints are JSON, so a 403 is the correct response and is
 * what the "Run Now" buttons / callers expect).
 */

// Real cron entrypoint — never gate CLI execution.
if (PHP_SAPI === 'cli') {
    return;
}

// Bootstrap PUBLIC_ROOT / APP_ROOT (upward search for app/Core/paths.php).
if (!defined('APP_ROOT')) {
    $__guardDir = __DIR__;
    for ($__guardI = 0; $__guardI < 6; $__guardI++) {
        $__guardDir = dirname($__guardDir);
        if (is_file($__guardDir . '/app/Core/paths.php')) {
            require_once $__guardDir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__guardDir, $__guardI);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Admin only']);
    exit;
}
