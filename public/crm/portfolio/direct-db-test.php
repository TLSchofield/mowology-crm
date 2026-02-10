<?php
/**
 * Direct database test - bypasses PHP logic to test raw SQL
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

echo '<h1>Direct Database Query Test</h1>';

// Test 1: Basic count
echo '<h2>Test 1: Basic Count</h2>';
try {
    $result = $db->query("SELECT COUNT(*) as cnt FROM seo_recommendations");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    echo '<p><strong>Total rows in seo_recommendations:</strong> ' . $row['cnt'] . '</p>';
    if ($row['cnt'] == 0) {
        echo '<p style="color: red;">ERROR: Table is empty!</p>';
    }
} catch (Exception $e) {
    echo '<p style="color: red;">ERROR: ' . $e->getMessage() . '</p>';
}

// Test 2: Sample rows
echo '<h2>Test 2: Sample Rows (LIMIT 5)</h2>';
try {
    $result = $db->query("
        SELECT id, query_text, search_volume, priority_score, status
        FROM seo_recommendations
        LIMIT 5
    ");
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo '<p style="color: red;">No rows returned!</p>';
    } else {
        echo '<table border="1" cellpadding="5" style="border-collapse: collapse;">';
        echo '<tr><th>ID</th><th>Query</th><th>Volume</th><th>Score</th><th>Status</th></tr>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . $row['id'] . '</td>';
            echo '<td>' . htmlspecialchars(substr($row['query_text'], 0, 30)) . '</td>';
            echo '<td>' . $row['search_volume'] . '</td>';
            echo '<td>' . $row['priority_score'] . '</td>';
            echo '<td>' . $row['status'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
} catch (Exception $e) {
    echo '<p style="color: red;">ERROR: ' . $e->getMessage() . '</p>';
}

// Test 3: The exact query from recommendations-data.php (without filters)
echo '<h2>Test 3: Exact Query (No Filters, No Window Function)</h2>';
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
            sr.reason,
            sr.created_at,
            sr.updated_at,
            st.name as target_name,
            st.target_type,
            st.canonical_slug as target_slug,
            ss.label as season_label
        FROM seo_recommendations sr
        LEFT JOIN seo_targets st ON sr.target_id = st.id
        LEFT JOIN seo_seasons ss ON sr.season_id = ss.id
        ORDER BY sr.priority_score DESC
        LIMIT 25 OFFSET 0
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo '<p><strong>Rows returned:</strong> ' . count($rows) . '</p>';

    if (empty($rows)) {
        echo '<p style="color: red;">ERROR: Query returned no rows!</p>';
        echo '<p>This indicates the JOIN is failing or the query is malformed.</p>';
    } else {
        echo '<p style="color: green;"><strong>✓ SUCCESS!</strong> Query returned ' . count($rows) . ' rows</p>';

        echo '<h3>First 3 Results</h3>';
        echo '<table border="1" cellpadding="5" style="border-collapse: collapse; font-size: 12px;">';
        echo '<tr><th>ID</th><th>Query</th><th>Volume</th><th>CTR</th><th>Pos</th><th>Score</th><th>Status</th><th>Target</th></tr>';

        $count = 0;
        foreach ($rows as $row) {
            if ($count >= 3) break;
            echo '<tr>';
            echo '<td>' . $row['id'] . '</td>';
            echo '<td>' . htmlspecialchars(substr($row['query_text'], 0, 25)) . '</td>';
            echo '<td>' . $row['impressions'] . '</td>';
            echo '<td>' . number_format($row['ctr'] * 100, 1) . '%</td>';
            echo '<td>' . ($row['avg_position'] ? number_format($row['avg_position'], 1) : '-') . '</td>';
            echo '<td>' . $row['priority_score'] . '</td>';
            echo '<td>' . $row['status'] . '</td>';
            echo '<td>' . ($row['target_name'] ?? '-') . '</td>';
            echo '</tr>';
            $count++;
        }
        echo '</table>';
    }
} catch (Exception $e) {
    echo '<p style="color: red;">ERROR: ' . $e->getMessage() . '</p>';
}

// Test 4: Check table structure
echo '<h2>Test 4: Table Structure</h2>';
try {
    $result = $db->query("DESCRIBE seo_recommendations");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);

    echo '<p><strong>Columns in seo_recommendations:</strong></p>';
    echo '<ul>';
    foreach ($columns as $col) {
        echo '<li><code>' . $col['Field'] . '</code> (' . $col['Type'] . ')' . ($col['Null'] === 'NO' ? ' [NOT NULL]' : '') . '</li>';
    }
    echo '</ul>';
} catch (Exception $e) {
    echo '<p style="color: red;">ERROR: ' . $e->getMessage() . '</p>';
}

// Test 5: Count by status
echo '<h2>Test 5: Count by Status</h2>';
try {
    $result = $db->query("SELECT status, COUNT(*) as cnt FROM seo_recommendations GROUP BY status");
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);

    echo '<table border="1" cellpadding="5" style="border-collapse: collapse;">';
    echo '<tr><th>Status</th><th>Count</th></tr>';
    $total = 0;
    foreach ($rows as $row) {
        echo '<tr><td>' . $row['status'] . '</td><td>' . $row['cnt'] . '</td></tr>';
        $total += $row['cnt'];
    }
    echo '<tr style="font-weight: bold;"><td>TOTAL</td><td>' . $total . '</td></tr>';
    echo '</table>';
} catch (Exception $e) {
    echo '<p style="color: red;">ERROR: ' . $e->getMessage() . '</p>';
}

echo '<hr>';
echo '<p><a href="index.php?tab=recommendations">Back to Recommendations Tab</a></p>';
?>
