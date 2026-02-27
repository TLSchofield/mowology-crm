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
 * Also callable via web with admin auth + CSRF token (POST only)
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

// ============================================================================
// CONTEXT + SAFE JSON RESPONDER (prevents "empty response" in web mode)
// ============================================================================

$isCli = (php_sapi_name() === 'cli');

function jsonRespond(bool $success, string $message, array $extra = [], int $httpCode = 200): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($httpCode);
    }
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
}

// Catch fatals that normally produce a blank response in web mode
if (!$isCli) {
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            // If output already started, we still try to emit JSON
            $msg = "Fatal error: {$err['message']} in {$err['file']}:{$err['line']}";
            jsonRespond(false, $msg, ['error' => $err], 500);
        }
    });
}

// Optional: temporary debugging (turn off after fixed)
// Comment these out once resolved.
error_reporting(E_ALL);
ini_set('display_errors', $isCli ? '1' : '0');
ini_set('log_errors', '1');

// ============================================================================
// INITIALIZATION (guard includes so failures return JSON, not empties)
// ============================================================================

try {
    require_once PUBLIC_ROOT . '/app_config/config.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/seo-functions.php';
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, "[FATAL] Bootstrap include failed: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    jsonRespond(false, "Bootstrap include failed: " . $e->getMessage(), [], 500);
}

// ============================================================================
// WEB SECURITY (admin + CSRF, POST only)
// ============================================================================

$user = ['id' => null, 'role' => 'system'];

if (!$isCli) {
    // Require POST to avoid accidental GET calls returning HTML/empty
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonRespond(false, 'POST required', [], 405);
    }

    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    $user = getCurrentUser();

    if (!$user || ($user['role'] ?? '') !== 'admin') {
        jsonRespond(false, 'Admin access required', [], 403);
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($csrfToken)) {
        jsonRespond(false, 'CSRF token invalid', [], 403);
    }
}

// ============================================================================
// RUN ENGINE
// ============================================================================

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
    // ------------------------------------------------------------------------
    // STEP 1: Get latest GSC snapshot
    // ------------------------------------------------------------------------
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
        if ($isCli) {
            echo "[ERROR] $msg\n";
            exit(1);
        }
        jsonRespond(false, $msg, ['stats' => $stats], 400);
    }

    if ($isCli) {
        echo "[INFO] Using GSC snapshot {$snapshot['id']} (site: {$snapshot['site_url']})\n";
    }

    // ------------------------------------------------------------------------
    // STEP 2: Load targets + season
    // ------------------------------------------------------------------------
    $targetStmt = $db->query("
        SELECT id, name, target_type, canonical_slug, city, postcode_prefix, neighbourhood, priority_weight
        FROM seo_targets
        WHERE is_active = 1
    ");
    $targets = $targetStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($isCli) {
        echo "[INFO] Loaded " . count($targets) . " active targets\n";
    }

    $currentSeason = getCurrentSeason($db);
    $seasonId = $currentSeason['id'] ?? null;

    if ($isCli && $seasonId) {
        echo "[INFO] Current season: {$currentSeason['season_key']} (ID: $seasonId)\n";
    }

    // ------------------------------------------------------------------------
    // STEP 3: Pull top queries from snapshot
    // ------------------------------------------------------------------------
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

    if ($isCli) {
        echo "[INFO] Found " . count($queryRows) . " unique queries to analyze\n";
    }

    // ------------------------------------------------------------------------
    // STEP 4: Insert/update recommendations
    // ------------------------------------------------------------------------
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
                if ($isCli) echo "[GEN] {$row['query']} → $slug (score: $score, type: $recType)\n";
            } elseif ($affected === 2) {
                $stats['recommendations_updated']++;
                if ($isCli) echo "[UPD] {$row['query']} → $slug (score: $score)\n";
            }

        } catch (PDOException $e) {
            $stats['errors']++;
            error_log("SEO Recommendation insertion failed: " . $e->getMessage());
            if ($isCli) {
                echo "[ERR] Insert failed for: {$row['query']}\n";
                echo "      " . $e->getMessage() . "\n";
            }
        }
    }

    // ------------------------------------------------------------------------
    // STEP 5: Summary
    // ------------------------------------------------------------------------
    $stats['runtime_seconds'] = time() - $startTime;

    $message = sprintf(
        "SEO Recommendations: %d analyzed, %d generated, %d updated, %d errors in %ds",
        $stats['queries_analyzed'],
        $stats['recommendations_generated'],
        $stats['recommendations_updated'],
        $stats['errors'],
        $stats['runtime_seconds']
    );

    recordCronRun(
        'seo_recommendations',
        $stats['errors'] > 0 ? 'warning' : 'success',
        $message,
        isset($stats['runtime_seconds']) ? (int)($stats['runtime_seconds'] * 1000) : null,
        null,
        !$isCli
    );

    if ($isCli) {
        echo "\n[DONE] $message\n";
        exit(0);
    }

    jsonRespond(true, $message, ['stats' => $stats], 200);

} catch (Throwable $e) {
    $stats['errors']++;
    $msg = "SEO Recommendations Error: " . $e->getMessage();
    error_log($msg);
    recordCronRun('seo_recommendations', 'error', 'Fatal error', null, $e->getMessage(), !$isCli);

    if ($isCli) {
        echo "\n[ERROR] $msg\n";
        echo $e->getTraceAsString() . "\n";
        exit(1);
    }

    jsonRespond(false, $msg, ['stats' => $stats], 500);
}
