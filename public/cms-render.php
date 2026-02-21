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
    // Load page from database
    $page = cms_getPageBySlug($pageSlug);

    // If page not found or not published, try to fall back to legacy page
    if (!$page || $page['status'] !== 'published') {
        cms_fallbackToLegacy($pageSlug);
    }

    // Page found and published - render it
    cms_renderPage($page);
} catch (\Throwable $e) {
    // Log error with full detail
    error_log("CMS Render Error [{$pageSlug}]: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());

    // Attempt fallback
    cms_fallbackToLegacy($pageSlug ?? 'home');
}

// ============================================================================
// FALLBACK TO LEGACY PAGES
// ============================================================================

/**
 * Attempt to fall back to a legacy PHP page
 *
 * @param string $slug Page slug
 * @return void (exits with 404 or loads legacy page)
 */
function cms_fallbackToLegacy(string $slug): void
{
    // Map of slug patterns to legacy files
    $legacyMap = [
        'home' => 'index.php',
        'portfolio' => 'portfolio.php',
        'about' => 'about.php',
        'contact' => 'contact.php',
        'services' => 'services_static.php',
        'quote' => 'quote.php',
        'get-free-quote' => 'get-free-quote.php',
    ];

    // Check for exact match
    if (isset($legacyMap[$slug])) {
        $legacyFile = __DIR__ . '/' . $legacyMap[$slug];
        if (file_exists($legacyFile)) {
            include $legacyFile;
            exit;
        }
    }

    // Check for service landing page pattern (/services/*)
    if (preg_match('/^services\/([a-z0-9\-]+)$/', $slug, $m)) {
        $serviceFile = __DIR__ . '/services/' . $m[1] . '.php';
        if (file_exists($serviceFile)) {
            include $serviceFile;
            exit;
        }
    }

    // No fallback found - 404
    header('HTTP/1.1 404 Not Found');
    include __DIR__ . '/404.php';
    exit;
}
