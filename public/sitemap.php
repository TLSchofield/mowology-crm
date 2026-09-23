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

// Cache in /tmp. The file name carries this script's mtime so a deploy of
// sitemap.php invalidates the cache instead of serving the old output for 24h.
$cacheFile = sys_get_temp_dir() . '/mowology_sitemap_' . (int)filemtime(__FILE__) . '.xml';
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

    // Slugs that must never be listed: they redirect (quote → jobFlow, which
    // robots.txt disallows) or are aliases of another URL (home → /).
    $skipSlugs = ['quote', 'get-free-quote'];

    // Collect entries keyed by URL so CMS pages and static pages never duplicate.
    $entries = [];

    foreach ($pages as $page) {
        $slug = trim((string)$page['slug'], '/');
        if (in_array($slug, $skipSlugs, true)) {
            continue;
        }

        // Use stored canonical if present, otherwise build from slug
        $loc = !empty($page['canonical_url'])
            ? $page['canonical_url']
            : $siteUrl . '/' . $slug;

        // Use seo_getCanonicalUrl if available (legacy compat)
        if (function_exists('seo_getCanonicalUrl')) {
            $loc = seo_getCanonicalUrl($page);
        }

        // The CMS home page is served at / — never advertise the /home alias.
        if ($slug === 'home') {
            $loc = $siteUrl . '/';
        }

        $meta = $sitemapPriority[$page['page_type']] ?? $defaultSitemapMeta;
        $entries[$loc] = [
            'lastmod'    => date('Y-m-d', strtotime($page['updated_at'])),
            'changefreq' => $meta['changefreq'],
            'priority'   => $meta['priority'],
        ];
    }

    // Static (non-CMS) public pages. Listed only when the CMS has no page at
    // the same URL, using the file's modification time as lastmod.
    $staticPages = [
        '/'         => ['file' => 'index.php',    'type' => 'home'],
        '/services' => ['file' => 'services_static.php', 'type' => 'services'],
        '/about'    => ['file' => 'about.php',    'type' => 'about'],
        '/contact'  => ['file' => 'contact.php',  'type' => 'contact'],
        '/privacy'  => ['file' => 'privacy.php',  'type' => 'custom'],
    ];
    foreach (glob(__DIR__ . '/services/*.php') ?: [] as $serviceFile) {
        $serviceSlug = basename($serviceFile, '.php');
        $staticPages['/services/' . $serviceSlug] = ['file' => 'services/' . $serviceSlug . '.php', 'type' => 'service_landing'];
    }
    foreach ($staticPages as $path => $info) {
        $loc  = $siteUrl . $path;
        $file = __DIR__ . '/' . $info['file'];
        if (isset($entries[$loc]) || !file_exists($file)) {
            continue;
        }
        $meta = $sitemapPriority[$info['type']] ?? $defaultSitemapMeta;
        $entries[$loc] = [
            'lastmod'    => date('Y-m-d', filemtime($file) ?: time()),
            'changefreq' => $meta['changefreq'],
            'priority'   => $meta['priority'],
        ];
    }

    // Homepage first, then by priority, then alphabetically — stable and readable.
    uksort($entries, function ($a, $b) use ($entries, $siteUrl) {
        if ($a === $siteUrl . '/') return -1;
        if ($b === $siteUrl . '/') return 1;
        $cmp = strcmp($entries[$b]['priority'], $entries[$a]['priority']);
        return $cmp !== 0 ? $cmp : strcmp($a, $b);
    });

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

    foreach ($entries as $loc => $meta) {
        $xml .= '  <url>' . "\n";
        $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>' . "\n";
        $xml .= '    <lastmod>' . $meta['lastmod'] . '</lastmod>' . "\n";
        $xml .= '    <changefreq>' . $meta['changefreq'] . '</changefreq>' . "\n";
        $xml .= '    <priority>' . $meta['priority'] . '</priority>' . "\n";
        $xml .= '  </url>' . "\n";
    }

    // Portfolio page is not a CMS page; list it with its approved before/after images
    // (Google image sitemap extension) so each transformation is discoverable.
    try {
        require_once APP_ROOT . '/Modules/Portfolio/Services/BeforeAfterService.php';
        $baService = new BeforeAfterService($db);
        $baPairs   = $baService->published(100);
        if (isset($entries[$siteUrl . '/portfolio'])) {
            throw new RuntimeException('portfolio already listed');
        }
        $xml .= '  <url>' . "\n";
        $xml .= '    <loc>' . $siteUrl . '/portfolio</loc>' . "\n";
        $xml .= '    <changefreq>weekly</changefreq>' . "\n";
        $xml .= '    <priority>' . ($baPairs ? '0.8' : '0.6') . '</priority>' . "\n";
        foreach ($baPairs as $bp) {
            foreach (['before', 'after'] as $side) {
                $xml .= '    <image:image>' . "\n";
                $xml .= '      <image:loc>' . htmlspecialchars($siteUrl . $bp[$side . '_url'], ENT_XML1) . '</image:loc>' . "\n";
                $xml .= '      <image:title>' . htmlspecialchars($bp['label'] . ' — ' . $side, ENT_XML1) . '</image:title>' . "\n";
                $xml .= '      <image:caption>' . htmlspecialchars($bp['alt_' . $side], ENT_XML1) . '</image:caption>' . "\n";
                $xml .= '    </image:image>' . "\n";
            }
        }
        $xml .= '  </url>' . "\n";
    } catch (Throwable $e) {
        // ba_pairs unavailable — the rest of the sitemap is unaffected
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
