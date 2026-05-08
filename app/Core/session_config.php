<?php
declare(strict_types=1);

/**
 * /app/Core/session_config.php
 * Central session bootstrap.
 *
 * Migrated from /public/app_config/session_config.php
 */

// Error display is controlled by config.php (APP_ENV) — do NOT set it here.
// session_config.php loads before config.php, so any ini_set() here would
// unconditionally override the environment-aware setting that comes later.

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
$sessionLifetime = 9 * 3600; // 9 hours in seconds

// Override the host's php.ini gc_maxlifetime (cPanel sets it to 1440 = 24 min)
// so session files survive at least as long as the cookie.
ini_set('session.gc_maxlifetime', (string)$sessionLifetime);

// Cookie hardening
// Check both direct HTTPS and reverse-proxy forwarded headers.
// cPanel shared hosting uses Apache/SSL at the server level, but some
// configurations forward via X-Forwarded-Proto (e.g., load balancers).
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
       || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
       || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');

session_name('MOWOSESS');

// Capacitor Android WebView sends cookies on same-origin POSTs but can
// lose the session cookie if the app context shifts (e.g. deep links, intent
// transitions). SameSite=None ensures the cookie is sent in all contexts for
// WebView traffic. SameSite=None requires Secure=true (already set via HTTPS).
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isCapacitorWebView = stripos($ua, 'Capacitor') !== false
    || (stripos($ua, 'wv)') !== false && stripos($ua, 'Android') !== false);
$sameSite = ($isCapacitorWebView && $secure) ? 'None' : 'Lax';

session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => $sameSite,
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
