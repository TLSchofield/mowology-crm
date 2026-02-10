<?php
declare(strict_types=1);

// Foundation bootstrap for future CMS expansion
// - Central site settings
// - Helpers (escape, asset paths)
// - Database access (for dynamic content)

// Load app config first (sets up Database::pdo(), getDB(), etc.)
require_once dirname(__DIR__) . '/app_config/config.php';

date_default_timezone_set('America/Vancouver');

// Configure session to use a writable cPanel temp directory
if (session_status() === PHP_SESSION_NONE) {
  $sessionPath = '/home/mowology/tmp';
  if (is_dir($sessionPath) && is_writable($sessionPath)) {
    session_save_path($sessionPath);
  }
  session_start();
}

define('SITE_NAME', 'Mowology');
define('SITE_TAGLINE', 'A HIGHER DEGREE OF SERVICE');
define('SITE_URL', 'https://mowology.ca');
define('SITE_PHONE_DISPLAY', '778-846-9273');
define('SITE_PHONE_TEL', '7788469273');
define('SITE_EMAIL', 'office@mowology.ca');
define('SITE_LOCALE', 'en_CA');

// If you later add staging environments, you can switch this automatically.
define('SITE_YEAR', '2026');

// h() is already defined in config.php, so we only add it here if not present
// For backward compatibility in case this file is used standalone
if (!function_exists('h')) {
  function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

// For later CDN/versioning if needed
if (!function_exists('asset')) {
  function asset(string $path): string {
    return $path;
  }
}

// Load shared helper functions (needed by header.php and other includes)
require_once __DIR__ . '/functions.php';
