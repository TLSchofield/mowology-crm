<?php
/**
 * One-time Migration Runner — Migration 503
 * Adds portal_token column to contacts table.
 * Run once via authenticated POST, then DELETE this file.
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

// Add portal_token to contacts (guard: skip if already exists)
$colCheck = $db->query("SHOW COLUMNS FROM contacts LIKE 'portal_token'")->fetch();
if (!$colCheck) {
    try {
        $db->exec("ALTER TABLE contacts
            ADD COLUMN portal_token VARCHAR(64) NULL DEFAULT NULL AFTER notes,
            ADD UNIQUE KEY unique_portal_token (portal_token)
        ");
        $results[] = ['step' => 'portal_token column', 'status' => 'added'];
    } catch (PDOException $e) {
        $results[] = ['step' => 'portal_token column', 'status' => 'error', 'msg' => $e->getMessage()];
    }
} else {
    $results[] = ['step' => 'portal_token column', 'status' => 'already_exists'];
}

echo json_encode(['migration' => 503, 'results' => $results]);
