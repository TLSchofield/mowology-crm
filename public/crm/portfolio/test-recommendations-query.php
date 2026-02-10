<?php
/**
 * Debug script to test recommendations query directly
 * Access at: /crm/portfolio/test-recommendations-query.php
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

if (!$user || $user['role'] !== 'admin') {
    die('Admin access required');
}

$db = getDB();

echo '<h1>Recommendations Query Debug</h1>';

// Step 1: Check if tables exist
echo '<h2>Step 1: Table Structure</h2>';
try {
    $tables = [
        'seo_recommendations',
        'seo_targets',
        'seo_seasons'
    ];

    foreach ($tables as $table) {
        $result = $db->query("SELECT COUNT(*) as cnt FROM `$table`");
        if ($result) {
            $row = $result->fetch(PDO::FETCH_ASSOC);
            echo "<p><strong>$table:</strong> {$row['cnt']} rows</p>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

// Step 2: Test basic SELECT from recommendations
echo '<h2>Step 2: Basic Query Test</h2>';
try {
    $basicStmt = $db->query("SELECT id, query_text, search_volume, priority_score, status FROM seo_recommendations LIMIT 5");
    $rows = $basicStmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>Basic query returned " . count($rows) . " rows</p>";
    if ($rows) {
        echo '<table border="1" cellpadding="5">';
        echo '<tr><th>ID</th><th>Query</th><th>Volume</th><th>Score</th><th>Status</th></tr>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . $row['id'] . '</td>';
            echo '<td>' . htmlspecialchars($row['query_text']) . '</td>';
            echo '<td>' . $row['search_volume'] . '</td>';
            echo '<td>' . $row['priority_score'] . '</td>';
            echo '<td>' . $row['status'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

// Step 3: Test WITH JOINs (like the actual query)
echo '<h2>Step 3: Complex Query with JOINs</h2>';
try {
    $sql = "
        SELECT
            sr.id,
            sr.query_text,
            sr.search_volume as impressions,
            sr.clicks,
            sr.ctr,
            sr.avg_position,
            sr.suggested_slug,
            sr.rec_type,
            sr.priority_score,
            sr.status,
            sr.target_id,
            sr.season_id,
            st.name as target_name,
            ss.label as season_label,
            COUNT(*) OVER() as total_count
        FROM seo_recommendations sr
        LEFT JOIN seo_targets st ON sr.target_id = st.id
        LEFT JOIN seo_seasons ss ON sr.season_id = ss.id
        ORDER BY sr.priority_score DESC
        LIMIT 5
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([]);
    $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<p>Complex query returned " . count($recommendations) . " rows</p>";
    echo "<p><strong>First row keys:</strong> " . implode(', ', array_keys($recommendations[0] ?? [])) . "</p>";

    if ($recommendations) {
        echo '<table border="1" cellpadding="5" style="font-size: 12px;">';
        echo '<tr><th>ID</th><th>Query</th><th>Volume</th><th>Score</th><th>Target</th><th>Season</th><th>Total</th></tr>';
        foreach ($recommendations as $row) {
            echo '<tr>';
            echo '<td>' . $row['id'] . '</td>';
            echo '<td>' . htmlspecialchars(substr($row['query_text'], 0, 30)) . '</td>';
            echo '<td>' . $row['impressions'] . '</td>';
            echo '<td>' . $row['priority_score'] . '</td>';
            echo '<td>' . ($row['target_name'] ?? '-') . '</td>';
            echo '<td>' . ($row['season_label'] ?? '-') . '</td>';
            echo '<td>' . $row['total_count'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

// Step 4: Test with string interpolation (the actual approach)
echo '<h2>Step 4: String Interpolation Query (actual approach)</h2>';
try {
    $perPage = 25;
    $offset = 0;
    $sortBy = 'priority_score';
    $sortDir = 'DESC';
    $whereClause = '';
    $params = [];

    $sql = "
        SELECT
            sr.id,
            sr.query_text,
            sr.search_volume as impressions,
            sr.clicks,
            sr.ctr,
            sr.avg_position,
            sr.suggested_slug,
            sr.rec_type,
            sr.priority_score,
            sr.status,
            sr.target_id,
            sr.season_id,
            sr.reason,
            sr.created_at,
            sr.updated_at,
            st.name as target_name,
            st.target_type,
            st.canonical_slug as target_slug,
            ss.label as season_label,
            COUNT(*) OVER() as total_count
        FROM seo_recommendations sr
        LEFT JOIN seo_targets st ON sr.target_id = st.id
        LEFT JOIN seo_seasons ss ON sr.season_id = ss.id
        $whereClause
        ORDER BY sr.{$sortBy} {$sortDir}
        LIMIT {$perPage} OFFSET {$offset}
    ";

    echo '<pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ccc;">';
    echo htmlspecialchars($sql);
    echo '</pre>';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<p><strong>Query returned " . count($recommendations) . " rows</strong></p>";

    if ($recommendations) {
        echo '<p><strong>Total count from window function:</strong> ' . $recommendations[0]['total_count'] . '</p>';
    }
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
    echo "<p style='color: red;'><strong>Code:</strong> " . $e->getCode() . "</p>";
}

// Step 5: Check targets and seasons
echo '<h2>Step 5: Filter Data</h2>';
try {
    $targetsStmt = $db->query("SELECT id, name, target_type FROM seo_targets WHERE is_active = 1 ORDER BY target_type, name");
    $targets = $targetsStmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p><strong>Active targets:</strong> " . count($targets) . "</p>";

    $seasonsStmt = $db->query("SELECT id, season_key, label FROM seo_seasons WHERE is_active = 1 ORDER BY priority_boost DESC");
    $seasons = $seasonsStmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p><strong>Active seasons:</strong> " . count($seasons) . "</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

echo '<hr>';
echo '<p><a href="index.php?tab=recommendations">Back to Recommendations Tab</a></p>';
?>
