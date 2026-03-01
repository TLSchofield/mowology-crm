<?php
/**
 * Sitemap Generator
 *
 * Generates sitemap.xml with all published pages.
 * Automatically refreshes every 24 hours.
 */

declare(strict_types=1);

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=86400');  // Cache for 24 hours

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/crm/includes/cms-functions.php';
require_once __DIR__ . '/crm/includes/seo-functions.php';

// Cache in /tmp
$cacheFile = sys_get_temp_dir() . '/mowology_sitemap.xml';
$cacheTime = 86400;  // 24 hours

// Return cached if recent
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
    echo file_get_contents($cacheFile);
    exit;
}

// Priority + changefreq per page_type (P1-D)
$sitemapPriority = [
    'home'            => ['priority' => '1.0', 'changefreq' => 'daily'],
    'service_landing' => ['priority' => '0.9', 'changefreq' => 'weekly'],
    'services'        => ['priority' => '0.8', 'changefreq' => 'weekly'],
    'about'           => ['priority' => '0.7', 'changefreq' => 'monthly'],
    'contact'         => ['priority' => '0.7', 'changefreq' => 'monthly'],
    'portfolio'       => ['priority' => '0.6', 'changefreq' => 'weekly'],
    'custom'          => ['priority' => '0.5', 'changefreq' => 'monthly'],
    'landing'         => ['priority' => '0.8', 'changefreq' => 'weekly'],
];
$defaultSitemapMeta = ['priority' => '0.5', 'changefreq' => 'monthly'];

try {
    $db = getDB();
    // Include scheduling window filter (P1-D / P1-F)
    $pages = $db->query("
        SELECT id, slug, title, updated_at, page_type, canonical_url
        FROM cms_pages
        WHERE status = 'published'
          AND noindex = 0
          AND (publish_at IS NULL OR publish_at <= NOW())
          AND (unpublish_at IS NULL OR unpublish_at > NOW())
        ORDER BY updated_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach ($pages as $page) {
        // Use stored canonical if present, otherwise build from slug
        $loc = !empty($page['canonical_url'])
            ? $page['canonical_url']
            : $siteUrl . '/' . ltrim($page['slug'], '/');

        // Use seo_getCanonicalUrl if available (legacy compat)
        if (function_exists('seo_getCanonicalUrl')) {
            $loc = seo_getCanonicalUrl($page);
        }

        $meta = $sitemapPriority[$page['page_type']] ?? $defaultSitemapMeta;

        $xml .= '  <url>' . "\n";
        $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>' . "\n";
        $xml .= '    <lastmod>' . date('Y-m-d', strtotime($page['updated_at'])) . '</lastmod>' . "\n";
        $xml .= '    <changefreq>' . $meta['changefreq'] . '</changefreq>' . "\n";
        $xml .= '    <priority>' . $meta['priority'] . '</priority>' . "\n";
        $xml .= '  </url>' . "\n";
    }

    $xml .= '</urlset>';

    // Cache it
    @file_put_contents($cacheFile, $xml);

    echo $xml;

} catch (Exception $e) {
    error_log("Sitemap generation error: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
}
