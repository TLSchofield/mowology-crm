<?php
/**
 * One-time Migration Runner — Migration 900: Stripe Payments Integration
 * Adds:
 *   1. stripe_payment_intent_id column to invoices
 *   2. stripe_payments audit table
 *   3. Expands payment_method ENUM to include 'stripe'
 * Safe to run multiple times (idempotent checks).
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

// ── 1. Add stripe_payment_intent_id to invoices ──────────────────────────────
$colCheck = $db->query("SHOW COLUMNS FROM invoices LIKE 'stripe_payment_intent_id'")->fetch();
if (!$colCheck) {
    try {
        // Find a safe column to insert after (payment_reference if it exists, else use end of table)
        $afterCol = $db->query("SHOW COLUMNS FROM invoices LIKE 'payment_reference'")->fetch();
        $afterSql = $afterCol ? "AFTER payment_reference" : "";
        $db->exec("ALTER TABLE invoices ADD COLUMN stripe_payment_intent_id VARCHAR(100) NULL DEFAULT NULL {$afterSql}");
        $results[] = ['step' => 'invoices.stripe_payment_intent_id', 'status' => 'added'];
    } catch (PDOException $e) {
        $results[] = ['step' => 'invoices.stripe_payment_intent_id', 'status' => 'error', 'msg' => $e->getMessage()];
    }
} else {
    $results[] = ['step' => 'invoices.stripe_payment_intent_id', 'status' => 'already_exists'];
}

// ── 2. Expand payment_method ENUM ─────────────────────────────────────────────
try {
    $db->exec("ALTER TABLE invoices
        MODIFY COLUMN payment_method
            ENUM('cash','cheque','e_transfer','credit_card','stripe','other') NULL DEFAULT NULL
    ");
    $results[] = ['step' => 'invoices.payment_method ENUM expanded', 'status' => 'ok'];
} catch (PDOException $e) {
    $results[] = ['step' => 'invoices.payment_method ENUM', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── 3. Create stripe_payments audit table ─────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS stripe_payments (
        id                      INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id              INT NOT NULL,
        payment_intent_id       VARCHAR(100) NOT NULL COMMENT 'Stripe PaymentIntent ID (pi_...)',
        payment_method_type     VARCHAR(50)  NULL,
        amount_cents            INT NOT NULL,
        currency                VARCHAR(10)  NOT NULL DEFAULT 'cad',
        status                  ENUM('created','succeeded','failed','cancelled') NOT NULL DEFAULT 'created',
        stripe_customer_id      VARCHAR(100) NULL,
        stripe_charge_id        VARCHAR(100) NULL,
        stripe_receipt_url      VARCHAR(500) NULL,
        raw_event_type          VARCHAR(100) NULL,
        failure_code            VARCHAR(100) NULL,
        failure_message         TEXT NULL,
        webhook_received_at     TIMESTAMP NULL,
        created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_payment_intent (payment_intent_id),
        INDEX idx_invoice (invoice_id),
        INDEX idx_status (status),
        INDEX idx_created (created_at),
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $results[] = ['step' => 'stripe_payments table', 'status' => 'ok'];
} catch (PDOException $e) {
    $results[] = ['step' => 'stripe_payments table', 'status' => 'error', 'msg' => $e->getMessage()];
}

echo json_encode(['migration' => '900', 'results' => $results, 'done' => true], JSON_PRETTY_PRINT);
