<?php
/**
 * Migration 1109 — Backfill invoices.company_id for contract-billed invoices.
 *
 * contract_billing.php's INSERT never populated company_id, even though it
 * already resolves the value (property.billing_company_id, falling back to
 * the contact's linked company) — so every contract-billed invoice was
 * invisible in a company's "Invoices" tab. The cron itself is fixed
 * separately; this backfills the historical rows using the same resolution
 * logic.
 *
 * Idempotent + admin-only. Only fills rows where contract_id IS NOT NULL AND
 * company_id IS NULL, so it's safe to re-run.
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
    $before = (int) $db->query("
        SELECT COUNT(*) FROM invoices WHERE contract_id IS NOT NULL AND company_id IS NULL
    ")->fetchColumn();

    $updated = $db->exec("
        UPDATE invoices i
        JOIN contracts c   ON c.id = i.contract_id
        JOIN contacts con  ON con.id = c.contact_id
        LEFT JOIN properties p  ON p.id = c.property_id
        LEFT JOIN companies cb  ON cb.id = p.billing_company_id
        LEFT JOIN companies co  ON (co.primary_contact_id = con.id OR co.billing_contact_id = con.id)
        SET i.company_id = COALESCE(cb.id, co.id)
        WHERE i.contract_id IS NOT NULL
          AND i.company_id IS NULL
          AND COALESCE(cb.id, co.id) IS NOT NULL
    ");

    $after = (int) $db->query("
        SELECT COUNT(*) FROM invoices WHERE contract_id IS NOT NULL AND company_id IS NULL
    ")->fetchColumn();

    echo json_encode([
        'success' => true,
        'migration' => '1109',
        'result' => [
            'invoices_missing_company_id_before' => $before,
            'invoices_updated'                   => $updated,
            'invoices_missing_company_id_after'  => $after,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
