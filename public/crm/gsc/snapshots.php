<?php
/**
 * /crm/gsc/snapshots.php
 * Returns GSC data arrays for parent include (portfolio index.php)
 *
 * Expects $db and $user to be available from parent scope.
 * If accessed directly, loads its own auth.
 */

// If included from another page, $user and $db should already exist
if (!isset($user) || !isset($db)) {
    require_once dirname(__DIR__) . '/../loginAuth/auth.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
    requireLogin();
    $user = getCurrentUser();
    $db = getDB();
}

if (!$user || ($user['role'] ?? '') !== 'admin') {
    return [
        'latest_snapshot' => null,
        'top_queries' => [],
        'top_pages' => [],
        'low_ctr' => [],
        'error' => 'Admin access required'
    ];
}

try {
    // Latest snapshot
    $stmt = $db->prepare("
        SELECT gs.id AS snapshot_id, gs.property_id, gp.site_url, gs.snapshot_date, gs.data_json, gs.pulled_at
        FROM gsc_snapshots gs
        JOIN gsc_properties gp ON gs.property_id = gp.id
        ORDER BY gs.snapshot_date DESC
        LIMIT 1
    ");
    $stmt->execute();
    $latestSnapshot = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
    error_log('GSC Snapshots - latest snapshot error: ' . $e->getMessage());
    $latestSnapshot = null;
}

// Top queries
$topQueries = [];
if ($latestSnapshot) {
    try {
        $stmt = $db->prepare("
            SELECT query, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(ctr) as ctr, AVG(position) as position
            FROM gsc_query_page_stats
            WHERE snapshot_id = ?
            GROUP BY query
            ORDER BY impressions DESC
            LIMIT 20
        ");
        $stmt->execute([(int)$latestSnapshot['snapshot_id']]);
        $topQueries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log('GSC Snapshots - top queries error: ' . $e->getMessage());
    }
}

// Top pages
$topPages = [];
if ($latestSnapshot) {
    try {
        $stmt = $db->prepare("
            SELECT page, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(ctr) as ctr, AVG(position) as position
            FROM gsc_query_page_stats
            WHERE snapshot_id = ?
            GROUP BY page
            ORDER BY clicks DESC
            LIMIT 20
        ");
        $stmt->execute([(int)$latestSnapshot['snapshot_id']]);
        $topPages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log('GSC Snapshots - top pages error: ' . $e->getMessage());
    }
}

// Low CTR opportunities
$lowCTR = [];
if ($latestSnapshot) {
    try {
        $stmt = $db->prepare("
            SELECT page, SUM(impressions) as impressions, AVG(ctr) as ctr, AVG(position) as position
            FROM gsc_query_page_stats
            WHERE snapshot_id = ?
            GROUP BY page
            HAVING impressions > 50 AND ctr < 0.03
            ORDER BY impressions DESC
            LIMIT 10
        ");
        $stmt->execute([(int)$latestSnapshot['snapshot_id']]);
        $lowCTR = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log('GSC Snapshots - low CTR error: ' . $e->getMessage());
    }
}

return [
    'latest_snapshot' => $latestSnapshot,
    'top_queries' => $topQueries,
    'top_pages' => $topPages,
    'low_ctr' => $lowCTR,
];
