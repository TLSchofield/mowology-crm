<?php
declare(strict_types=1);

/**
 * /app/Core/session_config.php
 * Central session bootstrap.
 *
 * Migrated from /public/app_config/session_config.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// If output started already, sessions can break.
if (headers_sent($file, $line)) {
    error_log("Session start blocked: headers already sent at $file:$line");
    return;
}

/**
 * cPanel alt-php can point session.save_path to a non-existent folder.
 * Force to a known-writable folder in your account.
 */
$savePath = '/home/mowology/tmp';

// Ensure it exists
if (!is_dir($savePath)) {
    @mkdir($savePath, 0700, true);
}

// Force session save path BEFORE session_start
if (is_dir($savePath) && is_writable($savePath)) {
    // session_save_path is often more reliable than ini_set on cPanel
    session_save_path($savePath);
} else {
    error_log("Session save path not writable or missing: $savePath");
    // If this fails, your host-level config must be corrected in cPanel.
}

// Cookie hardening
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_name('MOWOSESS');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
