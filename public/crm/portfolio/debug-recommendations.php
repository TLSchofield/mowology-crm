<?php
/**
 * Detailed debugging for recommendations issue
 * Shows:
 * 1. What recommendations-data.php is returning
 * 2. Raw database counts
 * 3. First few rows of data
 * 4. Query execution status
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

echo '<style>
.debug-box { background: #f5f5f5; border: 1px solid #ccc; padding: 15px; margin: 15px 0; border-radius: 4px; }
.success { color: #2D8659; font-weight: bold; }
.error { color: #d32f2f; font-weight: bold; }
.info { color: #1976d2; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background: #f0f0f0; }
</style>';

echo '<h1>Recommendations Debugging</h1>';

// ======== TEST 1: Raw Database ========
echo '<div class="debug-box">';
echo '<h2>Test 1: Raw Database Status</h2>';

try {
    $countResult = $db->query("SELECT COUNT(*) as cnt FROM seo_recommendations")->fetch(PDO::FETCH_ASSOC);
    $total = $countResult['cnt'];
    echo '<p class="success">✓ Database has ' . $total . ' recommendations</p>';

    if ($total > 0) {
        $firstRow = $db->query("SELECT id, query_text, status, priority_score FROM seo_recommendations LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        echo '<p class="info">First row ID: ' . $firstRow['id'] . ' | Query: ' . htmlspecialchars(substr($firstRow['query_text'], 0, 40)) . '</p>';
    } else {
        echo '<p class="error">✗ No data in seo_recommendations table!</p>';
    }
} catch (Exception $e) {
    echo '<p class="error">✗ Error: ' . $e->getMessage() . '</p>';
}

echo '</div>';

// ======== TEST 2: Include recommendations-data.php ========
echo '<div class="debug-box">';
echo '<h2>Test 2: Recommendations Data Include</h2>';

try {
    // Set up the context like index.php does
    $_GET = ['status' => '', 'rec_type' => '', 'target_id' => '', 'season_id' => '', 'sort' => 'priority_score', 'dir' => 'DESC', 'page' => 1];

    // Include and capture
    $recommendationsData = include dirname(__DIR__) . '/portfolio/recommendations-data.php';

    echo '<p class="info">Include completed successfully</p>';

    if (is_array($recommendationsData)) {
        echo '<p class="success">✓ Return value is an array</p>';

        $recCount = count($recommendationsData['recommendations'] ?? []);
        echo '<p class="info">Recommendations count: ' . $recCount . '</p>';

        if ($recCount > 0) {
            echo '<p class="success">✓ ' . $recCount . ' recommendations returned!</p>';

            // Show first few
            echo '<h3>First 3 Records</h3>';
            echo '<table>';
            echo '<tr><th>ID</th><th>Query</th><th>Volume</th><th>Score</th><th>Status</th><th>Target</th></tr>';

            foreach (array_slice($recommendationsData['recommendations'], 0, 3) as $rec) {
                echo '<tr>';
                echo '<td>' . $rec['id'] . '</td>';
                echo '<td>' . htmlspecialchars(substr($rec['query_text'], 0, 30)) . '</td>';
                echo '<td>' . ($rec['impressions'] ?? $rec['search_volume']) . '</td>';
                echo '<td>' . $rec['priority_score'] . '</td>';
                echo '<td>' . $rec['status'] . '</td>';
                echo '<td>' . ($rec['target_name'] ?? '-') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<p class="error">✗ 0 recommendations returned</p>';
            echo '<p class="info">Pagination: ' . json_encode($recommendationsData['pagination']) . '</p>';
        }
    } else {
        echo '<p class="error">✗ Include did not return an array. Got: ' . gettype($recommendationsData) . '</p>';
    }
} catch (Exception $e) {
    echo '<p class="error">✗ Exception: ' . $e->getMessage() . '</p>';
    echo '<p class="error">File: ' . $e->getFile() . ' Line: ' . $e->getLine() . '</p>';
}

echo '</div>';

// ======== TEST 3: Direct SQL Test ========
echo '<div class="debug-box">';
echo '<h2>Test 3: Direct SQL Execution</h2>';

try {
    $sql = "SELECT sr.id, sr.query_text, sr.priority_score, st.name as target_name
            FROM seo_recommendations sr
            LEFT JOIN seo_targets st ON sr.target_id = st.id
            ORDER BY sr.priority_score DESC
            LIMIT 5";

    $stmt = $db->prepare($sql);
    $stmt->execute([]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($results) {
        echo '<p class="success">✓ Direct query returned ' . count($results) . ' rows</p>';

        echo '<table>';
        echo '<tr><th>ID</th><th>Query</th><th>Score</th><th>Target</th></tr>';
        foreach ($results as $row) {
            echo '<tr>';
            echo '<td>' . $row['id'] . '</td>';
            echo '<td>' . htmlspecialchars(substr($row['query_text'], 0, 30)) . '</td>';
            echo '<td>' . ($row['priority_score'] ?? '-') . '</td>';
            echo '<td>' . ($row['target_name'] ?? '-') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p class="error">✗ Direct query returned 0 rows</p>';
    }
} catch (Exception $e) {
    echo '<p class="error">✗ Error: ' . $e->getMessage() . '</p>';
}

echo '</div>';

// ======== TEST 4: Error Logs ========
echo '<div class="debug-box">';
echo '<h2>Test 4: PHP Error Log Check</h2>';
echo '<p class="info">Any errors from recommendations-data.php should appear below</p>';

$errorLogFile = ini_get('error_log');
if ($errorLogFile && file_exists($errorLogFile)) {
    $lines = array_slice(file($errorLogFile), -20);
    echo '<p class="info">Last 20 lines from: ' . $errorLogFile . '</p>';

    $filtered = array_filter($lines, function($line) {
        return strpos($line, 'Recommendation') !== false || strpos($line, 'Count') !== false;
    });

    if ($filtered) {
        echo '<pre style="background: #fff; border: 1px solid #999; padding: 10px; overflow: auto; max-height: 300px;">';
        echo implode($filtered);
        echo '</pre>';
    } else {
        echo '<p class="info">No errors found in recent log</p>';
    }
} else {
    echo '<p class="error">Cannot access error log</p>';
}

echo '</div>';

// ======== Summary ========
echo '<div class="debug-box">';
echo '<h2>Summary & Next Steps</h2>';

$recData = include dirname(__DIR__) . '/portfolio/recommendations-data.php';
$hasData = count($recData['recommendations'] ?? []) > 0;

if ($hasData) {
    echo '<p class="success">✓ Everything appears to be working!</p>';
    echo '<p>If you don\'t see recommendations in the main tab, try:</p>';
    echo '<ol>';
    echo '<li>Hard refresh (Ctrl+Shift+R or Cmd+Shift+R)</li>';
    echo '<li>Clear browser cache</li>';
    echo '<li>Check browser console for JavaScript errors</li>';
    echo '</ol>';
} else {
    echo '<p class="error">✗ Data is not being returned from recommendations-data.php</p>';
    echo '<p>Check the PHP error log for details</p>';
}

echo '</div>';

echo '<hr>';
echo '<p><a href="index.php?tab=recommendations">Back to Recommendations Tab</a></p>';
?>
