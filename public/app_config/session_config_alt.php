<?php
// Session Configuration - Custom session handler for cPanel hosting
// This creates a sessions directory in your own space with proper permissions

// Define custom session path
define('CUSTOM_SESSION_PATH', __DIR__ . '/sessions');

// Create sessions directory if it doesn't exist
if (!file_exists(CUSTOM_SESSION_PATH)) {
    mkdir(CUSTOM_SESSION_PATH, 0700, true);
    // Create .htaccess to prevent web access
    file_put_contents(CUSTOM_SESSION_PATH . '/.htaccess', "Deny from all\n");
}

// Set custom session save path
ini_set('session.save_path', CUSTOM_SESSION_PATH);

// Configure session settings BEFORE starting session
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_lifetime', 0); // Session cookie (expires when browser closes)
ini_set('session.gc_maxlifetime', 86400); // 24 hours server-side
ini_set('session.cookie_samesite', 'Lax');

// Force HTTPS cookies if on HTTPS
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}

// Use a custom session name
session_name('MOWOLOGY_SECURE');

// Start the session
if (session_status() === PHP_SESSION_NONE) {
    if (!session_start()) {
        // If session start fails, log the error
        error_log("Failed to start session. Path: " . CUSTOM_SESSION_PATH);
        die("Session initialization failed. Please contact administrator.");
    }
}

// Regenerate session ID periodically for security
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} else if (time() - $_SESSION['created'] > 1800) {
    // Regenerate session ID every 30 minutes
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}
?>
