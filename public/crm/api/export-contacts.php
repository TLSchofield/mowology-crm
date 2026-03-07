<?php
/**
 * CSV Export — Contacts
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
requirePermission('clients.view');
session_write_close();

$db = getDB();

$stmt = $db->query("
    SELECT c.id, c.first_name, c.last_name, c.email, c.phone, c.mobile,
           c.preferred_contact_method, c.lifecycle_stage, c.prospect_status,
           c.receive_sms, c.receive_marketing, c.notes, c.created_at,
           COUNT(DISTINCT p.id) AS property_count,
           COUNT(DISTINCT q.id) AS quote_count,
           COUNT(DISTINCT jp.id) AS plan_count
    FROM contacts c
    LEFT JOIN properties p ON p.site_contact_id = c.id
    LEFT JOIN quotes q ON q.property_id = p.id
    LEFT JOIN job_plans jp ON jp.property_id = p.id
    GROUP BY c.id
    ORDER BY c.last_name, c.first_name
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="contacts-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM

fputcsv($out, ['ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Mobile',
               'Preferred Contact', 'Lifecycle Stage', 'Status',
               'SMS Consent', 'Marketing Consent', 'Notes', 'Created',
               'Properties', 'Quotes', 'Plans']);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['id'], $r['first_name'], $r['last_name'], $r['email'], $r['phone'], $r['mobile'],
        $r['preferred_contact_method'], $r['lifecycle_stage'], $r['prospect_status'],
        $r['receive_sms'] ? 'Yes' : 'No', $r['receive_marketing'] ? 'Yes' : 'No',
        $r['notes'], $r['created_at'],
        $r['property_count'], $r['quote_count'], $r['plan_count'],
    ]);
}
fclose($out);
