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

// Session lifetime — 8 hours (matches Android/PWA usage patterns).
// The server GC window (gc_maxlifetime) must be >= cookie lifetime or the
// session file gets deleted while the browser still holds a valid cookie,
// causing silent logouts on Android/PWA when the screen is locked for >24 min.
$sessionLifetime = 8 * 3600; // 8 hours in seconds

// Override the host's php.ini gc_maxlifetime (cPanel sets it to 1440 = 24 min)
// so session files survive at least as long as the cookie.
ini_set('session.gc_maxlifetime', (string)$sessionLifetime);

// Cookie hardening
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_name('MOWOSESS');

session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'domain' => '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
