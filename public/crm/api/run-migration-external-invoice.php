<?php
/**
 * Run Migration: External Invoice Flag
 *
 * Adds billed_externally column to job_visits so completed visits that were
 * invoiced outside this CRM (e.g., in Jobber) can be marked without deleting them.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
session_write_close();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db      = getDB();
$results = [];

try {
    $db->exec("
        ALTER TABLE job_visits
        ADD COLUMN billed_externally TINYINT(1) NOT NULL DEFAULT 0
    ");
    $results[] = ['step' => 'add billed_externally column', 'status' => 'ok'];
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate column')) {
        $results[] = ['step' => 'add billed_externally column', 'status' => 'already exists'];
    } else {
        $results[] = ['step' => 'add billed_externally column', 'status' => 'error', 'message' => $e->getMessage()];
    }
}

echo json_encode(['success' => true, 'results' => $results]);
