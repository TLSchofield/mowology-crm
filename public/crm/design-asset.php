<?php
/**
 * Serves the Design System page's brand files (public/crm/assets/design-system/brand/) to signed-in staff only.
 * The folder itself denies direct requests (.htaccess): the wordmark, motion studies and boards are not public before
 * the trade-mark filing (Tim, 2026-10-10). ?f=<file name> — a plain name from that folder, nothing else.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
requirePermission('settings.edit');

$types = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'html' => 'text/html; charset=utf-8'];
$name  = (string)($_GET['f'] ?? '');
$ext   = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$dir   = realpath(__DIR__ . '/assets/design-system/brand');
$path  = $dir ? realpath($dir . '/' . $name) : false;

if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $name) || !isset($types[$ext]) || !$path || dirname($path) !== $dir || !is_file($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $types[$ext]);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
if ($ext === 'html' || $ext === 'svg') {
    // Shown only inside the Design System page; the studies load Google Fonts and nothing else.
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'unsafe-inline'; frame-ancestors 'self'");
}
readfile($path);
