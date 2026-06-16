<?php
/**
 * Migration 1064 — clear wrongly-stored bill_to_name on PM-managed invoices.
 *
 * The old schedule-invoice path stored the on-site contact's name in
 * invoices.bill_to_name, which overrides the (now corrected) render-time
 * composition of "{billing_entity_name} C/O {management firm}". For invoices on
 * PM-managed properties whose stored bill_to_name is NOT already a proper
 * "… C/O …" heading, null it so the PDF/view compose the correct payer.
 *
 * Admin-only. Idempotent. Dry-run by default — append &apply=1 to write.
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
$apply = !empty($_GET['apply']);

// Target: invoices on PM-managed strata (property has a management firm AND a
// billing entity name) whose stored bill_to_name is missing the "C/O" form.
$selectSql = "
    SELECT i.id, i.invoice_number, i.bill_to_name,
           p.billing_entity_name, mgr.company_name AS firm,
           CONCAT(p.billing_entity_name, ' C/O ', mgr.company_name) AS should_be
    FROM invoices i
    JOIN properties p   ON p.id = i.property_id
    JOIN companies mgr  ON mgr.id = p.property_manager_id
    WHERE p.property_manager_id IS NOT NULL
      AND NULLIF(p.billing_entity_name, '') IS NOT NULL
      AND (i.bill_to_name IS NOT NULL AND i.bill_to_name <> '' AND i.bill_to_name NOT LIKE '% C/O %')
    ORDER BY i.id
";
$rows = $db->query($selectSql)->fetchAll(PDO::FETCH_ASSOC);

$out = [
    'migration'  => 1064,
    'mode'       => $apply ? 'apply' : 'dry-run',
    'match_count'=> count($rows),
    'samples'    => array_slice($rows, 0, 25),
];

if ($apply && $rows) {
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE invoices SET bill_to_name = NULL WHERE id IN ($in)")->execute($ids);
    $out['updated'] = count($ids);
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
