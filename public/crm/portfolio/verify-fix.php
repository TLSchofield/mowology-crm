<?php
/**
 * Quick verification that the fix is working
 * Shows exactly what recommendations-data.php returns
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

echo '<h1>Recommendations Data Verification</h1>';
echo '<p style="background: #e8f4f8; padding: 10px; border-left: 4px solid #2D8659;">';
echo 'Testing what the fixed recommendations-data.php returns...';
echo '</p>';

// Simulate what index.php does
$_GET['status'] = '';
$_GET['rec_type'] = '';
$_GET['target_id'] = '';
$_GET['season_id'] = '';
$_GET['sort'] = 'priority_score';
$_GET['dir'] = 'DESC';
$_GET['page'] = 1;

// Include the file and capture what it returns
$recommendationsData = include dirname(__DIR__) . '/portfolio/recommendations-data.php';

echo '<h2>Return Value Structure</h2>';
echo '<pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ddd; overflow-x: auto;">';
echo 'Array keys: ' . implode(', ', array_keys($recommendationsData)) . "\n\n";
echo '</pre>';

echo '<h2>Pagination Info</h2>';
echo '<table border="1" cellpadding="5" style="border-collapse: collapse;">';
echo '<tr><th>Key</th><th>Value</th></tr>';
foreach ($recommendationsData['pagination'] as $key => $val) {
    echo '<tr><td>' . $key . '</td><td>' . $val . '</td></tr>';
}
echo '</table>';

echo '<h2>Recommendations Count</h2>';
echo '<p style="font-size: 18px;"><strong>' . count($recommendationsData['recommendations']) . ' recommendations</strong> returned</p>';

if (count($recommendationsData['recommendations']) > 0) {
    echo '<p style="color: green;"><strong>✓ SUCCESS!</strong> Recommendations are being returned!</p>';

    echo '<h2>First 5 Recommendations</h2>';
    echo '<table border="1" cellpadding="5" style="border-collapse: collapse; font-size: 12px;">';
    echo '<tr><th>ID</th><th>Query</th><th>Volume</th><th>CTR</th><th>Position</th><th>Score</th><th>Status</th><th>Target</th></tr>';

    $count = 0;
    foreach ($recommendationsData['recommendations'] as $rec) {
        if ($count >= 5) break;
        echo '<tr>';
        echo '<td>' . $rec['id'] . '</td>';
        echo '<td>' . htmlspecialchars(substr($rec['query_text'], 0, 25)) . '</td>';
        echo '<td>' . ($rec['impressions'] ?? $rec['search_volume'] ?? '-') . '</td>';
        echo '<td>' . number_format($rec['ctr'] * 100, 1) . '%</td>';
        echo '<td>' . ($rec['avg_position'] ? number_format($rec['avg_position'], 1) : '-') . '</td>';
        echo '<td>' . $rec['priority_score'] . '</td>';
        echo '<td>' . $rec['status'] . '</td>';
        echo '<td>' . ($rec['target_name'] ?? '-') . '</td>';
        echo '</tr>';
        $count++;
    }
    echo '</table>';
} else {
    echo '<p style="color: red;"><strong>✗ PROBLEM:</strong> No recommendations returned!</p>';
    echo '<p>This means the query is still failing. The fix may not have been applied correctly.</p>';
}

echo '<h2>Filter Data</h2>';
echo '<p><strong>Targets:</strong> ' . count($recommendationsData['targets']) . '</p>';
echo '<p><strong>Seasons:</strong> ' . count($recommendationsData['seasons']) . '</p>';

echo '<hr>';
echo '<p><a href="index.php?tab=recommendations">Back to Recommendations Tab</a></p>';
?>
