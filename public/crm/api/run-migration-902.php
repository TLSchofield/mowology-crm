<?php
/**
 * One-time Migration Runner — Migration 902
 * Adds stripe_customer_id, stripe_card_brand, stripe_card_last4, stripe_card_exp
 * columns to the contacts table.
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

// ── Add stripe_customer_id to contacts ───────────────────────────────────────
$colCheck = $db->query("SHOW COLUMNS FROM contacts LIKE 'stripe_customer_id'")->fetch();
if (!$colCheck) {
    try {
        $db->exec("ALTER TABLE contacts
            ADD COLUMN stripe_customer_id VARCHAR(100) NULL DEFAULT NULL AFTER notes,
            ADD COLUMN stripe_card_brand   VARCHAR(20)  NULL DEFAULT NULL AFTER stripe_customer_id,
            ADD COLUMN stripe_card_last4   VARCHAR(4)   NULL DEFAULT NULL AFTER stripe_card_brand,
            ADD COLUMN stripe_card_exp     VARCHAR(7)   NULL DEFAULT NULL AFTER stripe_card_last4
        ");
        $results[] = ['step' => 'contacts stripe columns', 'status' => 'added'];
    } catch (PDOException $e) {
        $results[] = ['step' => 'contacts stripe columns', 'status' => 'error', 'msg' => $e->getMessage()];
    }
} else {
    $results[] = ['step' => 'contacts stripe columns', 'status' => 'already_exists'];
}

// ── Add index on stripe_customer_id ──────────────────────────────────────────
$idxCheck = $db->query("SHOW INDEX FROM contacts WHERE Key_name = 'idx_contacts_stripe_customer'")->fetch();
if (!$idxCheck) {
    try {
        $db->exec("CREATE INDEX idx_contacts_stripe_customer ON contacts (stripe_customer_id)");
        $results[] = ['step' => 'idx_contacts_stripe_customer index', 'status' => 'created'];
    } catch (PDOException $e) {
        $results[] = ['step' => 'idx_contacts_stripe_customer index', 'status' => 'error', 'msg' => $e->getMessage()];
    }
} else {
    $results[] = ['step' => 'idx_contacts_stripe_customer index', 'status' => 'already_exists'];
}

echo json_encode(['migration' => '902', 'results' => $results, 'done' => true], JSON_PRETTY_PRINT);
