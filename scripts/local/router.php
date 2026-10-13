<?php
/**
 * LOCAL TEST ONLY — router for PHP's built-in server (scripts/local-site.sh).
 *
 *   php -S 127.0.0.1:8000 -t public scripts/local/router.php
 *
 * Emulates what Apache does on production from public/.htaccess, public/api/.htaccess and the
 * per-directory .htaccess deny files:
 *   - denies app/, storage, vendor, sessions, app_config, logs/backups/tmp dirs, dotfiles,
 *     *.md/*.log/*.sql/*.bak/*.orig/*.swp and config.php/auth.php/session_config.php/secrets.php;
 *   - never executes PHP (or scripts) under /_media/ and /uploads/;
 *   - the canonical 301s (/index.php, /home, /about.php …), the named clean URLs
 *     (/quote, /privacy, /services/<slug> …) and /sitemap.xml;
 *   - /api/<module>/<action> → /api/index.php?module=&action= with QSA semantics
 *     (original query appended AFTER, so a duplicate ?action= wins exactly as on production);
 *   - DirectoryIndex (crm/: dashboard_appstack.php) and the trailing-slash redirect;
 *   - the CMS catch-all → /cms-render.php?page=<slug>;
 *   - real static files are served by the built-in server itself.
 *
 * Code runs at file scope on purpose: an included page's top-level variables must be globals.
 */

$__docroot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$__uri     = $_SERVER['REQUEST_URI'] ?? '/';
$__qpos    = strpos($__uri, '?');
$__path    = rawurldecode($__qpos === false ? $__uri : substr($__uri, 0, $__qpos));
$__query   = $__qpos === false ? '' : substr($__uri, $__qpos + 1);

if (!function_exists('__mw_local_deny')) {
    function __mw_local_deny(int $code = 403): bool {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $code === 404 ? "404 Not Found (local router)\n" : "403 Forbidden (local router: .htaccess denies this path)\n";
        return true;
    }
    function __mw_local_redirect(string $to): bool {
        header('Location: ' . $to, true, 301);
        return true;
    }
    /** Run a PHP file as if Apache had rewritten the request to it. */
    function __mw_local_target(string $docroot, string $script, string $query): string {
        $file = $docroot . $script;
        $_SERVER['SCRIPT_NAME']     = $script;
        $_SERVER['PHP_SELF']        = $script;
        $_SERVER['SCRIPT_FILENAME'] = $file;
        $_SERVER['QUERY_STRING']    = $query;
        $_GET = [];
        parse_str($query, $_GET);
        $_REQUEST = array_merge($_GET, $_POST);
        chdir(dirname($file));
        return $file;
    }
}

// ── Security headers production sends (subset that matters for behaviour) ──
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Local-Test-Site: 1');

// ── Denies ──
if (strpos($__path, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $__path)) { return __mw_local_deny(400); }
// Dotfiles / dot-directories (.git, .env, .htaccess, .DS_Store …) — .well-known stays public.
if (preg_match('#/\.(?!well-known/)[^/]*#', $__path)) { return __mw_local_deny(); }
$__denyDirs = '#^/(app|storage|vendor|sessions|app_config|crm/email-logs|crm/backup|crm/tmp|uploads/receipts|site-admin/includes)(/|$)#i';
if (preg_match($__denyDirs, $__path)) { return __mw_local_deny(); }
if (preg_match('#(^|/)(config|auth|session_config|secrets)\.php$#', $__path)) { return __mw_local_deny(); }
if (preg_match('#\.(md|log|sql|bak|orig|swp)$#i', $__path) || preg_match('#\.git-ftp\.log$#', $__path)) { return __mw_local_deny(); }
if (preg_match('#^/(_media|uploads)/#', $__path) && preg_match('#\.(ph(p\d*|t|tml|ar|ps)|inc|cgi|pl|py|sh|asp|aspx|jsp)$#i', $__path)) { return __mw_local_deny(); }
if (preg_match('#^/site-admin/css/.*\.php$#', $__path)) { return __mw_local_deny(); }

