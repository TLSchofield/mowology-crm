<?php
declare(strict_types=1);

// Foundation bootstrap — multi-tenant site resolution
// Detects which site (tenant) is being served from the request domain,
// then sets SITE_* constants dynamically from the cms_sites table.

// Load app config first (sets up Database::pdo(), getDB(), etc.)
require_once dirname(__DIR__) . '/app_config/config.php';

// Configure session to use a writable cPanel temp directory
if (session_status() === PHP_SESSION_NONE) {
  $sessionPath = '/home/mowology/tmp';
  if (is_dir($sessionPath) && is_writable($sessionPath)) {
    session_save_path($sessionPath);
  }

  // Cookie hardening (matches app/Core/session_config.php's CRM session) — the
  // public site was previously starting a session with PHP's bare defaults,
  // i.e. no HttpOnly/Secure/SameSite at all.
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
         || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  session_start();
}

// ── Multi-tenant site resolution ───────────────────────────────────────────
// Load CmsSiteFunctions and resolve which site this request belongs to.
// Falls back to hardcoded Mowology defaults if cms_sites table doesn't exist yet.
$__siteFuncsPath = dirname(__DIR__) . '/app/Modules/CMS/Services/CmsSiteFunctions.php';
if (is_file($__siteFuncsPath)) {
    require_once $__siteFuncsPath;
    $__site = cms_resolveSite();
} else {
    // Pre-migration fallback: use hardcoded Mowology values
    $__site = [
        'id' => 1, 'slug' => 'mowology', 'domain' => 'mowology.ca',
        'name' => 'Mowology', 'tagline' => 'A HIGHER DEGREE OF SERVICE',
        'phone_display' => '778-846-9273', 'phone_tel' => '7788469273',
        'email' => 'office@mowology.ca', 'locale' => 'en_CA',
        'timezone' => 'America/Vancouver',
    ];
}

define('CMS_SITE_ID', (int)$__site['id']);
define('SITE_NAME', $__site['name'] ?? 'Mowology');
define('SITE_TAGLINE', $__site['tagline'] ?? '');
define('SITE_URL', 'https://' . ($__site['domain'] ?? 'mowology.ca'));
define('SITE_PHONE_DISPLAY', $__site['phone_display'] ?? '');
define('SITE_PHONE_TEL', $__site['phone_tel'] ?? '');
define('SITE_EMAIL', $__site['email'] ?? '');
define('SITE_LOCALE', $__site['locale'] ?? 'en_CA');

date_default_timezone_set($__site['timezone'] ?? 'America/Vancouver');

// Year constant (used in copyright footers, etc.)
define('SITE_YEAR', date('Y'));

// Store full site record for downstream use (header.php, footer.php, etc.)
$GLOBALS['__cms_site'] = $__site;
unset($__site, $__siteFuncsPath);

// h() is already defined in config.php, so we only add it here if not present
// For backward compatibility in case this file is used standalone
if (!function_exists('h')) {
  function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

// Asset URL with automatic cache-busting via file modification time.
// Usage: asset('/assets/css/master.css') → '/assets/css/master.css?v=1742400123'
// No manual version bumping ever needed — timestamp updates on every deploy.
if (!function_exists('asset')) {
  function asset(string $path): string {
    $disk = dirname(__DIR__) . $path;  // PUBLIC_ROOT . $path
    $ts   = @filemtime($disk) ?: time();
    return $path . '?v=' . $ts;
  }
}

// Load shared helper functions (needed by header.php and other includes)
require_once __DIR__ . '/functions.php';
