<?php
/**
 * CSV Export for Reports
 * GET ?report=revenue-by-month&start=...&end=...
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
requirePermission('expenses.view');
session_write_close();

$report = $_GET['report'] ?? '';
$start  = $_GET['start'] ?? date('Y-01-01');
$end    = $_GET['end']   ?? date('Y-12-31');
$limit  = max(1, min(100, (int)($_GET['limit'] ?? 50)));

$db = getDB();

$filename = $report . '-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

try {
    switch ($report) {

        case 'revenue-by-month':
            fputcsv($out, ['Month', 'Invoice Count', 'Total Invoiced', 'Total Collected', 'Outstanding']);
            $stmt = $db->prepare("
                SELECT DATE_FORMAT(i.issue_date, '%b %Y') AS month_label,
                       COUNT(*) AS invoice_count,
                       COALESCE(SUM(i.total), 0) AS total_revenue,
                       COALESCE(SUM(i.amount_paid), 0) AS total_collected,
                       COALESCE(SUM(i.balance_due), 0) AS total_outstanding
                FROM invoices i
                WHERE i.status NOT IN ('draft','cancelled') AND i.issue_date BETWEEN ? AND ?
                GROUP BY DATE_FORMAT(i.issue_date, '%Y-%m'), month_label
                ORDER BY DATE_FORMAT(i.issue_date, '%Y-%m') ASC
            ");
            $stmt->execute([$start, $end]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($out, [$r['month_label'], $r['invoice_count'], number_format((float)$r['total_revenue'], 2), number_format((float)$r['total_collected'], 2), number_format((float)$r['total_outstanding'], 2)]);
            }
            break;

        case 'revenue-by-service':
            fputcsv($out, ['Service Type', 'Visits', 'Revenue', 'Margin', 'Margin %']);
            $stmt = $db->prepare("
                SELECT COALESCE(vms.service_type, 'other') AS service_type, COUNT(*) AS visit_count,
                       COALESCE(SUM(vms.quoted_amount), 0) AS revenue, COALESCE(SUM(vms.gross_margin), 0) AS margin,
                       AVG(vms.margin_pct) AS avg_margin_pct
                FROM visit_margin_snapshots vms WHERE vms.visit_date BETWEEN ? AND ?
                GROUP BY vms.service_type ORDER BY revenue DESC
            ");
            $stmt->execute([$start, $end]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($out, [ucwords(str_replace('_', ' ', $r['service_type'])), $r['visit_count'], number_format((float)$r['revenue'], 2), number_format((float)$r['margin'], 2), round((float)$r['avg_margin_pct'], 1) . '%']);
            }
            break;

        case 'quote-funnel':
            fputcsv($out, ['Status', 'Count', 'Total Value']);
            $stmt = $db->prepare("SELECT status, COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS total_value FROM quotes WHERE created_at BETWEEN ? AND ? GROUP BY status ORDER BY FIELD(status,'draft','sent','accepted','declined','expired')");
            $stmt->execute([$start, $end . ' 23:59:59']);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($out, [ucfirst($r['status']), $r['cnt'], number_format((float)$r['total_value'], 2)]);
            }
            break;

        case 'crew-profitability':
            fputcsv($out, ['Crew Member', 'Visits', 'Revenue', 'Labor Cost', 'Material Cost', 'Drive Cost', 'Gross Margin', 'Margin %']);
            $stmt = $db->prepare("
                SELECT COALESCE(u.full_name, CONCAT(u.first_name,' ',u.last_name), 'Unassigned') AS crew_name,
                       COUNT(*) AS visit_count, COALESCE(SUM(vms.quoted_amount), 0) AS revenue,
                       COALESCE(SUM(vms.labor_cost), 0) AS labor_cost, COALESCE(SUM(vms.material_cost), 0) AS material_cost,
                       COALESCE(SUM(vms.drive_cost), 0) AS drive_cost, COALESCE(SUM(vms.gross_margin), 0) AS gross_margin,
                       AVG(vms.margin_pct) AS avg_margin_pct
                FROM visit_margin_snapshots vms JOIN job_visits jv ON jv.id = vms.visit_id LEFT JOIN users u ON u.id = jv.assigned_crew_id
                WHERE vms.visit_date BETWEEN ? AND ? GROUP BY u.id, crew_name ORDER BY gross_margin DESC
            ");
            $stmt->execute([$start, $end]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($out, [$r['crew_name'], $r['visit_count'], number_format((float)$r['revenue'], 2), number_format((float)$r['labor_cost'], 2), number_format((float)$r['material_cost'], 2), number_format((float)$r['drive_cost'], 2), number_format((float)$r['gross_margin'], 2), round((float)$r['avg_margin_pct'], 1) . '%']);
            }
            break;

        case 'client-lifetime-value':
            fputcsv($out, ['Client', 'Email', 'Phone', 'Invoices', 'Total Invoiced', 'Total Paid', 'First Invoice', 'Last Invoice']);
            $stmt = $db->prepare("
                SELECT CONCAT(c.first_name,' ',c.last_name) AS client_name, c.email, c.phone,
                       COUNT(DISTINCT i.id) AS invoice_count, COALESCE(SUM(i.total), 0) AS total_invoiced,
                       COALESCE(SUM(i.amount_paid), 0) AS total_paid, MIN(i.issue_date) AS first_invoice, MAX(i.issue_date) AS last_invoice
                FROM contacts c JOIN invoices i ON i.contact_id = c.id AND i.status NOT IN ('draft','cancelled')
                GROUP BY c.id, client_name, c.email, c.phone ORDER BY total_paid DESC LIMIT ?
            ");
            $stmt->execute([$limit]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($out, [$r['client_name'], $r['email'], $r['phone'], $r['invoice_count'], number_format((float)$r['total_invoiced'], 2), number_format((float)$r['total_paid'], 2), $r['first_invoice'], $r['last_invoice']]);
            }
            break;

        case 'overdue-invoices':
            fputcsv($out, ['Invoice #', 'Client', 'Issue Date', 'Due Date', 'Total', 'Paid', 'Balance Due', 'Days Overdue', 'Email', 'Phone']);
            $stmt = $db->query("
                SELECT i.invoice_number, COALESCE(CONCAT(ct.first_name,' ',ct.last_name), c.company_name, 'N/A') AS client_name,
                       i.issue_date, i.due_date, i.total, i.amount_paid, i.balance_due,
                       DATEDIFF(CURDATE(), i.due_date) AS days_overdue, ct.email, ct.phone
                FROM invoices i LEFT JOIN contacts ct ON i.contact_id = ct.id LEFT JOIN companies c ON i.company_id = c.id
                WHERE i.balance_due > 0 AND i.due_date < CURDATE() AND i.status NOT IN ('draft','cancelled','paid')
                ORDER BY i.due_date ASC
            ");
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($out, [$r['invoice_number'], $r['client_name'], $r['issue_date'], $r['due_date'], number_format((float)$r['total'], 2), number_format((float)$r['amount_paid'], 2), number_format((float)$r['balance_due'], 2), $r['days_overdue'], $r['email'], $r['phone']]);
            }
            break;
    }
} catch (Throwable $e) {
    fputcsv($out, ['Error generating report']);
}

fclose($out);
