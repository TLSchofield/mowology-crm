<?php
/**
 * Migration 987 — Add stripe_charge_id to invoices + payment_reference to accounting_transactions
 * DELETE THIS FILE after running on production.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    requirePermission('expenses.edit');

    $db  = getDB();
    $log = [];

    $steps = [
        // Add stripe_charge_id to invoices
        [
            'sql'  => "ALTER TABLE invoices ADD COLUMN stripe_charge_id VARCHAR(255) NULL AFTER stripe_payment_intent_id",
            'desc' => 'Add stripe_charge_id to invoices',
        ],
        // Add payment_reference to accounting_transactions
        [
            'sql'  => "ALTER TABLE accounting_transactions ADD COLUMN payment_reference VARCHAR(255) NULL AFTER matched_by",
            'desc' => 'Add payment_reference to accounting_transactions',
        ],
        // Index on stripe_charge_id
        [
            'sql'  => "CREATE INDEX idx_invoices_stripe_charge ON invoices(stripe_charge_id)",
            'desc' => 'Create index idx_invoices_stripe_charge',
        ],
        // Index on payment_reference
        [
            'sql'  => "CREATE INDEX idx_at_payment_reference ON accounting_transactions(payment_reference)",
            'desc' => 'Create index idx_at_payment_reference',
        ],
    ];

    foreach ($steps as $step) {
        try {
            $db->exec($step['sql']);
            $log[] = '✓ ' . $step['desc'];
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'Duplicate column') !== false ||
                strpos($msg, 'already exists')   !== false ||
                strpos($msg, 'Duplicate key')     !== false) {
                $log[] = '— ' . $step['desc'] . ' (already exists — skipped)';
            } else {
                $log[] = '✗ ' . $step['desc'] . ': ' . $msg;
            }
        }
    }

    // Backfill stripe_charge_id from stripe_payments table where available
    try {
        $updated = $db->exec("
            UPDATE invoices i
            JOIN stripe_payments sp ON sp.invoice_id = i.id
                AND sp.status = 'succeeded'
                AND sp.stripe_charge_id IS NOT NULL
            SET i.stripe_charge_id = sp.stripe_charge_id
            WHERE i.stripe_charge_id IS NULL
        ");
        $log[] = "✓ Backfilled stripe_charge_id on {$updated} invoice(s) from stripe_payments";
    } catch (PDOException $e) {
        $log[] = '✗ Backfill stripe_charge_id: ' . $e->getMessage();
    }

    echo json_encode(['ok' => true, 'log' => $log]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
