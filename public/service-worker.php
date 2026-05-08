<?php
/**
 * Service Worker wrapper — dynamic CACHE_VERSION.
 * ─────────────────────────────────────────────────
 * Serves the contents of service-worker.js with the CACHE_VERSION literal
 * rewritten to reflect the current file mtime of the SW source (plus a
 * short list of cache-sensitive dependencies). Any deploy that updates
 * those files auto-bumps the version, busting old caches on next install.
 *
 * Registered from appstack_footer.php as '/service-worker.php'.
 * Scope is '/' — same as the previous '/service-worker.js' registration.
 *
 * Why PHP and not a build step? This repo has no build pipeline (vanilla
 * PHP on cPanel shared hosting). A thin PHP wrapper is the simplest way
 * to produce a deterministic, auto-bumping version without introducing
 * npm/webpack/etc.
 */
declare(strict_types=1);

// Service workers MUST be served with a JS content-type and must not be
// long-cached by the browser — otherwise updates won't reach users.
header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: /');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$swPath = __DIR__ . '/service-worker.js';
if (!is_file($swPath)) {
    http_response_code(500);
    echo "// service-worker.js missing\n";
    exit;
}

// Files whose mtime influences the cache key. Add any file here whose
// change should force a cache flush on the next SW install.
$deps = [
    $swPath,
    __DIR__ . '/assets/favicon/site.webmanifest',
    __DIR__ . '/crm/offline.html',
];

$maxMtime = 0;
foreach ($deps as $f) {
    if (is_file($f)) {
        $m = filemtime($f);
        if ($m > $maxMtime) $maxMtime = $m;
    }
}
if ($maxMtime <= 0) $maxMtime = time();

// Compact YYYYMMDD-HHMM version — new on any deploy-day rebuild
$version = 'mw-' . gmdate('Ymd-Hi', $maxMtime);

$body = file_get_contents($swPath);
if ($body === false) {
    http_response_code(500);
    echo "// service-worker.js unreadable\n";
    exit;
}

// Substitute the CACHE_VERSION literal. The source file keeps a static
// fallback value so direct requests to service-worker.js still work.
$body = preg_replace(
    "/var CACHE_VERSION = '[^']+';/",
    "var CACHE_VERSION = '{$version}'; // dynamic via service-worker.php",
    $body,
    1
);

echo $body;
