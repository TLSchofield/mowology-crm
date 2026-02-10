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

try {
    $db = getDB();
    $pages = $db->query("
        SELECT id, slug, title, updated_at, page_type, status
        FROM cms_pages
        WHERE status = 'published' AND noindex = 0
        ORDER BY updated_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach ($pages as $page) {
        $xml .= '  <url>' . "\n";
        $xml .= '    <loc>' . htmlspecialchars(seo_getCanonicalUrl($page), ENT_XML1) . '</loc>' . "\n";
        $xml .= '    <lastmod>' . date('Y-m-d', strtotime($page['updated_at'])) . '</lastmod>' . "\n";
        $xml .= '    <changefreq>weekly</changefreq>' . "\n";
        $xml .= '    <priority>' . (($page['page_type'] === 'home') ? '1.0' : '0.8') . '</priority>' . "\n";
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
