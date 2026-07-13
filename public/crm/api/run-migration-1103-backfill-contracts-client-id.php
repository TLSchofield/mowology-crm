<?php
/**
 * Migration 1103 — Backfill contracts.client_id.
 *
 * Idempotent + admin-only. Only fills rows where client_id IS NULL, so it's
 * safe to re-run. Reports how many contracts were updated.
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

try {
    $before = (int) $db->query("SELECT COUNT(*) FROM contracts WHERE client_id IS NULL")->fetchColumn();

    $updated = $db->exec("
        UPDATE contracts c
        JOIN clients cl ON cl.legacy_contact_id = c.contact_id
        SET c.client_id = cl.id
        WHERE c.client_id IS NULL
    ");

    $after = (int) $db->query("SELECT COUNT(*) FROM contracts WHERE client_id IS NULL")->fetchColumn();

    echo json_encode([
        'success' => true,
        'migration' => '1103',
        'result' => [
            'contracts_missing_client_id_before' => $before,
            'contracts_updated'                  => $updated,
            'contracts_missing_client_id_after'   => $after,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
