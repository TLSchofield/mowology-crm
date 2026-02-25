<?php
/**
 * One-time Migration Runner — Migration 207
 * Adds invoice_timing ENUM to job_plans.
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
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db = getDB();
$results = [];

$colStmt = $db->query("SHOW COLUMNS FROM job_plans LIKE 'invoice_timing'");
$colExists = $colStmt->fetchAll();
if (empty($colExists)) {
    try {
        $db->exec("ALTER TABLE job_plans
            ADD COLUMN invoice_timing ENUM('after_visit','end_of_month','upfront') NOT NULL DEFAULT 'after_visit'
            AFTER pricing_model
        ");
        $results[] = ['step' => 'invoice_timing column', 'status' => 'added'];
    } catch (PDOException $e) {
        $results[] = ['step' => 'invoice_timing column', 'status' => 'error', 'msg' => $e->getMessage()];
    }
} else {
    $results[] = ['step' => 'invoice_timing column', 'status' => 'already_exists'];
}

echo json_encode(['migration' => '207', 'results' => $results, 'done' => true], JSON_PRETTY_PRINT);
