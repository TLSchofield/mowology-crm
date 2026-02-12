<?php
/**
 * /app/Modules/Marketing/Api/generate.php
 * AJAX Endpoint: Manually trigger recommendation generation
 *
 * POST /crm/api/seo/generate.php
 * Requires: admin auth + CSRF token
 *
 * Response: {success: bool, message: string, stats: {...}}
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json');

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/seo-functions.php';

requireLogin();
$user = getCurrentUser();

if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Admin access required']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'POST required']));
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'CSRF token invalid']));
}

// Run the recommendation engine directly (same logic as the cron script)
// We don't include the cron file because it has its own auth/exit flow.
$db = getDB();
$startTime = time();

$stats = [
    'queries_analyzed' => 0,
    'recommendations_generated' => 0,
    'recommendations_updated' => 0,
    'errors' => 0,
    'runtime_seconds' => 0,
];

try {
    // STEP 1: Get latest GSC snapshot
    $snapshotStmt = $db->prepare("
        SELECT gs.id, gp.site_url
        FROM gsc_snapshots gs
        JOIN gsc_properties gp ON gs.property_id = gp.id
        WHERE gs.snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
        ORDER BY gs.snapshot_date DESC
        LIMIT 1
    ");
    $snapshotStmt->execute();
    $snapshot = $snapshotStmt->fetch(PDO::FETCH_ASSOC);

    if (!$snapshot) {
        die(json_encode(['success' => false, 'message' => 'No GSC snapshot found in last 28 days. Run Sync Now first.', 'stats' => $stats]));
    }

    // STEP 2: Load season
    $currentSeason = getCurrentSeason($db);
    $seasonId = $currentSeason['id'] ?? null;

    // STEP 3: Pull top queries from snapshot
    $queryStmt = $db->prepare("
        SELECT
            query,
            SUM(impressions) AS total_impressions,
            SUM(clicks) AS total_clicks,
            AVG(ctr) AS avg_ctr,
            AVG(position) AS avg_position,
            COUNT(DISTINCT page) AS page_count,
            MAX(page) AS sample_page
        FROM gsc_query_page_stats
        WHERE snapshot_id = ?
          AND query IS NOT NULL
          AND query != ''
        GROUP BY query
        ORDER BY total_impressions DESC
        LIMIT 1000
    ");
    $queryStmt->execute([$snapshot['id']]);
    $queryRows = $queryStmt->fetchAll(PDO::FETCH_ASSOC);

    // STEP 4: Insert/update recommendations
    $insertStmt = $db->prepare("
        INSERT INTO seo_recommendations
            (query_text, search_volume, clicks, ctr, avg_position, suggested_slug, rec_type, priority_score, target_id, season_id, reason, status, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', NOW())
        ON DUPLICATE KEY UPDATE
            search_volume = VALUES(search_volume),
            clicks = VALUES(clicks),
            ctr = VALUES(ctr),
            avg_position = VALUES(avg_position),
            priority_score = VALUES(priority_score),
            reason = VALUES(reason),
            updated_at = NOW()
    ");

    foreach ($queryRows as $row) {
        $stats['queries_analyzed']++;

        if ((int)$row['total_impressions'] < 15) {
            continue;
        }

        $matchedTargetId = detectMatchingTarget($row['query'], $db);

        $scoreResult = scoreRecommendation([
            'query' => $row['query'],
            'impressions' => (int)$row['total_impressions'],
            'clicks' => (int)$row['total_clicks'],
            'ctr' => (float)$row['avg_ctr'],
            'position' => (float)$row['avg_position'],
            'existing_page' => !empty($row['sample_page']),
            'target_id' => $matchedTargetId,
            'season_id' => $seasonId
        ], $db);

        $score = (int)($scoreResult['score'] ?? 0);

        if ($score < 40) {
            continue;
        }

        $recType = selectRecType($row, $score, !empty($row['sample_page']));
        $slug = generateSlug($row['query'], $matchedTargetId, $db);

        try {
            $insertStmt->execute([
                $row['query'],
                (int)$row['total_impressions'],
                (int)$row['total_clicks'],
                (float)$row['avg_ctr'],
                (float)$row['avg_position'],
                $slug,
                $recType,
                $score,
                $matchedTargetId,
                $seasonId,
                (string)($scoreResult['reason'] ?? '')
            ]);

            $affected = $insertStmt->rowCount();
            if ($affected === 1) {
                $stats['recommendations_generated']++;
            } elseif ($affected === 2) {
                $stats['recommendations_updated']++;
            }
        } catch (PDOException $e) {
            $stats['errors']++;
            error_log("SEO Recommendation insertion failed: " . $e->getMessage());
        }
    }

    // STEP 5: Summary
    $stats['runtime_seconds'] = time() - $startTime;

    $message = sprintf(
        "SEO Recommendations: %d analyzed, %d generated, %d updated, %d errors in %ds",
        $stats['queries_analyzed'],
        $stats['recommendations_generated'],
        $stats['recommendations_updated'],
        $stats['errors'],
        $stats['runtime_seconds']
    );

    die(json_encode(['success' => true, 'message' => $message, 'stats' => $stats]));

} catch (Throwable $e) {
    $stats['errors']++;
    error_log("SEO Recommendations Error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'stats' => $stats]));
}
