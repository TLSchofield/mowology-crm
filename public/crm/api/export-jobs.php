<?php
/**
 * CSV Export — Job Plans
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
requirePermission('jobs.view');
session_write_close();

$db = getDB();

$stmt = $db->query("
    SELECT jp.plan_number, jp.title, jp.service_type, jp.status, jp.is_recurring,
           jp.recurrence_pattern, jp.price_per_visit, jp.plan_start_date, jp.plan_end_date,
           jp.created_at,
           COALESCE(CONCAT(pc.first_name,' ',pc.last_name), 'N/A') AS client_name,
           p.address, p.city,
           COUNT(jv.id) AS visit_count,
           SUM(CASE WHEN jv.status = 'completed' THEN 1 ELSE 0 END) AS completed_visits
    FROM job_plans jp
    LEFT JOIN properties p ON jp.property_id = p.id
    LEFT JOIN contacts pc ON p.site_contact_id = pc.id
    LEFT JOIN job_visits jv ON jv.plan_id = jp.id
    GROUP BY jp.id
    ORDER BY jp.created_at DESC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="jobs-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Plan #', 'Title', 'Client', 'Service', 'Status', 'Recurring',
               'Recurrence', 'Price/Visit', 'Address', 'City',
               'Start Date', 'End Date', 'Total Visits', 'Completed', 'Created']);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['plan_number'], $r['title'], $r['client_name'], $r['service_type'],
        $r['status'], $r['is_recurring'] ? 'Yes' : 'No', $r['recurrence_pattern'],
        number_format((float)$r['price_per_visit'], 2), $r['address'], $r['city'],
        $r['plan_start_date'], $r['plan_end_date'],
        $r['visit_count'], $r['completed_visits'], $r['created_at'],
    ]);
}
fclose($out);
