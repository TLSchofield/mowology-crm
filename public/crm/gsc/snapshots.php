<?php
/**
 * /crm/gsc/snapshots.php
 * Displays GSC data and insights
 */

declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

// Only admins can view GSC data
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    die('Admin access required');
}

$db = getDB();

// Get latest snapshot
$stmt = $db->prepare("
    SELECT gp.id, gp.site_url, gs.snapshot_date, gs.data_json, gs.pulled_at
    FROM gsc_snapshots gs
    JOIN gsc_properties gp ON gs.property_id = gp.id
    ORDER BY gs.snapshot_date DESC
    LIMIT 1
");
$stmt->execute();
$latestSnapshot = $stmt->fetch(PDO::FETCH_ASSOC);

// Get top queries
$topQueries = [];
if ($latestSnapshot) {
    $stmt = $db->prepare("
        SELECT query, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(ctr) as ctr, AVG(position) as position
        FROM gsc_query_page_stats
        WHERE snapshot_id = ?
        GROUP BY query
        ORDER BY impressions DESC
        LIMIT 20
    ");
    $stmt->execute([$latestSnapshot['id']]);
    $topQueries = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get top pages
$topPages = [];
if ($latestSnapshot) {
    $stmt = $db->prepare("
        SELECT page, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(ctr) as ctr, AVG(position) as position
        FROM gsc_query_page_stats
        WHERE snapshot_id = ?
        GROUP BY page
        ORDER BY clicks DESC
        LIMIT 20
    ");
    $stmt->execute([$latestSnapshot['id']]);
    $topPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get low CTR opportunities (high impressions, low clicks)
$lowCTR = [];
if ($latestSnapshot) {
    $stmt = $db->prepare("
        SELECT page, SUM(impressions) as impressions, AVG(ctr) as ctr, AVG(position) as position
        FROM gsc_query_page_stats
        WHERE snapshot_id = ?
        GROUP BY page
        HAVING impressions > 50 AND ctr < 0.03
        ORDER BY impressions DESC
        LIMIT 10
    ");
    $stmt->execute([$latestSnapshot['id']]);
    $lowCTR = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

return [
    'latest_snapshot' => $latestSnapshot,
    'top_queries' => $topQueries,
    'top_pages' => $topPages,
    'low_ctr' => $lowCTR,
];
