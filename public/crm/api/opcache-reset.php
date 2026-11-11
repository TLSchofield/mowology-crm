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

// POST + CSRF only (Fable review 2026-10-12): a GET from any page an admin opened could reset it.
// The topbar button posts with the token; opening the URL shows a button that does the same.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verifyCSRFToken((string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') { http_response_code(403); echo json_encode(['error' => 'Bad or expired token — reload the page']); exit; }
    header('Content-Type: text/html; charset=utf-8');
    $t = htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset OPcache</title>'
       . '<form method="post" style="font-family:sans-serif;margin:40px"><input type="hidden" name="csrf_token" value="' . $t . '">'
       . '<button type="submit" style="font-size:16px;padding:10px 18px">Reset OPcache</button></form>';
    exit;
}

$reset = function_exists('opcache_reset') ? opcache_reset() : null;
echo json_encode(['opcache_reset' => $reset, 'enabled' => function_exists('opcache_reset')]);
