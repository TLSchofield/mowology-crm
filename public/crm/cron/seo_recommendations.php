<?php
/**
 * /crm/cron/seo_recommendations.php
 * Daily SEO Recommendation Generation Engine
 *
 * Purpose:
 *   Read GSC query stats from last 28 days
 *   Apply scoring rules to identify high-value opportunities
 *   Generate/update recommendations in seo_recommendations table
 *   Idempotent: safe to run multiple times per day
 *
 * Run via cron (daily @ 3 AM after GSC sync):
 *   0 3 * * * php /home/mowology/public_html/crm/cron/seo_recommendations.php
 *
 * Also callable via web with admin auth + CSRF token
 */

declare(strict_types=1);

// ============================================================================
// INITIALIZATION
// ============================================================================

require_once dirname(__DIR__) . '/../app_config/config.php';
require_once dirname(__DIR__) . '/includes/seo-functions.php';

// Determine context: CLI or web request
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    // Web request: require auth + CSRF
    require_once dirname(__DIR__) . '/../loginAuth/auth.php';
    requireLogin();
    $user = getCurrentUser();

    if (!$user || $user['role'] !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Admin access required']));
    }

    // CSRF protection
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'CSRF token invalid']));
    }
} else {
    // CLI: set a fake user context for logging
    $user = ['id' => null, 'role' => 'system'];
}

$db = getDB();
$startTime = time();
$stats = [
    'queries_analyzed' => 0,
    'recommendations_generated' => 0,
    'recommendations_updated' => 0,
    'errors' => 0,
    'runtime_seconds' => 0
];

try {
    // =========================================================================
    // STEP 1: Get latest GSC snapshot (last 28 days aggregated)
    // =========================================================================

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
        $msg = "No GSC snapshot found in last 28 days. Run Sync Now first.";
        if (!$isCli) {
            header('Content-Type: application/json');
            die(json_encode(['success' => false, 'message' => $msg]));
        } else {
            echo "[ERROR] $msg\n";
            exit(1);
        }
    }

    if ($isCli) {
        echo "[INFO] Using GSC snapshot from " . $snapshot['id'] . " (site: {$snapshot['site_url']})\n";
    }

    // =========================================================================
    // STEP 2: Get all active targets & current season (for matching)
    // =========================================================================

    $targetStmt = $db->query("SELECT id, name, target_type, canonical_slug, city, postcode_prefix, neighbourhood, priority_weight FROM seo_targets WHERE is_active = 1");
    $targets = $targetStmt->fetchAll(PDO::FETCH_ASSOC);
    $targetsById = [];
    foreach ($targets as $t) {
        $targetsById[$t['id']] = $t;
    }

    if ($isCli && count($targets) > 0) {
        echo "[INFO] Loaded " . count($targets) . " active targets\n";
    }

    $currentSeason = getCurrentSeason($db);
    $seasonId = $currentSeason['id'] ?? null;

    if ($isCli && $seasonId) {
        echo "[INFO] Current season: {$currentSeason['season_key']} (ID: $seasonId)\n";
    }

    // =========================================================================
    // STEP 3: Query GSC stats & generate recommendations
    // =========================================================================

    // Group by query_text to aggregate multiple pages per query
    $queryStmt = $db->prepare("
        SELECT
            query,
            SUM(impressions) as total_impressions,
            SUM(clicks) as total_clicks,
            AVG(ctr) as avg_ctr,
            AVG(position) as avg_position,
            COUNT(DISTINCT page) as page_count,
            MAX(page) as sample_page
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

    if ($isCli) {
        echo "[INFO] Found " . count($queryRows) . " unique queries to analyze\n";
    }

    // =========================================================================
    // STEP 4: Score & insert/update recommendations
    // =========================================================================

    $insertStmt = $db->prepare("
        INSERT INTO seo_recommendations
        (query_text, search_volume, clicks, ctr, avg_position, suggested_slug, rec_type, priority_score, target_id, season_id, reason, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', NOW())
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

        // Skip if below minimum threshold
        if ((int)$row['total_impressions'] < 15) {
            continue;
        }

        // Detect matching target
        $matchedTargetId = detectMatchingTarget($row['query'], $db);

        // Score the query
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

        $score = $scoreResult['score'];

        // Skip low scores
        if ($score < 40) {
            continue;
        }

        // Select recommendation type
        $recType = selectRecType($row, $score, !empty($row['sample_page']));

        // Generate slug
        $slug = generateSlug($row['query'], $matchedTargetId, $db);

        // Insert or update
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
                $scoreResult['reason']
            ]);

            $affectedRows = $insertStmt->rowCount();
            if ($affectedRows === 1) {
                $stats['recommendations_generated']++;
                if ($isCli) {
                    echo "[GEN] {$row['query']} → $slug (score: $score, type: $recType)\n";
                }
            } elseif ($affectedRows === 2) {
                // ON DUPLICATE KEY UPDATE counts as 2 rows affected
                $stats['recommendations_updated']++;
                if ($isCli) {
                    echo "[UPD] {$row['query']} → $slug (score: $score)\n";
                }
            }

            // Log action if web request
            if (!$isCli && $user['id']) {
                logRecommendationAction(
                    0, // Will use last_insert_id, but we can't get it here in this context
                    'generated',
                    $user['id'],
                    null,
                    'new',
                    "Auto-generated by cron",
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $db
                );
            }

        } catch (PDOException $e) {
            $stats['errors']++;
            if ($isCli) {
                echo "[ERR] Failed to insert recommendation for: {$row['query']}\n";
                echo "     " . $e->getMessage() . "\n";
            }
            error_log("SEO Recommendation insertion failed: " . $e->getMessage());
        }
    }

    // =========================================================================
    // STEP 5: Summary & response
    // =========================================================================

    $stats['runtime_seconds'] = time() - $startTime;

    $message = sprintf(
        "SEO Recommendations: %d analyzed, %d generated, %d updated, %d errors in %ds",
        $stats['queries_analyzed'],
        $stats['recommendations_generated'],
        $stats['recommendations_updated'],
        $stats['errors'],
        $stats['runtime_seconds']
    );

    if ($isCli) {
        echo "\n[DONE] $message\n";
        exit(0);
    } else {
        header('Content-Type: application/json');
        http_response_code(200);
        die(json_encode([
            'success' => true,
            'message' => $message,
            'stats' => $stats
        ]));
    }

} catch (Throwable $e) {
    $stats['errors']++;
    $errorMsg = "SEO Recommendations Error: " . $e->getMessage();

    if ($isCli) {
        echo "\n[ERROR] $errorMsg\n";
        echo $e->getTraceAsString() . "\n";
        exit(1);
    } else {
        error_log($errorMsg);
        http_response_code(500);
        header('Content-Type: application/json');
        die(json_encode([
            'success' => false,
            'message' => $errorMsg,
            'stats' => $stats
        ]));
    }
}
