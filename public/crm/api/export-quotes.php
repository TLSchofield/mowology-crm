<?php
/**
 * CSV Export — Quotes
 */
declare(strict_types=1);

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
require_once PUBLIC_ROOT . '/crm/includes/functions.php';
requireLogin();
requirePermission('billing.view');
session_write_close();

$db = getDB();

$stmt = $db->query("
    SELECT q.quote_number, q.status, q.service_type, q.total_amount, q.probability,
           q.pipeline_stage, q.expected_close_date, q.deal_source,
           q.created_at, q.sent_at, q.accepted_at, q.valid_until,
           COALESCE(CONCAT(pc.first_name,' ',pc.last_name), c.company_name, 'N/A') AS client_name,
           p.address, p.city,
           u.full_name AS created_by
    FROM quotes q
    LEFT JOIN properties p ON q.property_id = p.id
    LEFT JOIN contacts pc ON p.site_contact_id = pc.id
    LEFT JOIN companies c ON q.company_id = c.id
    LEFT JOIN users u ON q.created_by = u.id
    ORDER BY q.created_at DESC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="quotes-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Quote #', 'Client', 'Service', 'Amount', 'Status', 'Pipeline Stage',
               'Probability %', 'Expected Close', 'Source', 'Address', 'City',
               'Created', 'Sent', 'Accepted', 'Valid Until', 'Created By']);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['quote_number'], $r['client_name'], $r['service_type'],
        number_format((float)$r['total_amount'], 2), $r['status'], $r['pipeline_stage'],
        $r['probability'], $r['expected_close_date'], $r['deal_source'],
        $r['address'], $r['city'],
        $r['created_at'], $r['sent_at'], $r['accepted_at'], $r['valid_until'], $r['created_by'],
    ]);
}
fclose($out);