// ── Canonical 301s (production only fires these on the original request line) ──
if (preg_match('#^/(index\.php|home)/?$#i', $__path)) { return __mw_local_redirect('/' . ($__query !== '' ? '?' . $__query : '')); }
if (preg_match('#^/(about|contact|portfolio|privacy|privacy-request)\.php$#i', $__path, $__m)) { return __mw_local_redirect('/' . $__m[1] . ($__query !== '' ? '?' . $__query : '')); }
if (preg_match('#^/services/([a-z0-9-]+)\.php$#i', $__path, $__m)) { return __mw_local_redirect('/services/' . $__m[1] . ($__query !== '' ? '?' . $__query : '')); }
if (preg_match('#^/(about|contact|portfolio|privacy|privacy-request|quote|services|services/[a-z0-9-]+)/$#i', $__path, $__m)) { return __mw_local_redirect('/' . $__m[1] . ($__query !== '' ? '?' . $__query : '')); }

// ── Named rewrites ──
$__rewrites = [
    '#^/sitemap\.xml$#'          => ['/sitemap.php', ''],
    '#^/quote/?$#'               => ['/quote.php', ''],
    '#^/before-after/?$#'        => ['/before-after.php', ''],
    '#^/forgot-password/?$#'     => ['/forgot-password.php', ''],
    '#^/reset-password/?$#'      => ['/reset-password.php', ''],
    '#^/services/?$#'            => ['/cms-render.php', 'page=services'],
    '#^/privacy/?$#'             => ['/privacy.php', ''],
    '#^/privacy-request/?$#'     => ['/privacy-request.php', ''],
];
foreach ($__rewrites as $__re => [$__script, $__q]) {
    if (preg_match($__re, $__path) && is_file($__docroot . $__script)) {
        $__qs = trim($__q . ($__query !== '' ? ($__q !== '' ? '&' : '') . $__query : ''), '&');
        require __mw_local_target($__docroot, $__script, $__qs);
        return true;
    }
}
if (preg_match('#^/services/([a-z0-9\-]+)/?$#', $__path, $__m) && !is_file($__docroot . $__path) && is_file($__docroot . '/services/' . $__m[1] . '.php')) {
    require __mw_local_target($__docroot, '/services/' . $__m[1] . '.php', $__query);
    return true;
}

// ── /api/<module>/<action> (public/api/.htaccess) ──
if (strncmp($__path, '/api/', 5) === 0 && !is_file($__docroot . $__path)) {
    if (preg_match('#^/api/([a-z]+)/([a-z0-9\-]+)/?$#', $__path, $__m)) {
        // QSA: the rewrite's own params first, the original query appended after (PHP keeps the last).
        $__qs = 'module=' . $__m[1] . '&action=' . $__m[2] . ($__query !== '' ? '&' . $__query : '');
        require __mw_local_target($__docroot, '/api/index.php', $__qs);
        return true;
    }
    return __mw_local_deny(404);
}

// ── Real files ──
$__full = $__docroot . $__path;
if ($__path !== '/' && is_file($__full)) {
    return false;   // built-in server serves static files and runs .php files itself
}

// ── Directories: trailing slash + DirectoryIndex ──
if (is_dir($__full)) {
    if (substr($__path, -1) !== '/' && $__path !== '/services') {
        return __mw_local_redirect($__path . '/' . ($__query !== '' ? '?' . $__query : ''));
    }
    $__indexes = (strncmp($__path, '/crm/', 5) === 0 && $__path === '/crm/')
        ? ['dashboard_appstack.php', 'index.php']
        : ['index.php', 'index.html'];
    foreach ($__indexes as $__ix) {
        $__candidate = rtrim($__path, '/') . '/' . $__ix;
        if (is_file($__docroot . $__candidate)) {
            if (substr($__ix, -4) !== '.php') { return false; }
            require __mw_local_target($__docroot, $__candidate, $__query);
            return true;
        }
    }
    if ($__path !== '/') { return __mw_local_deny(); }   // Options -Indexes
}

// ── CMS catch-all ──
if (preg_match('#^/([a-z0-9\-/]+?)/?$#', $__path, $__m)) {
    $__qs = 'page=' . $__m[1] . ($__query !== '' ? '&' . $__query : '');
    require __mw_local_target($__docroot, '/cms-render.php', $__qs);
    return true;
}

return __mw_local_deny(404);
