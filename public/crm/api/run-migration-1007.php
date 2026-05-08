<?php
/**
 * Run Migration 1007 — Add contract_id to invoices table
 * POST to this URL while logged in as admin to apply.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin only']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$db  = getDB();
$log = [];

// Check if already applied
try {
    $cols = $db->query("SHOW COLUMNS FROM invoices LIKE 'contract_id'")->fetchAll();
    if (!empty($cols)) {
        echo json_encode(['success' => true, 'message' => 'Already applied — contract_id column exists', 'log' => []]);
        exit;
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Column check failed: ' . $e->getMessage()]);
    exit;
}

try {
    $db->exec("
        ALTER TABLE invoices
            ADD COLUMN contract_id INT UNSIGNED NULL DEFAULT NULL AFTER plan_id,
            ADD INDEX idx_invoices_contract_id (contract_id)
    ");
    $log[] = 'Added contract_id column + index to invoices table';
    echo json_encode(['success' => true, 'message' => 'Migration 1007 applied', 'log' => $log]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'log' => $log]);
}
