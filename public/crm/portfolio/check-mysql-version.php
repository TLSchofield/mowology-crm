<?php
/**
 * Check MySQL version and window function support
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

try {
    $versionStmt = $db->query("SELECT VERSION() as version");
    $version = $versionStmt->fetch(PDO::FETCH_ASSOC);
    echo '<h1>MySQL Version: ' . $version['version'] . '</h1>';

    // Test window function
    echo '<h2>Testing Window Function Support</h2>';
    $testStmt = $db->query("SELECT COUNT(*) OVER() as total FROM seo_recommendations LIMIT 1");
    if ($testStmt) {
        $result = $testStmt->fetch(PDO::FETCH_ASSOC);
        echo '<p style="color: green;"><strong>✓ Window functions are supported</strong></p>';
        echo '<p>Sample result: ' . print_r($result, true) . '</p>';
    }
} catch (Exception $e) {
    echo '<p style="color: red;"><strong>✗ Error:</strong> ' . $e->getMessage() . '</p>';
}
?>
