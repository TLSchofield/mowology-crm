<?php
/**
 * CMS Page Renderer
 *
 * Main entry point for CMS-managed pages.
 * Routes requests to cms-based pages or falls back to legacy pages.
 *
 * Usage:
 *   /cms-render.php?page=home (direct URL)
 *   /home (via .htaccess rewrite)
 *   /services/strata-landscaping (via .htaccess rewrite)
 *
 * @package Mowology
 * @subpackage CMS
 */

declare(strict_types=1);

// Load bootstrap
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/crm/includes/cms-functions.php';
require_once __DIR__ . '/crm/includes/cms-token-engine.php';
require_once __DIR__ . '/crm/includes/cms-renderer.php';

// Get requested page slug
$pageSlug = $_GET['page'] ?? null;

// If no slug via query string, derive from request URI
if (!$pageSlug) {
    // Strip query string, then remove leading/trailing slashes + extensions
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
    $uri = trim($uri, '/');
    $uri = preg_replace('/\.(php|html)$/', '', $uri);

    // Trailing slash normalisation: /about/ → /about (canonical)
    $uri = rtrim($uri, '/');

    $pageSlug = ($uri === '' || $uri === '/') ? 'home' : $uri;
}

// Trailing-slash normalisation on query-string slugs too
$pageSlug = rtrim($pageSlug, '/');
if ($pageSlug === '') {
    $pageSlug = 'home';
}

// Security: sanitize slug (lowercase, allow hyphens + forward slashes for sub-paths)
$pageSlug = preg_replace('/[^a-z0-9\-\/]/', '', strtolower($pageSlug));

if (empty($pageSlug)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid page slug');
}

try {
    // Load page from database (returns any status — live check below)
    $page = cms_getPageBySlug($pageSlug);

    // If page not found or not live (status + scheduling window), check for redirect then fall back
    if (!$page || !cms_isPageLive($page)) {
        cms_checkRedirectOrFallback($pageSlug);
    }

    // --- HTML Cache (P1-B) ---
    // Serve cached HTML if fresh; otherwise render and cache the result.
    $cachedHtml = cms_getPageHtmlCache((int)$page['id']);
    if ($cachedHtml !== null) {
        echo $cachedHtml;
        exit;
    }

    ob_start();
    cms_renderPage($page);
    $renderedHtml = ob_get_clean();

    // Store in persistent cache (1-hour TTL, invalidated on any page/block save)
    cms_setPageHtmlCache((int)$page['id'], $renderedHtml);

    echo $renderedHtml;
} catch (\Throwable $e) {
    // Log error with full detail
    error_log("CMS Render Error [{$pageSlug}]: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());

    // Attempt fallback (also checks redirects)
    cms_checkRedirectOrFallback($pageSlug ?? 'home');
}

// ============================================================================
// FALLBACK TO LEGACY PAGES
// ============================================================================

/**
 * Check for a CMS redirect, then fall back to legacy PHP, then 404.
 * This is the single exit point for any page that isn't live in the CMS.
 *
 * @param string $slug Requested slug
 * @return void (never returns — always exits)
 */
function cms_checkRedirectOrFallback(string $slug): void
{
    // 1. Check cms_page_redirects for a 301/302 rule (P1-A)
    if (function_exists('cms_getRedirectForSlug')) {
        $redirect = cms_getRedirectForSlug($slug);
        if ($redirect) {
            $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
            $code    = (int)($redirect['status_code'] ?? 301);
            $target  = $siteUrl . '/' . ltrim($redirect['to_slug'], '/');
            header('Location: ' . $target, true, $code);
            exit;
        }
    }

    // 2. Legacy PHP fallback map
    $legacyMap = [
        'home'          => 'index.php',
        'portfolio'     => 'portfolio.php',
        'about'         => 'about.php',
        'contact'       => 'contact.php',
        'services'      => 'services_static.php',
        'quote'         => 'quote.php',
        'get-free-quote' => 'get-free-quote.php',
    ];

    if (isset($legacyMap[$slug])) {
        $legacyFile = __DIR__ . '/' . $legacyMap[$slug];
        if (file_exists($legacyFile)) {
            include $legacyFile;
            exit;
        }
    }

    // 3. Service landing page pattern (/services/*)
    if (preg_match('/^services\/([a-z0-9\-]+)$/', $slug, $m)) {
        $serviceFile = __DIR__ . '/services/' . $m[1] . '.php';
        if (file_exists($serviceFile)) {
            include $serviceFile;
            exit;
        }
    }

    // 4. Nothing found — 404
    header('HTTP/1.1 404 Not Found');
    $notFound = __DIR__ . '/404.php';
    if (file_exists($notFound)) {
        include $notFound;
    } else {
        echo '<h1>404 Not Found</h1>';
    }
    exit;
}

/**
 * Legacy alias kept for any code that still calls cms_fallbackToLegacy().
 * @deprecated Use cms_checkRedirectOrFallback() directly.
 */
function cms_fallbackToLegacy(string $slug): void
{
    cms_checkRedirectOrFallback($slug);
}
