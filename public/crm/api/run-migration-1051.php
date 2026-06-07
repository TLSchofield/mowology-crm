<?php
/**
 * Migration 1051 — backfill invoices.contract_id from job_plans.contract_id.
 * Mirrors database/migrations/1051_invoices_backfill_contract_id.sql.
 *
 * Earlier invoice creation paths (visit-completion via pow-actions.php,
 * manual create.php) set plan_id but never set contract_id, which left
 * contract Billing Summaries showing $0 even when paid invoices existed
 * against the contract. This backfill walks plan_id → job_plans.contract_id.
 *
 * IDEMPOTENT — only fills rows where invoices.contract_id IS NULL.
 *
 * Run by visiting this URL as an admin. Add ?dry=1 to preview without writing.
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
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db  = getDB();
$dry = isset($_GET['dry']) && $_GET['dry'] === '1';

$db->beginTransaction();
try {
    // How many candidate rows would this migration touch?
    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM invoices i
        JOIN job_plans jp ON jp.id = i.plan_id
        WHERE i.contract_id IS NULL AND jp.contract_id IS NOT NULL
    ");
    $countStmt->execute();
    $candidates = (int)$countStmt->fetchColumn();

    $rowsAffected = $db->exec("
        UPDATE invoices i
        JOIN job_plans jp ON jp.id = i.plan_id
        SET i.contract_id = jp.contract_id
        WHERE i.contract_id IS NULL
          AND jp.contract_id IS NOT NULL
    ");

    if ($dry) {
        $db->rollBack();
        echo json_encode([
            'mode'           => 'dry-run',
            'candidates'     => $candidates,
            'rows_affected'  => $rowsAffected,
            'note'           => 'No changes committed. Remove ?dry=1 to apply.',
        ], JSON_PRETTY_PRINT);
    } else {
        $db->commit();
        echo json_encode([
            'mode'           => 'live',
            'candidates'     => $candidates,
            'rows_affected'  => $rowsAffected,
            'note'           => 'Migration 1051 applied successfully.',
        ], JSON_PRETTY_PRINT);
    }
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Migration failed: ' . $e->getMessage()]);
}
