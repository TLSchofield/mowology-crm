<?php
/**
 * Migration: Backfill balance_due
 *
 * Recalculates balance_due = total - amount_paid for any invoice where the
 * stored value doesn't match. Safe to run multiple times — only touches rows
 * that are actually wrong (delta > 0.5¢).
 *
 * Run once via browser: /crm/api/run-migration-balance-due-backfill.php
 */

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

header('Content-Type: application/json');

$db  = getDB();
$log = [];

try {
    // Count how many rows need fixing before touching anything
    $before = $db->query("
        SELECT COUNT(*) FROM invoices
        WHERE ABS(COALESCE(balance_due, 0) - (total - amount_paid)) > 0.005
    ")->fetchColumn();

    $log[] = "Invoices with incorrect balance_due: {$before}";

    // Recalculate
    $updated = $db->exec("
        UPDATE invoices
        SET balance_due = GREATEST(0.00, total - amount_paid)
        WHERE ABS(COALESCE(balance_due, 0) - (total - amount_paid)) > 0.005
    ");

    $log[] = "Updated {$updated} row(s)";

    // Show what's now eligible for reminders
    $eligible = $db->query("
        SELECT COUNT(*) FROM invoices
        WHERE status IN ('sent', 'viewed', 'overdue', 'partial')
          AND balance_due > 0.01
          AND (reminder_count IS NULL OR reminder_count < 3)
          AND (last_reminder_sent_at IS NULL OR last_reminder_sent_at < DATE_SUB(NOW(), INTERVAL 3 DAY))
          AND due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ")->fetchColumn();

    $log[] = "Invoices now eligible for reminders: {$eligible}";
    $log[] = "=== Done ===";

    echo json_encode(['success' => true, 'rows_updated' => (int)$updated, 'log' => $log]);

} catch (Throwable $e) {
    error_log('[balance-due-backfill] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'log' => $log]);
}
