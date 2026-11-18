<?php
/**
 * RSS 2.0 feed — /blog/feed
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/crm/includes/cms-functions.php';
require_once APP_ROOT . '/Modules/CMS/Services/ArticleService.php';

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$articles = [];
try {
    $svc      = new ArticleService(getDB());
    $articles = $svc->listPublished(20);
} catch (\Throwable $e) {
    error_log('blog feed: ' . $e->getMessage());
}

$x = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n<channel>\n";
echo '  <title>' . $x(SITE_NAME . ' — Landscaping Tips & Guides') . "</title>\n";
echo '  <link>' . $x(SITE_URL . '/blog') . "</link>\n";
echo '  <atom:link href="' . $x(SITE_URL . '/blog/feed') . '" rel="self" type="application/rss+xml" />' . "\n";
echo '  <description>' . $x('Practical guides on strata, commercial and residential landscaping in Vancouver, Burnaby and Richmond.') . "</description>\n";
echo '  <language>en-ca</language>' . "\n";
foreach ($articles as $a) {
    $when = $a['published_at'] ?? $a['created_at'] ?? date('Y-m-d H:i:s');
    echo "  <item>\n";
    echo '    <title>' . $x((string)$a['title']) . "</title>\n";
    echo '    <link>' . $x(SITE_URL . '/' . $a['slug']) . "</link>\n";
    echo '    <guid isPermaLink="true">' . $x(SITE_URL . '/' . $a['slug']) . "</guid>\n";
    echo '    <pubDate>' . $x(date(DATE_RSS, strtotime((string)$when))) . "</pubDate>\n";
    echo '    <description>' . $x((string)$a['excerpt']) . "</description>\n";
    echo "  </item>\n";
}
echo "</channel>\n</rss>\n";
