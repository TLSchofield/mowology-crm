<?php
/**
 * One-time Migration Runner — Migration 504
 * Fertilizer bundle columns: product_bundles, job_plans, plan_line_items, job_visits.
 * Run once via authenticated POST/GET, then DELETE this file.
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
session_write_close();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db = getDB();
$results = [];

$steps = [
    // product_bundles: application_count
    [
        'table' => 'product_bundles',
        'column' => 'application_count',
        'sql' => "ALTER TABLE product_bundles ADD COLUMN application_count INT DEFAULT NULL COMMENT 'Number of visits in this bundle (e.g., 3 for Spring/Summer/Fall)'",
    ],
    // product_bundles: seasonal_schedule
    [
        'table' => 'product_bundles',
        'column' => 'seasonal_schedule',
        'sql' => "ALTER TABLE product_bundles ADD COLUMN seasonal_schedule TEXT DEFAULT NULL COMMENT 'JSON: [{\"month\":4,\"label\":\"Spring Feed\"},...]'",
    ],
    // job_plans: is_prepaid_bundle
    [
        'table' => 'job_plans',
        'column' => 'is_prepaid_bundle',
        'sql' => "ALTER TABLE job_plans ADD COLUMN is_prepaid_bundle TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Plan was sold as a pre-paid bundle — no per-visit invoice'",
    ],
    // job_plans: source_bundle_id
    [
        'table' => 'job_plans',
        'column' => 'source_bundle_id',
        'sql' => "ALTER TABLE job_plans ADD COLUMN source_bundle_id INT DEFAULT NULL COMMENT 'FK to product_bundles if plan created from bundle presell'",
    ],
    // job_plans: bundle_applications_used
    [
        'table' => 'job_plans',
        'column' => 'bundle_applications_used',
        'sql' => "ALTER TABLE job_plans ADD COLUMN bundle_applications_used INT NOT NULL DEFAULT 0 COMMENT 'Counter incremented each time a prepaid visit completes'",
    ],
    // plan_line_items: product_id
    [
        'table' => 'plan_line_items',
        'column' => 'product_id',
        'sql' => "ALTER TABLE plan_line_items ADD COLUMN product_id INT DEFAULT NULL COMMENT 'FK to products — enables icon_base_path, application_rate lookups'",
    ],
    // job_visits: notification_sent_at
    [
        'table' => 'job_visits',
        'column' => 'notification_sent_at',
        'sql' => "ALTER TABLE job_visits ADD COLUMN notification_sent_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Set when fertilizer completion email+SMS fires; prevents duplicates'",
    ],
];

foreach ($steps as $step) {
    $key = "{$step['table']}.{$step['column']}";
    $exists = $db->query("SHOW COLUMNS FROM `{$step['table']}` LIKE '{$step['column']}'")->fetch();
    if ($exists) {
        $results[] = ['step' => $key, 'status' => 'already_exists'];
    } else {
        try {
            $db->exec($step['sql']);
            $results[] = ['step' => $key, 'status' => 'added'];
        } catch (PDOException $e) {
            $results[] = ['step' => $key, 'status' => 'error', 'msg' => $e->getMessage()];
        }
    }
}

echo json_encode(['migration' => 504, 'results' => $results]);
