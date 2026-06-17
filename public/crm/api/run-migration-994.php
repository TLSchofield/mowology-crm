<?php
/**
 * Migration 994 — seed Interac e-Transfer payment instructions.
 * Mirrors database/migrations/994_invoice_etransfer_payment_instructions.sql.
 * Admin-only, idempotent (only fills the value when empty/NULL).
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db = getDB();

$instructions = 'Pay by Interac e-Transfer to info@mowology.ca. Auto-deposit is enabled — no security question or password needed. Please include your invoice number in the message so we can match your payment.';

try {
    $stmt = $db->prepare(
        "UPDATE business_settings
            SET invoice_payment_instructions = ?
          WHERE id = 1
            AND (invoice_payment_instructions IS NULL OR invoice_payment_instructions = '')"
    );
    $stmt->execute([$instructions]);
    $updated = $stmt->rowCount();

    $current = $db->query("SELECT invoice_payment_instructions FROM business_settings WHERE id = 1")->fetchColumn();

    echo json_encode([
        'ok'              => true,
        'migration'       => '994',
        'rows_updated'    => $updated,
        'note'            => $updated === 0 ? 'Already set (left unchanged) or no settings row' : 'Seeded e-Transfer instructions',
        'current_value'   => $current,
    ], JSON_PRETTY_PRINT);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'migration' => '994', 'error' => $e->getMessage()]);
}
