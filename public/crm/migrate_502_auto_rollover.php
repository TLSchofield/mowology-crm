<?php
/**
 * Migration 502: Add auto-rollover columns
 * Run once via browser. Visit /crm/migrate_502_auto_rollover.php
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

// 1. service_packages: auto_rollover + max_rollover_days
try {
    $check = $db->query("SHOW COLUMNS FROM service_packages LIKE 'auto_rollover'");
    if ($check->rowCount() > 0) {
        $results[] = 'service_packages.auto_rollover already exists. Skipping.';
    } else {
        $db->exec("ALTER TABLE service_packages ADD COLUMN auto_rollover TINYINT(1) NOT NULL DEFAULT 1");
        $db->exec("ALTER TABLE service_packages ADD COLUMN max_rollover_days SMALLINT DEFAULT NULL");
        $db->exec("UPDATE service_packages SET auto_rollover = 0 WHERE slug IN ('snow-removal-per-visit', 'snow-removal-seasonal')");
        $results[] = 'Added auto_rollover + max_rollover_days to service_packages. Snow/salt set to OFF.';
    }
} catch (Exception $e) {
    $results[] = 'ERROR (service_packages): ' . $e->getMessage();
    $hasError = true;
}

// 2. products: auto_rollover + max_rollover_days
try {
    $check = $db->query("SHOW COLUMNS FROM products LIKE 'auto_rollover'");
    if ($check->rowCount() > 0) {
        $results[] = 'products.auto_rollover already exists. Skipping.';
    } else {
        $db->exec("ALTER TABLE products ADD COLUMN auto_rollover TINYINT(1) NOT NULL DEFAULT 1");
        $db->exec("ALTER TABLE products ADD COLUMN max_rollover_days SMALLINT DEFAULT NULL");
        $results[] = 'Added auto_rollover + max_rollover_days to products.';
    }
} catch (Exception $e) {
    $results[] = 'ERROR (products): ' . $e->getMessage();
    $hasError = true;
}

// 3. job_visits: rollover_count + original_scheduled_date
try {
    $check = $db->query("SHOW COLUMNS FROM job_visits LIKE 'rollover_count'");
    if ($check->rowCount() > 0) {
        $results[] = 'job_visits.rollover_count already exists. Skipping.';
    } else {
        $db->exec("ALTER TABLE job_visits ADD COLUMN rollover_count TINYINT NOT NULL DEFAULT 0");
        $db->exec("ALTER TABLE job_visits ADD COLUMN original_scheduled_date DATE DEFAULT NULL");
        $results[] = 'Added rollover_count + original_scheduled_date to job_visits.';
    }
} catch (Exception $e) {
    $results[] = 'ERROR (job_visits): ' . $e->getMessage();
    $hasError = true;
}

// 4. ops_settings: max_rollover_days default
try {
    $check = $db->prepare("SELECT COUNT(*) FROM ops_settings WHERE setting_key = 'max_rollover_days'");
    $check->execute();
    if ((int)$check->fetchColumn() > 0) {
        $results[] = 'ops_settings.max_rollover_days already exists. Skipping.';
    } else {
        $db->exec("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES ('max_rollover_days', '3', 'Max days an incomplete visit can roll forward before being auto-skipped')");
        $results[] = 'Added max_rollover_days = 3 to ops_settings.';
    }
} catch (Exception $e) {
    $results[] = 'ERROR (ops_settings): ' . $e->getMessage();
    $hasError = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migration 502 — Auto-Rollover</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 2rem; max-width: 700px; }
        .result { padding: 0.75rem 1rem; border-radius: 4px; margin: 0.5rem 0; }
        .result-ok { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .result-err { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        a { color: #0066cc; }
    </style>
</head>
<body>
    <h1>Migration 502: Auto-Rollover Columns</h1>
    <?php foreach ($results as $r): ?>
        <div class="result <?= strpos($r, 'ERROR') === 0 ? 'result-err' : 'result-ok' ?>">
            <?= htmlspecialchars($r) ?>
        </div>
    <?php endforeach; ?>
    <p style="margin-top: 1rem;"><a href="dashboard_appstack.php">&larr; Back to Dashboard</a></p>
</body>
</html>
