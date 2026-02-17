<?php
/**
 * Migration 601: Create calendar_stop_crew junction table for multi-crew assignments
 * Run once via browser. Visit /crm/migrate_601_stop_crew.php
 */

require_once dirname(__DIR__) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/app_config/config.php';

requireLogin();
$user = getCurrentUser();

if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Migration requires admin access.');
}

$db = getDB();
$results = [];
$hasError = false;

// 1. Create calendar_stop_crew junction table
try {
    $check = $db->query("SHOW TABLES LIKE 'calendar_stop_crew'");
    if ($check->rowCount() > 0) {
        $results[] = 'calendar_stop_crew table already exists. Skipping create.';
    } else {
        $db->exec("
            CREATE TABLE calendar_stop_crew (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                stop_id INT NOT NULL,
                user_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_stop_user (stop_id, user_id),
                KEY idx_user_id (user_id),
                CONSTRAINT fk_csc_stop FOREIGN KEY (stop_id) REFERENCES calendar_stops(id) ON DELETE CASCADE,
                CONSTRAINT fk_csc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $results[] = 'Created calendar_stop_crew table.';
    }
} catch (Exception $e) {
    $results[] = 'ERROR (create table): ' . $e->getMessage();
    $hasError = true;
}

// 2. Backfill existing crew_id assignments into junction table
if (!$hasError) {
    try {
        $stmt = $db->exec("
            INSERT IGNORE INTO calendar_stop_crew (stop_id, user_id)
            SELECT id, crew_id FROM calendar_stops WHERE crew_id IS NOT NULL
        ");
        $results[] = "Backfilled existing crew assignments into junction table ({$stmt} rows).";
    } catch (Exception $e) {
        $results[] = 'ERROR (backfill): ' . $e->getMessage();
        $hasError = true;
    }
}

// Output
header('Content-Type: text/html; charset=utf-8');
echo '<h2>Migration 601: calendar_stop_crew</h2>';
echo '<ul>';
foreach ($results as $r) {
    $color = strpos($r, 'ERROR') !== false ? 'red' : 'green';
    echo "<li style='color:{$color}'>{$r}</li>";
}
echo '</ul>';
echo $hasError
    ? '<p style="color:red; font-weight:bold;">Migration had errors.</p>'
    : '<p style="color:green; font-weight:bold;">Migration complete.</p>';
