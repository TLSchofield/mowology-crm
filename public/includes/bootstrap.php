<?php
declare(strict_types=1);

// Foundation bootstrap for future CMS expansion
// - Central site settings
// - Helpers (escape, asset paths)
// - Session ready (for CMS login later)

date_default_timezone_set('America/Vancouver');

if (session_status() === PHP_SESSION_NONE) {
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

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// For later CDN/versioning if needed
function asset(string $path): string {
  return $path;
}
