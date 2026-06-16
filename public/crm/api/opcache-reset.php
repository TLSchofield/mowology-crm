<?php
/**
 * Admin-only OPcache reset — used after lftp deploys so PHP picks up changed
 * files immediately (this host caches with validate_timestamps off). Temporary.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$reset = function_exists('opcache_reset') ? opcache_reset() : null;
echo json_encode(['opcache_reset' => $reset, 'enabled' => function_exists('opcache_reset')]);
