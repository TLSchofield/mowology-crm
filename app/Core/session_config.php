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

// Two session cookies on one request (2026-10-16): an Android phone's native layer sent a stray MOWOSESS
// ahead of the real one; PHP took the first, started a blank session and its new cookie replaced the
// signed-in one — a driver was signed out a second after every sign-in. With more than one, use the one
// whose session file exists (the newest). Self-contained: the live copy of this file differs from the repo.
try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $__sessName = session_name();
        $__sessRaw  = (string)($_SERVER['HTTP_COOKIE'] ?? '');
        if (substr_count($__sessRaw, $__sessName . '=') > 1
            && preg_match_all('/(?:^|;\s*)' . preg_quote($__sessName, '/') . '=([A-Za-z0-9,-]{16,256})(?=;|$)/', $__sessRaw, $__sessM)) {
            $__sessDir = (string)session_save_path();
            $__sessDir = rtrim(strpos($__sessDir, ';') !== false ? substr($__sessDir, strrpos($__sessDir, ';') + 1) : $__sessDir, '/');
            $__sessPick = null; $__sessAt = -1;
            foreach (array_unique($__sessM[1]) as $__sessId) {
                $__sessFile = $__sessDir . '/sess_' . $__sessId;
                if ($__sessDir !== '' && is_file($__sessFile) && filesize($__sessFile) > 0 && filemtime($__sessFile) > $__sessAt) {
                    $__sessPick = $__sessId; $__sessAt = filemtime($__sessFile);
                }
            }
            if ($__sessPick !== null) session_id($__sessPick);
        }
    }
} catch (Throwable $__sessE) { /* never block a request over this */ }

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// TEMPORARY (2026-10-16): one Android phone loses its sign-in a second after logging in. Logs, for that
// phone model only, how many session cookies arrive and short fingerprints (never the values). Self-contained
// (the live copy of this file differs from the repo). Remove after.
try {
    $__diagUa = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (stripos($__diagUa, 'SM-A156W') !== false) {
        preg_match_all('/(?:^|;\s*)MOWOSESS=([^;]*)/', (string)($_SERVER['HTTP_COOKIE'] ?? ''), $__diagM);
        error_log('[mw-sessdiag] ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?')
            . ' cookies=' . count($__diagM[1]) . ' fp=' . implode(',', array_map(function ($v) { return substr(sha1((string)$v), 0, 6); }, $__diagM[1]))
            . ' used=' . substr(sha1((string)session_id()), 0, 6) . ' user=' . (int)($_SESSION['user_id'] ?? 0)
            . ' wv=' . (stripos($__diagUa, 'wv)') !== false ? 1 : 0) . ' strict=' . ini_get('session.use_strict_mode'));
    }
} catch (Throwable $__diagE) { /* diagnostic only */ }

// An outside accountant's login reaches the Ledger and nothing else — deny by default, before any
// page code runs (Accountant's Ledger, 2026-10-13). auth.php repeats this and logs each view.
require_once __DIR__ . '/Auth/AccountantFence.php';
AccountantFence::enforce();
