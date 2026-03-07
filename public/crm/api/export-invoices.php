<?php
/**
 * CSV Export — Invoices
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
    SELECT i.invoice_number, i.status, i.total, i.amount_paid, i.balance_due,
           i.issue_date, i.due_date, i.paid_at, i.payment_method,
           i.created_at,
           COALESCE(CONCAT(pc.first_name,' ',pc.last_name), c.company_name, 'N/A') AS client_name,
           p.address, p.city
    FROM invoices i
    LEFT JOIN properties p ON i.property_id = p.id
    LEFT JOIN contacts pc ON p.site_contact_id = pc.id
    LEFT JOIN companies c ON i.company_id = c.id
    ORDER BY i.created_at DESC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="invoices-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Invoice #', 'Client', 'Status', 'Total', 'Paid', 'Balance Due',
               'Address', 'City', 'Issue Date', 'Due Date', 'Paid At',
               'Payment Method', 'Created']);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['invoice_number'], $r['client_name'], $r['status'],
        number_format((float)$r['total'], 2), number_format((float)$r['amount_paid'], 2),
        number_format((float)$r['balance_due'], 2),
        $r['address'], $r['city'],
        $r['issue_date'], $r['due_date'], $r['paid_at'], $r['payment_method'], $r['created_at'],
    ]);
}
fclose($out);
